---
name: kinetix-lightbox
description: "A full-screen media viewer (lightbox/gallery): zoom from the thumbnail, arrows/keyboard/swipe paging, wheel/pinch zoom + pan, thumbnails, full screen, download; images, PDFs and other files. Activates when showing images that should enlarge on click, building a photo gallery, using ImageColumn::preview() / ImageEntry::preview(), Markdown/help images, PreviewAction, or calling openKinetixLightbox()."
license: MIT
metadata:
  author: happones
---

# Kinetix Lightbox

## When to Apply

Activate this skill when:
- An image should open bigger on click (thumbnails, avatars, product photos,
  screenshots in help articles).
- Building a photo gallery or a "view attachments" surface.
- Configuring how image previews behave app-wide.

## Documentation

For full details, reference `docs/lightbox.md` (published at https://happones.github.io/kinetix/lightbox).

## Setup

`<KinetixFilePreview />` must be mounted once in the app layout — it is the
lightbox host. Its props are app-wide defaults:

```vue
<KinetixFilePreview loop :toolbar="['zoom', 'fullscreen', 'download']" :max-zoom="6" />
```

Options: `loop`, `toolbar` (`zoom`, `thumbnails`, `fullscreen`, `open`,
`download`; close is always shown), `thumbnails` (`'auto'`/`true`/`false`),
`zoom`, `maxZoom`, `closeOnBackdrop`, `swipeToClose`, `animation`
(`zoom`/`fade`/`none`), `preload`, and `bind` (open `[data-kinetix-lightbox]`
elements on click).

## Backend

```php
use Happones\Kinetix\Infolists\Components\ImageEntry;
use Happones\Kinetix\Tables\Columns\ImageColumn;

ImageColumn::make('photo')->label(__('products.fields.photo'))->preview();   // the column's images = one gallery
ImageColumn::make('photo')->preview(gallery: false);                          // each image on its own
ImageEntry::make('cover')->label(__('products.fields.cover'))->preview();     // the infolist's images = one gallery
```

`PreviewAction` opens its URL in the same viewer. Help Center and Docs article
images open in it automatically; turn that off app-wide with
`config/kinetix.php` → `'lightbox' => ['markdown' => false]`
(`KINETIX_LIGHTBOX_MARKDOWN=false`), or per component with `:lightbox="false"`
on `KinetixMarkdownContent`, `KinetixHelpArticle` or `KinetixDocs` (the prop
wins over the config either way).

## Frontend

```vue
<KinetixLightboxImage
  v-for="photo in photos"
  :key="photo.id"
  :src="photo.url"
  :thumbnail="photo.thumb_url"
  :alt="photo.alt"
  :caption="photo.title"
  group="property"
/>
```

Plain markup works too: `<a href="/big.jpg" data-kinetix-lightbox="trip"><img src="/thumb.jpg" alt="…"></a>`.

From script:

```ts
import { openKinetixLightbox } from '@/composables/useKinetixLightbox';

openKinetixLightbox([{ url: '/a.jpg', caption: 'Front' }, { url: '/manual.pdf' }], { index: 0 });
```

`<KinetixLightbox v-model:open v-model:index :items>` is the controlled viewer
for a page that wants to own its state (slots `caption` and `actions`).

## Rules

- Do not build a separate image modal: use `KinetixLightboxImage`,
  `data-kinetix-lightbox` or `openKinetixLightbox()` so every image behaves the
  same and app-wide options apply.
- Give images real alt text — triggers are buttons named after it.
- Pass a small `thumbnail` and the full-size `src` for large galleries.
