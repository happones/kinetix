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
spot; the order is emitted once on drop). Touch screens drag from the tile's
grip, and the arrow keys on the grip move a tile one place (announced). Value = ordered array of
`{ id?, path?, url, name, size?, mime?, thumb? }` — new uploads carry `path`,
existing spatie media carry `id`.

## Standalone vs spatie

- **No spatie**: self-contained uploader; store the value array in your own
  column, but only after `KinetixMedia::keep()` (never the raw request array):

```php
$product->gallery = KinetixMedia::keep(
    $state['gallery'] ?? [],
    current: $product->gallery ?? [],
    directory: 'products', // the field's directory()
);
```

  `keep()` keeps stored items, accepts a new one only from the current user's
  upload directory for the field when the file exists, and reads url/size/mime
  from the disk (the name stays the upload's original file name, as a label).
- **spatie/laravel-medialibrary** (record implements `HasMedia`): hydrate +
  persist via the helper:

```php
use Happones\Kinetix\Media\KinetixMedia;

// fill
$form->fill(['gallery' => KinetixMedia::items($product, 'images', 'thumb')]);

// save (adds new, removes deleted, reorders) — no-op without spatie.
// Pass the field's directory(): new files must be the user's own uploads there.
KinetixMedia::sync($product, 'images', $state['gallery'], directory: 'products');
```

## Frontend

`<KinetixMediaLibrary>` is rendered automatically by `KinetixForm` for the
`media-library` field type: thumbnail grid, drag-drop/click upload, drag-reorder,
delete, preview. i18n `media_add`/`media_uploading`/`media_upload_failed`.

Deferred: folders, native (non-spatie) image variants — use spatie conversions.
