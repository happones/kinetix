---
name: kinetix-media-library
description: "A multi-file media manager field: drag-drop upload, thumbnail grid, drag-reorder, delete, preview. Works standalone; integrates with spatie/laravel-medialibrary (collections, conversions, order) when installed. Activates when building a gallery / multi-image / media manager field."
license: MIT
metadata:
  author: happones
---

# Kinetix Media Library

## When to Apply

Activate this skill when:
- Building a multi-file / gallery / media-manager field (upload many, reorder,
  delete, preview).
- Wiring a form field to a spatie media collection.

## Documentation

For full details, reference `docs/media-library.md` (published at https://happones.github.io/kinetix/media-library).

## The field

```php
use Happones\Kinetix\Forms\Components\MediaLibrary;

MediaLibrary::make('gallery')
    ->collection('images')
    ->image()
    ->conversions(['thumb'])
    ->maxFiles(10);
```

Builds on `FileUpload` (same upload token / disk / constraints). Multiple +
reorderable by default (drag with a translucent live preview of the landing
spot; the order is emitted once on drop). Value = ordered array of
`{ id?, path?, url, name, size?, mime?, thumb? }` — new uploads carry `path`,
existing spatie media carry `id`.

## Standalone vs spatie

- **No spatie**: self-contained uploader; persist the value array yourself.
- **spatie/laravel-medialibrary** (record implements `HasMedia`): hydrate +
  persist via the helper:

```php
use Happones\Kinetix\Media\KinetixMedia;

// fill
$form->fill(['gallery' => KinetixMedia::items($product, 'images', 'thumb')]);

// save (adds new, removes deleted, reorders) — no-op without spatie
KinetixMedia::sync($product, 'images', $state['gallery']);
```

## Frontend

`<KinetixMediaLibrary>` is rendered automatically by `KinetixForm` for the
`media-library` field type: thumbnail grid, drag-drop/click upload, drag-reorder,
delete, preview. i18n `media_add`/`media_uploading`/`media_upload_failed`.

Deferred: folders, native (non-spatie) image variants — use spatie conversions.
