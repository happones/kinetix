# Media Library

A multi-file **media manager** field: drag-and-drop (or click) to upload many
files, see a thumbnail grid, **reorder by dragging**, delete, and preview. It
works standalone, and integrates with
[spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary) when
that package is installed — collections, conversions and ordering included.

<Screenshot name="media-library" alt="Media library grid" />

---

## The field

```php
use Happones\Kinetix\Forms\Components\MediaLibrary;

MediaLibrary::make('gallery')
    ->collection('images')          // spatie collection / logical group
    ->image()                       // restrict to images + show thumbnails
    ->conversions(['thumb'])        // spatie conversions to surface in the grid
    ->maxFiles(10)
    ->disk('public')
    ->directory('products');
```

Builds on [FileUpload](/forms) — same signed upload token, disk, directory and
size/type constraints. Multiple + reorderable by default; turn dragging off with
`->reorderable(false)`.

The field value is an **ordered array of media items**:

```ts
{ id?, path?, url, name, size?, mime?, thumb? }[]
```

Newly uploaded files carry a `path` (the stored temp path); existing spatie media
carry an `id`. Reordering just reorders the array.

---

## Standalone (no spatie)

Without spatie the field is a self-contained uploader: persist the value array
however you like (e.g. a JSON column). Uploaded files land on the configured disk
via the shared `{prefix}/uploads/store` endpoint.

---

## With spatie/laravel-medialibrary

Install the package and make your model implement `HasMedia`:

```bash
composer require spatie/laravel-medialibrary
```

```php
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(200)->height(200);
    }
}
```

Then **hydrate** the field from the collection and **sync** on save via the
`KinetixMedia` helper:

```php
use Happones\Kinetix\Media\KinetixMedia;

// edit() — fill the field from the model's media
$form = ProductResource::form(Form::make($product))->fill([
    'gallery' => KinetixMedia::items($product, 'images', 'thumb'),
]);

// update() — reconcile the collection with the submitted state
$state = $form->getState($request->all());
KinetixMedia::sync($product, 'images', $state['gallery']);
```

`sync()` adds newly uploaded files, removes the ones the user deleted, and
persists the new order — all on the bound collection. It's a **no-op** when
spatie isn't installed (or the record isn't `HasMedia`), so the same form code is
safe either way.

---

## API

| Method               | Effect |
| -------------------- | ------ |
| `collection(string)` | spatie collection name / logical group |
| `conversions([...])` | spatie conversion names surfaced as thumbnails |
| `reorderable(bool)`  | drag-to-reorder (default `true`) |
| `image()`            | restrict to images + thumbnail previews |
| `maxFiles(int)`      | cap the number of files |
| `disk()` · `directory()` · `acceptedFileTypes()` · `maxSize()` | inherited from FileUpload |

`KinetixMedia::items($record, $collection, $conversion?)` → the field's item
array. `KinetixMedia::sync($record, $collection, $items, $disk?)` → persist.

::: tip Folders & native variants
v1 covers the grid, reordering, upload and the spatie bridge. Folders and
native (non-spatie) image variants are not included yet — use spatie conversions
for variants.
:::
