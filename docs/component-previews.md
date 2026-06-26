# Component previews

Visual previews of key Kinetix components. These images are **generated
automatically** from the real components (see [How previews are generated](#how-previews-are-generated)),
so they stay in sync with the code.

## Wizard

The standalone `<KinetixWizard>` in its `gradient` and `panels` variants — see
the full [Wizard guide](/wizard).

![Wizard — gradient variant](/screenshots/wizard-gradient.png)

![Wizard — panels variant](/screenshots/wizard-panels.png)

## Onboarding checklist

`<KinetixOnboardingChecklist>` — progress bar, auto/manual steps, dismiss. See
[Onboarding](/onboarding).

![Onboarding checklist](/screenshots/onboarding-checklist.png)

## Empty state

`<KinetixEmptyState>` — a reusable "no data yet" block.

![Empty state](/screenshots/empty-state.png)

## Table with summaries

A `Table` with a `Sum` summarizer rendered in the footer — see
[Tables → Summaries](/tables#summaries).

![Table with a summary footer](/screenshots/table-summaries.png)

---

## How previews are generated

Previews are captured with a small, repeatable pipeline — no manual screenshots:

1. **A Vite "gallery"** (`gallery/`) renders one component per request
   (`?s=<name>&theme=light|dark`) with realistic mock props. Inertia and the
   Kinetix HTTP composable are aliased to lightweight stubs so components mount
   without a backend; real `en` translations and the shadcn design tokens are
   loaded so the output matches production.
2. **A Playwright script** (`scripts/screenshots.mjs`) boots the gallery, visits
   each entry in light and dark themes, and saves a cropped 2× PNG to
   `docs/public/screenshots/`.

Regenerate them with:

```bash
npm run screenshots
```

To preview a single component interactively while authoring:

```bash
npm run gallery:dev   # then open http://localhost:5733/?s=wizard-gradient&theme=dark
```

Add a new preview by appending an entry to `gallery/specimens.ts`
(`{ name, title, component, props, slots?, width? }`) and re-running the command.
