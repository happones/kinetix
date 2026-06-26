---
name: kinetix-keyboard-shortcuts
description: "App-wide keyboard hotkeys — conflict-safe single keys & sequences, a v-kinetix-hotkey directive, a useKinetixHotkeys composable, a `?` help overlay, and per-user overrides. Activates when adding shortcuts, binding actions to keys, or building the help UI."
license: MIT
metadata:
  author: happones
---

# Kinetix Keyboard Shortcuts Development

## When to Apply

Activate this skill when:
- Adding keyboard shortcuts (`v-kinetix-hotkey` directive or `useKinetixHotkeys`).
- Binding a Kinetix Action (or any element) to a key.
- Building the `?` help overlay or wiring per-user binding overrides.

## Documentation

For full details, reference `docs/keyboard-shortcuts.md` (published at https://happones.github.io/kinetix/keyboard-shortcuts).

Frontend-only module — no backend, no config.

---

## Conflict-safe scheme (important)

Do **not** map actions to browser/OS-reserved `Ctrl+`-combos (copy/new/save/print).
Use: single keys when not typing (`c`/`e`/`d`/`/`), Gmail-style sequences (`g i`),
`mod+…` for the rare modifier case (⌘/Ctrl, fires while typing), and `?` for help.
Keystrokes are ignored while typing in inputs; `preventDefault` only on a match.

---

## Usage

Register the plugin once: `app.use(KinetixHotkeys)` (from `@/plugins/kinetixHotkeys`).

```vue
<button v-kinetix-hotkey="'c'" @click="create">New</button>            <!-- clicks the element -->
<button v-kinetix-hotkey:Inbox="{ keys: 'g i', handler: goInbox }">Inbox</button>
```

```ts
import { useKinetixHotkeys } from '@/composables/useKinetixHotkeys'
const { register } = useKinetixHotkeys()
register({ keys: 'c', label: 'Create', handler: openCreate }) // auto-cleanup on unmount
```

- **Grammar**: space-separated steps = a sequence; within a step `+`-join
  modifiers + one key (`mod+s`, `shift+?`). Sequences are plain keys only.
- **Help overlay**: mount `<KinetixShortcuts>` once; `?` lists labelled shortcuts.
- **Bind an Action**: put the directive on the action button (`v-kinetix-hotkey="'e'"`).
- **Per-user overrides**: `setHotkeyOverrides({ create: 'n' })`, persisted via the
  Settings module (`KinetixSettings::set('shortcuts.bindings', ...)`).
- Native matcher (no dep); `@vueuse/core` `useMagicKeys` is a documented drop-in
  alternative.
