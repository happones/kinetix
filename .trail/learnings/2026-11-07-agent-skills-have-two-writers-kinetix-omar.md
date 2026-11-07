---
title: "Agent skills have two writers: kinetix:upgrade and Boost"
author: Omar
date: 2026-11-07
branch: main
cost: { minutes: 13, tokens: 264453 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/installation.md
  - resources/boost/skills/kinetix-boost/SKILL.md
  - resources/boost/skills/kinetix-forms/SKILL.md
  - resources/boost/skills/kinetix-notifications/SKILL.md
  - resources/boost/skills/kinetix-permissions/SKILL.md
  - resources/boost/skills/kinetix-resources/SKILL.md
  - resources/boost/skills/kinetix-tables/SKILL.md
---

## Decided
- Shipped skills (resources/boost/skills/**/*.md) must be byte-stable under Boost's writer: `Str::finish(MarkdownFormatter::format(trim($src)), "\n")`. `scripts/format-skills.php` mirrors Boost 2.10 (with fence masking). SkillsPublishTest fails when a source isn't stable, so run `php scripts/format-skills.php` after editing a skill.
- `kinetix:upgrade` leaves skills to Boost only when an agent in boost.json writes to `kinetix.skills_path` (Boost default paths, or `boost.agents.<agent>.skills_path`) AND boost.json lists every Kinetix skill already in that directory. Override with `kinetix.skills_publish_on_upgrade`.

## Tried and ruled out
- Deferring whenever boost.json lists any `kinetix-*` skill: if Boost writes a different directory (junie → .junie/skills), or skips a skill, the host's skills go stale silently.

## Watch out
- `kinetix:upgrade` runs on every `composer install` (post-autoload-dump). Anything it writes must be byte-identical across machines, or clean checkouts go dirty. The compiled vue-i18n bundle isn't deterministic yet; the docs tell hosts to gitignore it.
- `laravel/boost` isn't a dev dependency, so the mirror is pinned by hand. Recheck it against a newer Boost's MarkdownFormatter/Fences when Boost bumps.
- `TextInputField` drops `id`/`value` from extraInputAttributes, because a v-bind object after `:id` would win.
