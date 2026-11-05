# Performance

Kinetix has many modules, and an app usually uses a handful of them. The
published components are built so the browser only downloads, parses and runs
the code for what a page actually shows: optional bodies load the first time
they're needed, background polling stops when nobody is looking, and Tailwind
doesn't compile CSS for the agent skills sitting in your repository.

Nothing on this page needs configuring. It describes what loads when, so you
can tell an expected request from a regression, and the few host-side
settings that help.

---

## What loads lazily

Vite splits every dynamic `import()` into its own chunk. Kinetix uses that for
anything heavy that a page may never show.

### Layout hosts

The components you mount once in your layout ([Getting Started →
Mount the global components](/installation#_5-mount-the-global-components))
are small shells. Each one loads its body only when the feature is on and has
something to show:

| Host | Body loads |
|---|---|
| `<KinetixImportModal>` | When an import opens. The modal opens at once with a loading skeleton while the importer arrives. |
| `<KinetixFilePreview>` | On the first preview. Hovering or focusing a `[data-kinetix-lightbox]` element starts the download early. |
| `<KinetixSpotlight>` | On the first <kbd>Cmd/Ctrl</kbd>+<kbd>K</kbd> (or `kinetix:spotlight` event). Never while `kinetix.spotlight.enabled` is off, and then <kbd>Cmd/Ctrl</kbd>+<kbd>K</kbd> is left to the browser. |
| `<KinetixShortcuts>` | On the first <kbd>?</kbd>. |
| `<KinetixTours>` | When tours are enabled and at least one is shared with the page. Calling the store's `start()` before then still runs the tour once it loads. |
| `<KinetixAnnouncementBanner>` | When the shared feed has an entry, when the feed isn't shared, or when its props narrow the feed (so it has to fetch). |
| `<KinetixConfidentialUnlock>` | When confidential fields are enabled. An icon-sized placeholder holds its place meanwhile. |
| `<KinetixCookieConsent>` | When the banner is enabled and the visitor hasn't chosen yet, or when `visible` from `useKinetixCookieConsent()` is set to show it again. |

The keyboard shortcut, the `?` overlay and the preview click are handled by the
shell straight away. The shell records the request, waits for the body, then
opens it, so the first <kbd>Cmd/Ctrl</kbd>+<kbd>K</kbd> never reaches the
browser's own shortcut.

### Notifications and presence

`@laravel/echo-vue` (and the `pusher-js` client it pulls in) is a required peer,
but it is imported only when the bell listens in real time **and** Echo is
configured. The bell listens when the `realtime` prop says so; without the
prop it follows the server's `kinetix_config.realtime`, and on an older server
whether a `broadcasting` block is shared. The server turns `realtime` on when
any of these is true:

- `kinetix.notifications.broadcast` is on;
- the `kinetix.broadcasting.echo` block is filled;
- `broadcasting.default` is a real driver (anything but `null` or `log`).

Off, the bell only polls the database, and a host without broadcasting never
downloads Echo. On, but with `configureEcho()` never called, the bell still
mounts and still polls. See [Notifications → Real-time
broadcasting](/notifications#real-time-broadcasting-echo).

`useKinetixPresence()` (and `<KinetixOnlineUsers>`) loads Echo the same way,
the first time a presence channel is joined. The Inbox and Channels modules
still import Echo directly, which only affects their own pages.

### Tables and forms

- **Form fields.** A form ships six field types up front: `TextInput`,
  `Select`, `Checkbox`, `Toggle`, `Textarea` and `Hidden`. Every other type
  (date and time pickers, rich editors, media, repeaters, the wizard, …) is a
  separate chunk. When a form mounts it walks its schema once and starts every
  chunk it needs in parallel, so there's no waterfall. A field that is still
  loading shows a pulsing placeholder the height of an input. A field marked
  `autofocus` takes focus when it arrives, unless the user has already moved
  focus elsewhere. Rendering a field component directly yourself is unaffected.
- **Table filters.** Every filter input downloads when the Filters button is
  hovered, focused or opened, so a table nobody filters never loads them.
- **Editable cells.** The input cells (select, toggle, text, number and
  checkbox inputs) download when the table is set up, and only for the column
  types that table has. The same applies to client-side tables.
- **Table modals.** The form and infolist used by the create, edit, view and
  action modals of a table or relation manager load when a modal opens. The
  form chunk and its field chunks download together, and the modal's submit
  button stays busy until the form has mounted.
- **Long lists.** Kanban columns and comment threads window their rows past
  40 rows (the default threshold), and the media library past 12 rows of tiles.
  `@tanstack/vue-virtual` downloads at that moment, not before; until it has
  loaded every row renders, and shorter lists never load it.

### Language switcher

The `select` variant of `<KinetixLanguageSwitcher>` downloads its field where
it's used; the default dropdown ships with the component.

### Server-side rendering

Lazy components are defined at module level and the server awaits them, so an
SSR response contains the real markup, not the placeholder. Bodies that wait
for a trigger (spotlight, shortcuts, an import, a preview) render nothing on
the server, as before.

---

## Stale chunks after a deploy

Lazy chunks are fetched later than the page that needs them. A tab opened
before a deploy asks for chunk names the new build no longer has, and the
download fails. Vite reports that with a `vite:preloadError` event on `window`.
Kinetix's lazy components have no error state. A failed download is retried
twice with a short backoff; if it still fails, the component keeps showing its
skeleton, and the next use downloads again — a skeleton already on screen
switches to the real component as soon as that succeeds. A template `ref` on a
lazy component reaches what the real component exposes once it has loaded;
before that, its members are `undefined`. Kinetix doesn't
reload for you, because
only your app knows whether a reload is safe. The usual handler goes in `resources/js/app.ts`:

```ts
window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();
    window.location.reload();
});
```

Inertia's version check (`HandleInertiaRequests::version()`) already forces a
full reload on the next visit after a deploy. This handler covers the lazy
loads that happen without a visit.

---

## Polling

Several widgets refresh on a timer: the notification bell (database mode), the
queue and health widgets, and the Reports Center runs and schedules lists. They
all share one loop with these rules:

- **Never overlapping.** The next run is scheduled when the previous one ends,
  so a slow endpoint is never hit by a second request in flight.
- **Paused while the tab is hidden.** Coming back to the tab refreshes at once,
  then the interval starts over.
- **Optionally bounded.** `useKinetixPolling` takes a `timeout` (ms): a run that
  hasn't settled by then counts as finished, so a hung request can't stall the
  loop. The default `0` waits for the run. The bell doesn't reload at mount: the page
  already carries the current list.
- **Stopped on unmount.**

The Reports Center runs list also slows down when it has nothing to watch. The
runs endpoint returns `hasActive` (whether any listed run is pending or
running). While it is false the list polls every 60 seconds instead of every
`kinetix.reports_center.poll`. Launching or retrying a run fires
`kinetix:report-runs-changed` (exported as `KINETIX_REPORT_RUNS_CHANGED` from
`useKinetixReportRuns`), which goes back to full speed at once; your own code
can dispatch it too.

Each widget keeps its own interval setting, and `0` still turns polling off.

---

## Timezone picker

The zone list (every IANA zone with its current offset) is computed on the
first open and cached for an hour. It's shared by every picker on the page and is
only built when a picker opens. A closed picker formats only its own selected
zone.

---

## Tailwind and the agent skills

Tailwind v4 scans every file in your project that git doesn't ignore. That
includes the Markdown agent skills Kinetix publishes to `.claude/skills` (or
your `kinetix.skills_path`), and every class name quoted in their examples
becomes CSS your app ships and never uses.

`kinetix:install` and `kinetix:upgrade` therefore add an `@source not` line to
`resources/css/app.css` for each skills directory that holds Kinetix skills
(`kinetix.skills_path`, `.claude/skills` and `.agents/skills`), right after the
stylesheet's `@import` / `@source` lines:

```css
@import 'tailwindcss';

/* Kinetix: agent skills are documentation, not markup — keep them out of Tailwind's scan */
@source not '../../.claude/skills';
```

- It needs **Tailwind 4.1 or newer** (the release that added `@source not`), read
  from `node_modules/tailwindcss/package.json`. On an older version, or before
  `npm install`, the installer prints the lines to add later and the upgrade
  skips quietly.
- The lines only go at the top level, after a complete statement: comments,
  multi-line `@import`s and blocks such as `@theme { … }` are respected, and the
  file's line endings are kept. If there is no safe place (or an `@import` /
  `@source` already sits inside a block), nothing is written: the installer
  prints the lines and `kinetix:doctor` warns.
- It's idempotent. A directory that appears later (another agent's
  `.agents/skills`) is added by the next `kinetix:upgrade`. An exclusion you
  already wrote for a parent directory counts.
- `kinetix:doctor` warns when a skills directory isn't excluded.
- Turn it off with `KINETIX_TAILWIND_EXCLUDE_AGENT_SKILLS=false`
  (`kinetix.tailwind.exclude_agent_skills`).

Kinetix only edits the lines for its own skills. If your repository keeps other
Markdown (a `docs/` folder, other agents' skills), exclude it yourself the same
way:

```css
@source not '../../docs';
```

---

## Checking your bundle

To confirm a page loads only what it should:

1. **Network panel.** Open DevTools → Network, filter by JS, and reload a page
   that doesn't use the feature. Chunks are named after the component
   (`KinetixLightbox-[hash].js`, `KinetixImporter-[hash].js`, …). They should
   appear only when you open a preview, an import, and so on.
2. **Build manifest.** After `npm run build`, `public/build/manifest.json` lists
   each chunk's static `imports` and its `dynamicImports`. Packages that load
   lazily (`@laravel/echo-vue`, `@tanstack/vue-virtual`) should only appear under
   `dynamicImports` of the chunks that use them, never in your entry's static
   closure.
3. **A treemap.** For sizes, add a bundle visualizer (for example
   `rollup-plugin-visualizer`) to `vite.config.ts` for one build and open the
   report.

If a heavy package is in your entry, look for a static import of it in your
own code first. Importing a component like `KinetixLightbox` or
`KinetixImporter` directly from a layout puts it back in every page.

Kinetix's own test suite guards the import graph:
`tests/js/bundleGuard.forms.spec.ts` and `tests/js/bundleGuard.layout.spec.ts`
fail if a layout host, `KinetixTable` or `KinetixForm` statically reaches one of
the lazy bodies or packages above.

---

## Merging the initial chunks (optional)

::: info Host-owned and optional
Kinetix never writes this for you. It's a build setting in **your**
`vite.config.ts`; skip it unless a bundle measurement says it helps.
:::

Kinetix's lazy loading keeps heavy code out of the entry, but the entry itself
is still made of many small chunks that the browser requests up front. On
Vite 8 (Rolldown) you can merge the ones only your authenticated entry needs
into one chunk, without changing what other entries (a `public.ts`, `app.css`)
load.

Measured on a Laravel starter-kit app (Vite 8.3.4, Rolldown 1.2.14), in KB
gzip:

| Bundle | Before | After |
|---|---|---|
| `app.ts` entry | 340 | 319, in 10 chunks |
| `app.ts` entry, no Echo | 315 | 293 |
| `public.ts` entry | 188 | 188 (unchanged) |

A headless smoke run of the app behaved identically before and after.

```ts
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

// Merge the many tiny chunks the authenticated entry statically needs into one
// chunk, without touching what other entries (public.ts, app.css) load.
const appEntry = resolve('resources/js/app.ts');
const otherEntries = ['resources/js/public.ts', 'resources/css/app.css']
    .map((path) => resolve(path))
    .filter((path) => existsSync(path));
let appOnly: Set<string> | null = null;

function staticallyReachable(roots: string[], ctx: { getModuleInfo(id: string): { importedIds: readonly string[] } | null }): Set<string> {
    const seen = new Set<string>();
    const stack = [...roots];
    while (stack.length) {
        const id = stack.pop()!;
        if (seen.has(id)) continue;
        seen.add(id);
        stack.push(...(ctx.getModuleInfo(id)?.importedIds ?? []));
    }
    return seen;
}

export const appInitialChunk = {
    groups: [{
        name: (id: string, ctx: any) => {
            if (appOnly === null) {
                const shared = staticallyReachable(otherEntries, ctx);
                appOnly = new Set([...staticallyReachable([appEntry], ctx)].filter((m) => !shared.has(m)));
            }
            return appOnly.has(id) ? 'app-initial' : null;
        },
        tags: ['$initial' as const],
        includeDependenciesRecursively: false,
    }],
};
// build: { rolldownOptions: { output: { codeSplitting: appInitialChunk } } }
```

Wire it in under `build.rolldownOptions.output`, or under
`build.rollupOptions.output` if your config already has that block.

Caveats:

- **Vite 8 / Rolldown only.** Rollup's `manualChunks` works differently; this
  snippet doesn't translate to it.
- **Adjust the entry paths** to your app (`app.js`, no `public.ts`, …). Paths
  that don't exist are filtered out.
- **Production builds only.** The SSR build never sees `app.ts`, so the group
  captures nothing there.
- **Keep `includeDependenciesRecursively: false`.** With the default, the
  public bundle in the measurement above grew from 188 to 286 KB gzip.
- **Measure your own build** before and after (see
  [Checking your bundle](#checking-your-bundle)); keep it only if it helps.
