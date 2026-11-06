---
title: "Batch 5 audit follow-ups: throttles, envelope v2, repeater row keys"
author: Omar
date: 2026-11-06
branch: main
cost: { minutes: 48, tokens: 575772 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/activity.md
  - docs/calendar.md
  - docs/channels.md
  - docs/confidential.md
  - docs/connected-accounts.md
  - docs/credentials.md
---

## Decided
- Throttles are named per-user limiters in `Support\KinetixThrottle`, registered unconditionally in boot (a cached route that names a missing limiter answers 429 to everyone). All password-confirmation endpoints share one budget. Every attempt counts, because Laravel 11.0 has no `Limit::after`. A 429 is `{message, code: throttled, retry_after}`.
- The mail-template test goes only to the acting user's own address by default (`test_recipients` 'self').
- Export start binds the token's team claim through the route's `current_team` parameter and `KinetixTeams::currentTeam()`. It wraps the call in `PermissionTeam::run` ONLY when a team resolved; otherwise a host that pins spatie teams itself gets 403.
- Webhook secrets: an `EncryptedWithPlaintextFallback` cast. It reads plaintext, and writes ciphertext only when the column can hold it, because a varchar(255) overflows with AES-CBC. Migration 000051 widens the column and `kinetix:webhooks:encrypt-secrets` encrypts the old rows. The capacity memo is flushed by KinetixStateReset.
- Confidential envelope v2: AAD = `kinetix-confidential|2|table|column|rowKey`, 16-byte tag, 12-byte IV, and the table name rather than the model class (a morph map added later would break every value). New rows are bound by an `eloquent.created: *` listener (one extra UPDATE).
  - Keyless reads and quietly created rows stay column-bound. Strict mode (`legacy_envelopes=false`) refuses them on keyed reads, except the exact envelope the same instance sealed before it had a key: a WeakMap in ConfidentialBinding lets `created` observers read it.
- Repeater row keys (`__kinetix_row` = index + HMAC) are minted only in `toData()`. Recompute must round-trip incoming keys and never mint new ones.
- Activity history is redacted on read in `ActivityData::fromModel`.

## Tried and ruled out
- Strict row binding on keyless reads (`pluck`/`value`): it breaks every host `pluck` on a confidential column.
- Re-keying repeater rows by position in recompute: a hook that reorders or drops rows then silently resets the disabled values.
- Using `wasRecentlyCreated` to excuse unbound envelopes: it survives `refresh()`, so a DB-copied value would be accepted.

## Watch out
- Class-specific `created` observers run before wildcard listeners.
- Hosts whose FormRequest uses `'items.*' => 'array:…'` must allow `__kinetix_row`.
- In SensitiveKeys, `auth` is a whole-segment match only, so `author` stays visible; `pass` is not matched at all (`passport`).
- Follow-ups not done: Channel::webhook_secret is plaintext, the members POST has no throttle, and old `throttle:N,M` routes answer an untranslated 429.
