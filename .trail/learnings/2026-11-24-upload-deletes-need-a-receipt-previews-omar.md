---
title: "Upload deletes need a receipt, previews are resolved on the server, uploads are foldered per team"
author: Omar
date: 2026-11-24
branch: main
cost: { minutes: 33, tokens: 386804 }
id: l-e7053214
scope: repo
status: active
src: agent
touches:
  - src/Forms/UploadDirectory.php
  - tests/Feature/UploadEndpointTest.php
  - tests/js/components/KinetixFileUploadStorage.spec.ts
  - src/Forms/UploadController.php
  - src/Support/KinetixDisk.php
  - src/Forms/Components/FileUpload.php
  - resources/js/components/KinetixFileUpload.vue
---

## Decided
- uploads/delete takes only the receipt the upload answered (SignedDescriptor, audience UploadReceipt, user + team + expiry). The X in KinetixFileUpload deletes files uploaded in that form only; a record's stored file just leaves the value, the host deletes it on save. Stateless: no ledger needed for ownership.
- Preview URLs are computed server-side in FileUpload::toData from the field's reactive state (FormFieldData::$previewUrls). previewUrls null = rendered without the server (standalone) -> legacy /storage/ fallback; an empty map = server said "not previewable" -> file tile, never a guess.
- Private files: KinetixDisk::url($disk, $path, $private) uses temporaryUrl when the disk provides it, cached for half the TTL so the same URL is reused and the browser cache hits. Cache key is disk+path+ttl, not team: access is decided when rendering, not by the URL.
- Upload folders: {dir}/{md5('team|'.key)}/{md5(user)} via UploadDirectory::current(); team from KinetixTeams::keyFor('filesystem') (kinetix.filesystem.teams, null follows kinetix.teams). No team -> no upload, no adoption.

## Tried and ruled out
- A preview endpoint taking token + path: anyone holding a field token could read every file in that directory (other teams' private files on legacy paths).
- Signing whatever path the reactive state holds: live recompute feeds state back from the browser, so a crafted path would come back as a signed URL. Private fields only sign the record's stored value or the user's own upload dir.

## Watch out
- Kinetix does not own the save of a FileUpload value, so orphan cleanup can't be automatic: the planned kinetix_uploads ledger needs the host to claim files (claim(), a cast, keep()/sync()), and pruning must stay opt-in.
- FileUpload inside a Repeater: reactiveState is keyed by the top-level name, so nested uploads get previewUrls null (legacy /storage/).
- Team-mode upload tests need kinetix.teams set in defineEnvironment (routes register at boot) and PermissionServiceProvider (kinetix.permissions.team middleware).

In the code: src/Forms/UploadController.php, src/Forms/UploadDirectory.php, src/Support/KinetixDisk.php, src/Forms/Components/FileUpload.php (previewUrls), resources/js/components/KinetixFileUpload.vue, tests/Feature/UploadEndpointTest.php, tests/Feature/UploadEndpointTeamsTest.php.
