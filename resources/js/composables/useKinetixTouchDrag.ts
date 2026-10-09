import { onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';

export interface KinetixTouchDragOptions<T> {
    /**
     * Attribute marking drop targets (e.g. `data-kanban-column`); the hovered
     * target's attribute value is the drop key reported to the callbacks.
     */
    targetAttr: string;
    /** Fired once the long-press activates the drag. */
    onStart?: (payload: T) => void;
    /** Fired whenever the drop key under the finger changes (null = none). */
    onHover?: (key: string | null) => void;
    /**
     * Fired on every move while dragging, with the drop key and the finger's
     * viewport position — for targets that place the item at a point within
     * themselves (a slot in a kanban column), not just on the target.
     */
    onMove?: (key: string | null, x: number, y: number) => void;
    /** Fired on release with the drop key under the finger (null = cancel). */
    onDrop: (payload: T, key: string | null) => void;
    /**
     * Fired when an active drag ends without a release: the platform took the
     * gesture over (`pointercancel`) or the component unmounted mid-drag.
     */
    onCancel?: () => void;
    /** Container auto-scrolled while dragging near its edges. */
    scrollContainer?: () => HTMLElement | null;
    /**
     * Which way the edge auto-scroll runs: `x` (default) scrolls
     * `scrollContainer` horizontally; `y` scrolls it vertically — without
     * one, the dragged element's nearest scrolling ancestor, else the page.
     */
    scrollAxis?: 'x' | 'y';
    /**
     * `long-press` (default): the drag starts after holding still, so a
     * touch on the element can still scroll the page. `immediate`: it starts
     * on touch, for a dedicated grip styled `touch-action: none`.
     */
    activation?: 'long-press' | 'immediate';
    /**
     * Lift the element into a floating clone that follows the finger
     * (default). Off for lists that preview the move in place.
     */
    clone?: boolean;
}

export interface KinetixTouchDrag<T> {
    /** True while a touch drag is in flight (after long-press activation). */
    isTouchDragging: Ref<boolean>;
    /**
     * Wire to `@pointerdown` on each draggable element. Ignores mouse input
     * (native HTML5 drag-and-drop handles it); touch/pen starts a long-press
     * that either activates the drag or yields to scrolling.
     */
    startFromPointerDown: (
        event: PointerEvent,
        el: HTMLElement,
        payload: T,
    ) => void;
}

const LONG_PRESS_MS = 250;
const MOVE_TOLERANCE_PX = 8;
const EDGE_SCROLL_ZONE_PX = 48;
const EDGE_SCROLL_STEP_PX = 12;

/**
 * Touch/pen fallback for native HTML5 drag-and-drop, which never fires on
 * touch devices. A long-press (250ms without moving) lifts the element into a
 * floating clone that tracks the finger in real time; drop targets are
 * hit-tested by attribute so the host only maintains highlight state and the
 * move itself. Scrolling stays the default gesture — moving before the
 * long-press activates simply cancels it. A dedicated grip can start the drag
 * on touch instead (`activation: 'immediate'`), and lists that preview the
 * move in place can skip the clone (`clone: false`).
 */
export function useKinetixTouchDrag<T>(
    options: KinetixTouchDragOptions<T>,
): KinetixTouchDrag<T> {
    const isTouchDragging = ref(false);

    let pendingTimer: ReturnType<typeof setTimeout> | null = null;
    let payload: T | null = null;
    let sourceEl: HTMLElement | null = null;
    let clone: HTMLElement | null = null;
    let startX = 0;
    let startY = 0;
    let lastX = 0;
    let lastY = 0;
    let hoverKey: string | null = null;
    let edgeScrollFrame: number | null = null;
    let verticalScroller: HTMLElement | null = null;

    const setHoverKey = (key: string | null): void => {
        if (key !== hoverKey) {
            hoverKey = key;
            options.onHover?.(key);
        }
    };

    const hitTest = (x: number, y: number): string | null => {
        const el = document.elementFromPoint(x, y);

        return (
            el
                ?.closest(`[${options.targetAttr}]`)
                ?.getAttribute(options.targetAttr) ?? null
        );
    };

    /** How far to scroll this frame: toward whichever edge the finger holds near. */
    const edgeStep = (position: number, start: number, end: number): number => {
        if (position < start + EDGE_SCROLL_ZONE_PX) {
            return -EDGE_SCROLL_STEP_PX;
        }

        return position > end - EDGE_SCROLL_ZONE_PX ? EDGE_SCROLL_STEP_PX : 0;
    };

    /** Scroll toward the edge the finger holds near; true when it scrolled. */
    const edgeScroll = (): boolean => {
        const container = options.scrollContainer?.() ?? null;

        if (options.scrollAxis !== 'y') {
            if (!container) {
                return false;
            }

            const rect = container.getBoundingClientRect();
            const before = container.scrollLeft;
            container.scrollLeft += edgeStep(lastX, rect.left, rect.right);

            return container.scrollLeft !== before;
        }

        const scroller = container ?? verticalScroller;

        if (!scroller) {
            const before = window.scrollY;
            window.scrollBy(0, edgeStep(lastY, 0, window.innerHeight));

            return window.scrollY !== before;
        }

        const rect = scroller.getBoundingClientRect();
        const before = scroller.scrollTop;
        scroller.scrollTop += edgeStep(lastY, rect.top, rect.bottom);

        return scroller.scrollTop !== before;
    };

    /** The nearest ancestor that scrolls vertically (a modal, a windowed grid). */
    const nearestVerticalScroller = (el: HTMLElement): HTMLElement | null => {
        for (let node = el.parentElement; node; node = node.parentElement) {
            const { overflowY } = getComputedStyle(node);

            if (
                (overflowY === 'auto' || overflowY === 'scroll') &&
                node.scrollHeight > node.clientHeight
            ) {
                return node;
            }
        }

        return null;
    };

    /** Keep scrolling while the finger holds near an edge. */
    const edgeScrollLoop = (): void => {
        // Content moved under a still finger: what it's over changed too.
        if (isTouchDragging.value && edgeScroll()) {
            setHoverKey(hitTest(lastX, lastY));
            options.onMove?.(hoverKey, lastX, lastY);
        }

        edgeScrollFrame = isTouchDragging.value
            ? requestAnimationFrame(edgeScrollLoop)
            : null;
    };

    const activate = (): void => {
        pendingTimer = null;

        if (!sourceEl || payload === null) {
            return;
        }

        if (options.clone !== false) {
            liftClone(sourceEl);
        }

        verticalScroller =
            options.scrollAxis === 'y'
                ? nearestVerticalScroller(sourceEl)
                : null;

        isTouchDragging.value = true;

        if (options.activation !== 'immediate') {
            navigator.vibrate?.(10);
        }

        options.onStart?.(payload);
        setHoverKey(hitTest(lastX, lastY));
        options.onMove?.(hoverKey, lastX, lastY);
        edgeScrollLoop();
    };

    const liftClone = (el: HTMLElement): void => {
        const rect = el.getBoundingClientRect();
        clone = el.cloneNode(true) as HTMLElement;
        Object.assign(clone.style, {
            position: 'fixed',
            top: `${rect.top}px`,
            left: `${rect.left}px`,
            width: `${rect.width}px`,
            height: `${rect.height}px`,
            margin: '0',
            zIndex: 'var(--kinetix-z-popover, 120)',
            pointerEvents: 'none',
            opacity: '0.95',
            boxShadow:
                '0 10px 15px -3px rgb(0 0 0 / 0.2), 0 4px 6px -4px rgb(0 0 0 / 0.2)',
            transform: 'scale(1.03)',
            willChange: 'transform',
        });
        document.body.appendChild(clone);
    };

    const cleanup = (): void => {
        if (pendingTimer !== null) {
            clearTimeout(pendingTimer);
            pendingTimer = null;
        }

        if (edgeScrollFrame !== null) {
            cancelAnimationFrame(edgeScrollFrame);
            edgeScrollFrame = null;
        }

        clone?.remove();
        clone = null;
        sourceEl = null;
        verticalScroller = null;
        payload = null;
        isTouchDragging.value = false;
        setHoverKey(null);

        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', onPointerCancel);
        window.removeEventListener('touchmove', onTouchMove);
        window.removeEventListener('contextmenu', onContextMenu, true);
    };

    const onPointerMove = (event: PointerEvent): void => {
        lastX = event.clientX;
        lastY = event.clientY;

        if (!isTouchDragging.value) {
            // Moving before the long-press fires means the user is scrolling.
            if (
                Math.abs(event.clientX - startX) > MOVE_TOLERANCE_PX ||
                Math.abs(event.clientY - startY) > MOVE_TOLERANCE_PX
            ) {
                cleanup();
            }

            return;
        }

        if (clone) {
            clone.style.transform = `translate3d(${event.clientX - startX}px, ${event.clientY - startY}px, 0) scale(1.03)`;
        }

        setHoverKey(hitTest(event.clientX, event.clientY));
        options.onMove?.(hoverKey, event.clientX, event.clientY);
    };

    // Once the drag is active the page must not scroll under it. `touchmove`
    // is the only cancelable scroll signal, so it's registered non-passive.
    const onTouchMove = (event: TouchEvent): void => {
        if (isTouchDragging.value) {
            event.preventDefault();
        }
    };

    // The long-press would otherwise pop the platform context menu / text
    // selection callout over the card being lifted.
    const onContextMenu = (event: Event): void => {
        if (isTouchDragging.value || pendingTimer !== null) {
            event.preventDefault();
        }
    };

    /** Swallow the click that follows a completed touch drag on release. */
    const suppressNextClick = (): void => {
        window.addEventListener(
            'click',
            (event) => {
                event.preventDefault();
                event.stopPropagation();
            },
            { capture: true, once: true },
        );
    };

    const onPointerUp = (): void => {
        if (isTouchDragging.value) {
            const dropPayload = payload;
            const dropKey = hoverKey;
            suppressNextClick();
            cleanup();

            if (dropPayload !== null) {
                options.onDrop(dropPayload, dropKey);
            }

            return;
        }

        cleanup();
    };

    /** End the gesture without a drop, telling the host when one was active. */
    const cancel = (): void => {
        const wasDragging = isTouchDragging.value;
        cleanup();

        if (wasDragging) {
            options.onCancel?.();
        }
    };

    const onPointerCancel = (): void => {
        cancel();
    };

    const startFromPointerDown = (
        event: PointerEvent,
        el: HTMLElement,
        dragPayload: T,
    ): void => {
        if (event.pointerType === 'mouse' || !event.isPrimary) {
            return;
        }

        cleanup();

        payload = dragPayload;
        sourceEl = el;
        startX = lastX = event.clientX;
        startY = lastY = event.clientY;

        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerCancel);
        window.addEventListener('touchmove', onTouchMove, { passive: false });
        window.addEventListener('contextmenu', onContextMenu, true);

        if (options.activation === 'immediate') {
            activate();
        } else {
            pendingTimer = setTimeout(activate, LONG_PRESS_MS);
        }
    };

    onBeforeUnmount(cancel);

    return { isTouchDragging, startFromPointerDown };
}
