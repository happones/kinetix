---
title: "CRUD notification hooks: toast vs database delivery per resource"
author: Omar
date: 2026-11-06
branch: main
cost: { minutes: 29, tokens: 449108 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - docs/notification-preferences.md
  - docs/notifications.md
  - docs/relation-managers.md
  - docs/resources.md
  - resources/boost/skills/kinetix-notification-preferences/SKILL.md
  - resources/boost/skills/kinetix-notifications/SKILL.md
  - resources/boost/skills/kinetix-resources/SKILL.md
---

## Decided
- After create, update, delete, restore and force delete, the message goes through `Resource::sendCrudNotification(CrudEvent, $record)` (final). `RelationManager` has the same as instance methods, falling back to `$relatedResource`. Hosts override `getCrudNotification()` (null = send nothing) and `getCrudNotificationDelivery()`: Toast (default), Database, or ToastAndDatabase.
- Default output is byte-identical to before: the `kinetix.record_*` toast through KinetixFlash. The legacy `->with('message')` flash is set only when the delivery toasts.
- ToastAndDatabase stores the row already read and never broadcasts, so the bell (which toasts every new unread row) doesn't toast twice. `ReadDatabaseChannel` sets `read_at`, because `initialDatabaseReadAtValue()` is missing on Laravel 11.0.
- `CrudNotifier` stamps the team (`KinetixTeams::keyFor('notifications')`) and the `kinetix.records` type. Kinetix's own persist step runs inside `rescue(report: true)` after the commit, so a missing notifications table never turns a committed write into a 500 that leads to a duplicate on retry. Host hooks still throw.

## Tried and ruled out
- Separate title/body hooks per event: too many methods, and ambiguous when a relation manager overrides one hook and its resource another.
- A delivery flag on the Notification builder: `send()` and the import/export jobs would ignore it.

## Watch out
- Scaffold output needs a runtime test (ScaffoldRuntimeTest), not just `php -l`. The `--simple --soft-deletes` template shipped without `KinetixFlash`/`DB` imports and nobody noticed.
- Bulk delete has no Kinetix-owned message today (BulkActionController returns JSON).
