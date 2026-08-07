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
    /** Fired on release with the drop key under the finger (null = cancel). */
    onDrop: (payload: T, key: string | null) => void;
    /** Container auto-scrolled horizontally while dragging near its edges. */
    scrollContainer?: () => HTMLElement | null;
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
 * long-press activates simply cancels it.
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

    /** Keep scrolling the container while the finger holds near an edge. */
    const edgeScrollLoop = (): void => {
        const container = options.scrollContainer?.();

        if (isTouchDragging.value && container) {
            const rect = container.getBoundingClientRect();

            if (lastX < rect.left + EDGE_SCROLL_ZONE_PX) {
                container.scrollLeft -= EDGE_SCROLL_STEP_PX;
            } else if (lastX > rect.right - EDGE_SCROLL_ZONE_PX) {
                container.scrollLeft += EDGE_SCROLL_STEP_PX;
            }
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

        const rect = sourceEl.getBoundingClientRect();
        clone = sourceEl.cloneNode(true) as HTMLElement;
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

        isTouchDragging.value = true;
        navigator.vibrate?.(10);
        options.onStart?.(payload);
        setHoverKey(hitTest(lastX, lastY));
        edgeScrollLoop();
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

    const onPointerCancel = (): void => {
        cleanup();
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
        pendingTimer = setTimeout(activate, LONG_PRESS_MS);

        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerCancel);
        window.addEventListener('touchmove', onTouchMove, { passive: false });
        window.addEventListener('contextmenu', onContextMenu, true);
    };

    onBeforeUnmount(cleanup);

    return { isTouchDragging, startFromPointerDown };
}
