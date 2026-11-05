---
title: "Security batch 2: descriptor audiences, pinned outbound HTTP, takeover guard, write-only fields"
author: Omar
date: 2026-11-05
branch: main
cost: { minutes: 33, tokens: 118626 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/activity.md
  - docs/billing.md
  - docs/comments.md
  - docs/confidential.md
  - docs/connected-accounts.md
  - docs/credentials.md
  - docs/entitlements.md
---

## Decided
- Every SignedDescriptor carries a required `aud` (`Support\DescriptorAudience`, @internal). Consumers list the audiences they accept; a missing aud means Expired, a foreign one means ForeignAudience (403 with the endpoint's own forbidden message). We chose an explicit claim over shape checks because payload shapes overlap (write/bulk/form-action descriptors satisfied the record-modal one).
- Non-descriptor Crypt tokens carry a `purpose` claim: RelationScope (`kinetix-export-relation`), DownloadToken (`kinetix-download`).
- Outbound HTTP (webhooks, flow HTTP nodes) goes through the @internal `Support\OutboundHttp`:
  - no redirects by default; the opt-in redirect loop re-validates each hop;
  - every A/AAAA record is checked via `HostResolver`;
  - the connection is pinned with CURLOPT_RESOLVE;
  - the caller's headers are stripped on cross-origin hops.
- The spatie driver gets `KinetixCallWebhookJob` by setting `webhook-server.webhook_job` around `WebhookCall::create()`, because `useJob()` after create() throws.
- Impersonation and member credentials share the @internal `Permissions\TakeoverGuard`:
  - the target's teams must all be the actor's;
  - protected permissions are checked in each team (via PermissionTeam);
  - an owner target needs an owner actor;
  - an owner actor counts as holding P only when `TeamOwner::enabled() && grants(P)`.
- Comments and Tags under teams require a sealed `reference` (`KinetixComments::reference`). With teams off, the legacy type+id path stays as it was (lead override: requiring a policy there broke simple apps for no gain).
- `encrypted()` settings are write-only (`Form::writeOnly`, `isWithheld` on the field). On a team page an inherited secret uses `inheritingWriteOnly` (blank keeps inheriting, not flagged withheld).
- `disabled()` is enforced in `Form::getState` (`savedWhenDisabled()` to opt out); an autosave TableRepeater drops disabled columns from its allowlist.
- `kinetix.module_middleware.{module}` + the provider's `moduleMiddleware()` helper replaced 38 duplicated middleware blocks (route snapshot diff = only the new throttles).
- A module with `teams=false` while teams are on registers platform-level abilities (`platformWhenAppWide`).

## Tried and ruled out
- A revoke fallback that finds the user by the provision's identifier: a genuine pending invitation has the same shape (user_id null), so inviting a victim's email and cancelling stripped their role and ran detach_member. Corrupted rows get a doctor warning instead.
- Creating an account from an unverified provider email under `link_by_email=verified`: it allows pre-account takeover.
- Owner counted as holding every protected permission: with the owner bypass off, ownership grants nothing.
- Enforcing disabled inside Repeater rows: submitted rows can't be matched safely to stored rows. It is documented as browser-only.

## Watch out
- Any new endpoint token must use `SignedDescriptor::seal(..., DescriptorAudience::X)` and accept only X in `rejection()`.
- `moduleMiddleware()` is the only way to build an authenticated Kinetix route group; gating `impersonation` there also gates the leave route.
- TakeoverGuard trusts `team_switcher.teams_relation` to return every team, owned ones included.
