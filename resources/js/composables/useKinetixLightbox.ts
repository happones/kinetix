import type {
    KinetixLightboxItem,
    KinetixLightboxOptions,
    KinetixPreviewDetail,
} from '@/types/kinetix';

/**
 * The lightbox plumbing shared by every surface that shows images: the
 * `kinetix:preview` window event the global host (`<KinetixFilePreview />`)
 * listens to, and the `data-kinetix-lightbox` attribute that groups triggers
 * into a gallery.
 *
 *   openKinetixLightbox([{ url: '/a.jpg' }, { url: '/b.jpg' }], { index: 1 });
 *
 *   <a href="/big.jpg" data-kinetix-lightbox="trip"><img src="/thumb.jpg" alt="…" /></a>
 *
 * Triggers sharing a group value browse as one gallery, in document order,
 * inside the closest `[data-kinetix-lightbox-scope]` (or the whole page). An
 * empty value opens the element on its own.
 */
export const LIGHTBOX_ATTRIBUTE = 'data-kinetix-lightbox';
export const LIGHTBOX_SCOPE_ATTRIBUTE = 'data-kinetix-lightbox-scope';

const IMAGE_EXTENSION = /\.(png|jpe?g|gif|webp|svg|avif|bmp|ico)(\?|#|$)/i;
const PDF_EXTENSION = /\.pdf(\?|#|$)/i;

/** Items registered by components (KinetixLightboxImage) for their trigger. */
const registered = new WeakMap<Element, () => KinetixLightboxItem>();

export function resolveLightboxItemType(
    item: Pick<KinetixLightboxItem, 'url' | 'type'>,
): 'image' | 'pdf' | 'other' {
    if (item.type === 'image' || item.type === 'pdf' || item.type === 'other') {
        return item.type;
    }

    if (IMAGE_EXTENSION.test(item.url) || item.url.startsWith('data:image/')) {
        return 'image';
    }

    if (PDF_EXTENSION.test(item.url)) {
        return 'pdf';
    }

    return 'other';
}

/** Both event shapes as one gallery; null when there is nothing to show. */
export function normalizePreviewDetail(
    detail: KinetixPreviewDetail | null | undefined,
): { items: KinetixLightboxItem[]; index: number } | null {
    if (!detail) {
        return null;
    }

    const items = (detail.items ?? []).filter((item) => !!item?.url);

    if (items.length > 0) {
        const index = Math.min(
            Math.max(Math.trunc(detail.index ?? 0), 0),
            items.length - 1,
        );

        return { items, index };
    }

    if (!detail.url) {
        return null;
    }

    return {
        items: [
            {
                url: detail.url,
                type: detail.type ?? 'auto',
                caption: detail.label ?? null,
                alt: detail.label ?? null,
                downloadUrl: detail.downloadUrl ?? null,
                downloadLabel: detail.downloadLabel ?? null,
                origin: detail.origin ?? null,
            },
        ],
        index: 0,
    };
}

/**
 * Open items in the app's lightbox. Returns whether a host handled it; when
 * none is mounted the item opens in a new tab instead, so a trigger never
 * does nothing.
 */
export function openKinetixLightbox(
    items: KinetixLightboxItem[],
    {
        index = 0,
        options,
    }: { index?: number; options?: KinetixLightboxOptions } = {},
): boolean {
    const detail: KinetixPreviewDetail = { items, index, options };
    const first = items[index] ?? items[0];

    if (!first || typeof window === 'undefined') {
        return false;
    }

    // `url`/`type`/`label` keep listeners written for the single-file shape working.
    Object.assign(detail, {
        url: first.url,
        type: first.type ?? 'auto',
        label: first.caption ?? first.alt ?? null,
    });

    const handled = !window.dispatchEvent(
        new CustomEvent('kinetix:preview', { cancelable: true, detail }),
    );

    if (!handled) {
        window.open(first.url, '_blank', 'noopener,noreferrer');
    }

    return handled;
}

/** Tie a trigger element to the item it opens (read lazily on click). */
export function registerLightboxItem(
    element: Element,
    item: () => KinetixLightboxItem,
): void {
    registered.set(element, item);
}

export function unregisterLightboxItem(element: Element): void {
    registered.delete(element);
}

/**
 * The item a trigger opens: the registered one, else read from the markup —
 * `data-src` or `href` for the full file, the inner `<img>` for the thumbnail
 * and alt text, `data-caption` / `data-description` for the caption.
 */
export function lightboxItemFromElement(
    element: Element,
): KinetixLightboxItem | null {
    const fromComponent = registered.get(element);

    if (fromComponent) {
        return { origin: element, ...fromComponent() };
    }

    const html = element as HTMLElement;
    const image =
        element instanceof HTMLImageElement
            ? element
            : element.querySelector('img');
    const url =
        html.dataset?.src ??
        element.getAttribute('href') ??
        image?.currentSrc ??
        image?.getAttribute('src') ??
        null;

    if (!url) {
        return null;
    }

    return {
        url,
        type: html.dataset?.type ?? 'auto',
        thumbnail: image?.currentSrc || image?.getAttribute('src') || null,
        alt: image?.getAttribute('alt') || null,
        caption: html.dataset?.caption ?? image?.getAttribute('title') ?? null,
        description: html.dataset?.description ?? null,
        downloadUrl: html.dataset?.downloadUrl ?? null,
        origin: image ?? element,
    };
}

/**
 * The gallery a trigger belongs to: every trigger with the same group value
 * inside `scope` (default: its closest `[data-kinetix-lightbox-scope]`, else
 * the page), in document order, and the trigger's position in it.
 */
export function collectLightboxGroup(
    trigger: Element,
    scope?: ParentNode | null,
): { items: KinetixLightboxItem[]; index: number } {
    const group = trigger.getAttribute(LIGHTBOX_ATTRIBUTE) ?? '';
    const single = lightboxItemFromElement(trigger);

    if (group === '') {
        return { items: single ? [single] : [], index: 0 };
    }

    const root: ParentNode =
        scope ??
        trigger.closest(`[${LIGHTBOX_SCOPE_ATTRIBUTE}]`) ??
        trigger.ownerDocument;
    const members = Array.from(
        root.querySelectorAll(`[${LIGHTBOX_ATTRIBUTE}="${CSS.escape(group)}"]`),
    );
    const items: KinetixLightboxItem[] = [];
    let index = 0;

    for (const member of members) {
        const item = lightboxItemFromElement(member);

        if (!item) {
            continue;
        }

        if (member === trigger) {
            index = items.length;
        }

        items.push(item);
    }

    if (items.length === 0 && single) {
        return { items: [single], index: 0 };
    }

    return { items, index };
}

/**
 * Keyframes that grow `media` out of `origin` (FLIP): the image starts scaled
 * and clipped to the thumbnail's box — same corner radius, cropped like an
 * `object-cover` thumbnail — and ends at its own layout. Reverse them to
 * shrink back. Null when either box is empty or the origin is off screen,
 * where a plain fade reads better than a zoom from nowhere.
 */
export function lightboxFlipFrames(
    media: Element,
    origin: Element,
): Keyframe[] | null {
    if (!origin.isConnected) {
        return null;
    }

    const to = media.getBoundingClientRect();
    const from = origin.getBoundingClientRect();
    const view = origin.ownerDocument.defaultView;

    if (!to.width || !to.height || !from.width || !from.height || !view) {
        return null;
    }

    const offScreen =
        from.bottom < 0 ||
        from.right < 0 ||
        from.top > view.innerHeight ||
        from.left > view.innerWidth;

    if (offScreen) {
        return null;
    }

    const scale = Math.max(from.width / to.width, from.height / to.height);
    const dx = from.left + from.width / 2 - (to.left + to.width / 2);
    const dy = from.top + from.height / 2 - (to.top + to.height / 2);
    const insetX = Math.max(0, (to.width - from.width / scale) / 2);
    const insetY = Math.max(0, (to.height - from.height / scale) / 2);
    const radius =
        parseFloat(view.getComputedStyle(origin).borderTopLeftRadius) || 0;

    return [
        {
            transform: `translate(${dx}px, ${dy}px) scale(${scale})`,
            clipPath: `inset(${insetY}px ${insetX}px round ${radius / scale}px)`,
        },
        {
            transform: 'translate(0px, 0px) scale(1)',
            clipPath: 'inset(0px 0px round 0px)',
        },
    ];
}
