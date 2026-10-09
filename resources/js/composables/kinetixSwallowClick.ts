/**
 * How long a touch or pen release waits for its click. The click a tap
 * produces comes a little after the release (later still on a page without a
 * mobile viewport); a gesture that moved produces none at all.
 */
export const KINETIX_TOUCH_CLICK_WINDOW_MS = 500;

/**
 * Swallow the click a browser sends after a drag is released, so it doesn't
 * open or select what sits under the pointer. The guard is always dropped
 * after `withinMs` — a mouse click arrives in the same task as the release
 * (0), a touch one within {@link KINETIX_TOUCH_CLICK_WINDOW_MS} — so when no
 * click comes, the next real one isn't eaten.
 */
export function swallowNextClick(withinMs = 0): void {
    const swallow = (event: Event): void => {
        event.preventDefault();
        event.stopPropagation();
    };

    window.addEventListener('click', swallow, { capture: true, once: true });
    setTimeout(
        () => window.removeEventListener('click', swallow, true),
        withinMs,
    );
}

/** The click window for a release by this kind of pointer. */
export function clickWindowFor(pointerType: string): number {
    return pointerType === 'mouse' ? 0 : KINETIX_TOUCH_CLICK_WINDOW_MS;
}
