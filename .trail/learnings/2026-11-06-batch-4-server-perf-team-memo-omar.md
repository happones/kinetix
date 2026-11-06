---
title: "Batch 4 server perf: team memo, once props, route cache, lazy locales installer"
author: Omar
date: 2026-11-06
branch: main
cost: { minutes: 51, tokens: 183876 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/activity.md
  - docs/billing.md
  - docs/inbox.md
  - docs/installation.md
  - docs/integration-logs.md
  - docs/locale.md
---

## Decided
- The current team is memoized per request on the Request object, keyed by segment + user identity + relation, through `KinetixTeams::currentTeam()`. A 404 answer is never cached. `forgetCurrentTeam()` runs after Kinetix membership changes; `KinetixStateReset` flushes on queued jobs and Octane requests. Feature-flag scope and the team switcher's "current" follow the URL team and fall back to the stored team only when there is no team context.
- Once props (`kinetix.shared_props.once`, inertia-laravel 3 only; detected through `PropsResolver` + `Onceable`) are keyed by user, team, locale, the prop's config and a session version. The version is bumped by the `kinetix.shared-props` middleware after writes in the source modules. `NEVER_ONCE` covers security and self-changing props, and also billing and entitlements, because webhooks change them outside the session.
- Route blocks are skipped when `routesAreCached()`. Gates, channels, aliases, listeners and permission features stay outside the guard; RouteCacheBootTest diffs a cached boot against an uncached one.
- Memo resets go through `KinetixStateReset`, not `scoped()`: the managers are injected into other singletons. A job that runs inline is detected by driver (`SyncJob` or driver `sync`), not by the connection name.
- Index migrations are one per module (000045–000049), plus an optional 000050 for the host notifications table. A shared file would already be marked as run when a host adopts a module later.
- Lazy locales are opt-in (`--lazy-locales`). The default snippet uses one glob over the generator's `--multi-locales` .ts files. The raw `lang/*.json` glob is opt-in only: the multi-locale output doesn't convert `:name`, plurals or `@`.

## Tried and ruled out
- `import.meta.glob(..., { import: 'default' })` to stop locale chunks importing Rolldown's `__export` helper: it produces identical chunks. The cause is another entry (public.ts) importing the same locale files statically; wiring every entry lazily fixes it.
- Writing `withApp` next to an existing `setup()`: Inertia 3 returns from `setup` before reaching `withApp`, so i18n and Pinia are never installed. The installer builds i18n inside `setup` (chained onto the single `createApp`) or refuses.

## Watch out
- Lazy locales always block on the active locale plus the fallback. With 2 locales and app locale ≠ fallback it is worse than eager unless `preloadFallback: 'idle'` is used. It wins from about 3 locales. `'idle'` with SSR causes a hydration mismatch when the active locale misses a key.
- The Inertia 3 starter kit's app.ts has no `resolve:`, so the installer can't wrap it; hosts add `resolve: withKinetixLocales(...)` by hand.
- InertiaEntryFile must refuse when the options object has an `async`/get/set property or a spread, otherwise it misses an existing setup.
- Pinned query counts (SharedPropsQueryCountTest): default 0, teams 2, all modules 15 on the first visit and 14 on a repeat visit.
