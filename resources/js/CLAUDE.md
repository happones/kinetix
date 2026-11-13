# Frontend rules (`resources/js/`)

This code ships to host applications: `components/`, `composables/`, `stores/`, `plugins/`, `icons/` and `types/kinetix.ts` are published into the host's `resources/js`. Build components that feel native to Vue developers, integrate naturally with Inertia, and stay accessible and light.

Also binding: the REQUIRED "Accessibility Rules" and "Bundle & Loading Rules" and the module sections in `.agents/skills/kinetix-development/SKILL.md`.

## Stack and style

- Vue 3 `<script setup lang="ts">`, Composition API, typed props, emits and slots. `@inertiajs/vue3` ^3 on the client; the server side still supports inertia-laravel ^2 and ^3, so payload features must work with both.
- UI in the shadcn style on reka-ui, designed deliberately rather than assembled from defaults.
- Reuse the Kinetix primitives (KinetixButton, KinetixModal, KinetixBadge, KinetixSwitch, KinetixAlert, KinetixCopyable…) instead of re-implementing them. DRY is required: search `components/` before adding one.
- No state library unless a real need exists; computed over watchers; no duplicated state.
- Published comments never name other admin frameworks (see the root `CLAUDE.md`).

## Component architecture

- Composition over monoliths. A large component is split into composables, focused subcomponents and a component map instead of a `v-if` chain (KinetixTable is the reference).
- Components are generic: no business entities, no assumptions about the host app.
- With `v-memo`, keep `:key` out of ternaries; compute the key in a function and put the memo on the `v-for` element.

## Backend contract

- Types come from `types/kinetix.ts`, mirrored from the Data classes in `src/Data`. Never invent or assume a field; a missing field is a backend change first.
- Partial reloads use `only`; mutations stay full reloads. Avoid oversized props and unneeded requests.

## Multi-team (REQUIRED)

- Build links with `useKinetixTeams().teamUrl()` (team route key, never the numeric id).
- Never cache or persist anything (module caches, localStorage, stores) without the team and user in the key; clear team-bound state when the team changes.
- Keep the teams-off mode working.

## Accessibility (summary; the skill has the full REQUIRED list)

- Labelled controls, keyboard paths, focus management (focus moves only when it was lost), live-region announcements that are not doubled, 24px minimum targets, phone-width layouts, reduced motion via `useKinetixReducedMotion`.
- Body-teleported layers use the `--kinetix-z-*` variables, never raw `z-50` (a scan spec enforces it).
- Loading, empty and error states for every async surface.

## Security

- No `v-html` on untrusted content; Markdown goes through the Kinetix renderer. Validate URLs before binding them to `href`/`src`.
- Frontend permissions are presentation only; the server enforces access.
- No secrets or internal config in props.

## Performance and bundle

- Heavy dependencies load lazily; optional ones use the host-registered loader pattern. No import shape is optional at build time and resolved at runtime.
- Virtualize only past a measured threshold; Set membership for large lists. The bundle guard specs in `tests/js` must stay green.

## Strings

- Every string is a `kinetix.*` vue-i18n key present in every locale under `resources/lang/`, with `{named}` placeholders.

## Checks while working

- `npx vitest run tests/js/<spec>`, `npm run types:check`, `npx eslint <changed files>` (linting all of `resources/js` loops on testbench's vendor symlink; `npm run lint` removes it first), `npx prettier --check <files>`.
- Visual checks in the gallery (`npm run gallery:dev`, specimens via `?s=`) on a port outside the `cccwebenvdev` list. `npm run audit:mobile` for phone-width and target sizes.
