---
title: Laravel floor raised to 12
author: Omar
date: 2026-11-12
branch: main
cost: { minutes: 11, tokens: 111431 }
touches:
  - AGENTS.md
  - README.md
  - composer.json
  - composer.lock
  - docs/installation.md
  - docs/performance.md
  - resources/boost/skills/kinetix-permissions/SKILL.md
  - src/Commands/OptimizeCommand.php
  - src/KinetixServiceProvider.php
  - src/Support/KinetixLookupSlots.php
---

## Decided
- Kinetix requires `illuminate/support ^12.0|^13.0` (testbench ^10|^11) from v0.290.0. Composer >= 2.9 blocks every Laravel 11 release by security advisory, so `--prefer-lowest` already resolved Laravel 12.x; the ^11 constraint was nominal and untested.
- The `optimizes()` guard in KinetixServiceProvider is gone (always present on 12).
- inertia-laravel floor stays ^2.0.

## Tried and ruled out
- Keeping ^11 and documenting `audit.block-insecure: false` — it would ask hosts to disable a security default to install us.

## Watch out
- Older learnings mention Laravel 11.0 workarounds (`Limit::after`, `initialDatabaseReadAtValue()`, get/setMiddlewarePriority only). They remain correct, just no longer required; new code may use Laravel 12.0 APIs.
