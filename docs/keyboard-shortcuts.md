# Keyboard Shortcuts

Kinetix Shortcuts adds app-wide keyboard hotkeys — **conflict-safe by design**,
**reusable** in any component, and **customizable** per user. It's a frontend
module (no backend), composing with Spotlight (`Cmd/Ctrl+K`) and Settings
(persisted overrides).

---

## Why single keys & sequences (not `Ctrl+`-combos)

Mapping actions to `Ctrl+C` / `Ctrl+N` fights the browser/OS (copy, new window,
save, print…) — many can't even be prevented. So Kinetix follows the
Linear/GitHub/Gmail model:

- **Single keys** when you're *not* typing: `c` create, `e` edit, `d` delete, `/` search.
- **Sequences**: `g` then `i` (Gmail-style).
- **`mod+…` combos** (⌘ on macOS, Ctrl elsewhere) for the few that need a
  modifier — these still fire while typing.
- **`?`** opens the shortcut cheat-sheet.

Keystrokes are ignored while typing in an input/textarea/select/contenteditable,
and `preventDefault` only runs on a match — so normal typing is never disturbed.

---

## 1. The directive

Register the plugin once, then bind a key to any element — Kinetix or not:

```ts
// app entry
import { KinetixHotkeys } from '@/plugins/kinetixHotkeys'
createApp(App).use(KinetixHotkeys)
```

```vue
<!-- triggers the element's own click -->
<button v-kinetix-hotkey="'c'" @click="create">New</button>

<!-- run a custom handler; the arg labels it in the help overlay -->
<button v-kinetix-hotkey:Inbox="{ keys: 'g i', handler: goInbox }">Inbox</button>
```

This is the "fire an event for something to catch" piece you asked for: a string
value clicks the element (so its `@click` runs); an object value runs your
handler.

### Binding a Kinetix Action to a key

Declare the shortcut on the PHP action — when it's rendered in a
`KinetixPageHeader`, Kinetix registers the hotkey for you (it runs the action,
including its confirmation modal):

```php
use Happones\Kinetix\Actions\CreateAction;

CreateAction::make()->shortcut('c')->url(fn () => route('posts.create'));
```

For an arbitrary element, use the directive instead:

```vue
<button v-kinetix-hotkey="'e'" @click="edit">Edit</button>
```

---

## 2. The composable

For programmatic registration (with automatic cleanup on unmount):

```ts
import { useKinetixHotkeys } from '@/composables/useKinetixHotkeys'

const { register } = useKinetixHotkeys()
register({ keys: 'c', label: 'Create', handler: openCreate })
register({ keys: 'g i', label: 'Go to inbox', handler: goInbox })
```

Combo grammar: steps are space-separated (a *sequence*); within a step, `+`-join
modifiers and one key (`mod+s`, `shift+?`). Sequences use plain keys only.

---

## 3. Help overlay

Mount `<KinetixShortcuts>` once in your layout — press **`?`** to see every
labelled shortcut:

```vue
<KinetixShortcuts />
```

Only shortcuts registered with a `label` (directive arg, or `label` in the
composable) appear.

---

## 4. Per-user customization

Override any binding by id and persist the map with the
[Settings](/settings) module:

```ts
import { setHotkeyOverrides } from '@/composables/useKinetixHotkeys'

// e.g. load from a setting and apply
setHotkeyOverrides({ 'create': 'n', 'edit': 'mod+e' })
```

```php
// persist the user's choices
KinetixSettings::set('shortcuts.bindings', $overrides);
```

> **Prefer `@vueuse/core`?** The matcher is intentionally native (no extra
> dependency, mirroring Spotlight's `Cmd+K`). If your app already uses VueUse,
> `useMagicKeys` is a drop-in alternative to drive the same `register()` calls.
