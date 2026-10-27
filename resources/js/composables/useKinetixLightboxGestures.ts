import { computed, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Pointer gestures for KinetixLightbox's stage, one model for mouse, pen and
 * touch:
 *
 * - wheel, pinch, double-click / double-tap and the zoom buttons zoom around
 *   a point, from fit (1×) up to `maxZoom`;
 * - dragging a zoomed image pans it, clamped so its edges never leave the
 *   stage;
 * - at fit, a horizontal drag follows the finger and pages to the next or
 *   previous item past a distance or speed threshold, and a vertical drag
 *   dismisses the viewer the same way;
 * - a tap that moved less than the drag threshold is a tap (`onTap`).
 *
 * The zoom math assumes the media is centered in the stage with
 * `transform-origin: center`, which is how the slide lays it out.
 */
export interface KinetixLightboxGestureOptions {
    stage: Ref<HTMLElement | null>;
    media: () => HTMLElement | null;
    zoomable: () => boolean;
    maxZoom: () => number;
    /** Whether paging toward `direction` (1 = next) would move. */
    canPage: (direction: 1 | -1) => boolean;
    swipeToClose: () => boolean;
    onPage: (direction: 1 | -1) => void;
    onDismiss: () => void;
    onTap: (event: PointerEvent) => void;
}

const DRAG_THRESHOLD = 8;
const DOUBLE_TAP_MS = 300;
const ZOOM_STEP = 1.5;
const DOUBLE_TAP_ZOOM = 2.5;

type Mode = 'idle' | 'pending' | 'pan' | 'page' | 'dismiss' | 'pinch';

interface Point {
    x: number;
    y: number;
}

function distance(a: Point, b: Point): number {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

function midpoint(a: Point, b: Point): Point {
    return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
}

export function useKinetixLightboxGestures(
    options: KinetixLightboxGestureOptions,
) {
    const scale = ref(1);
    const offsetX = ref(0);
    const offsetY = ref(0);
    const dragX = ref(0);
    const dragY = ref(0);
    const gesturing = ref(false);

    const pointers = new Map<number, Point>();
    let mode: Mode = 'idle';
    let start = { x: 0, y: 0, time: 0, offsetX: 0, offsetY: 0 };
    let pinch = { distance: 1, scale: 1 };
    let lastTap = { time: 0, x: 0, y: 0 };

    const zoomed = computed(() => scale.value > 1.01);
    const canZoomIn = computed(
        () => options.zoomable() && scale.value < options.maxZoom() - 0.01,
    );
    const canZoomOut = computed(() => options.zoomable() && zoomed.value);
    /** 0 → 1 as a dismiss drag approaches the release threshold. */
    const dismissProgress = computed(() => {
        const height = options.stage.value?.clientHeight || 600;

        return Math.min(Math.abs(dragY.value) / (height * 0.35), 1);
    });

    function bounds(next: number): Point {
        const stage = options.stage.value;
        const media = options.media();

        if (!stage || !media) {
            return { x: 0, y: 0 };
        }

        return {
            x: Math.max(0, (media.offsetWidth * next - stage.clientWidth) / 2),
            y: Math.max(
                0,
                (media.offsetHeight * next - stage.clientHeight) / 2,
            ),
        };
    }

    function clampOffsets(): void {
        const limit = bounds(scale.value);

        offsetX.value = Math.min(Math.max(offsetX.value, -limit.x), limit.x);
        offsetY.value = Math.min(Math.max(offsetY.value, -limit.y), limit.y);
    }

    function reset(): void {
        scale.value = 1;
        offsetX.value = 0;
        offsetY.value = 0;
        dragX.value = 0;
        dragY.value = 0;
    }

    /** Zoom to `next`, keeping the content under `point` (client coords) in place. */
    function zoomTo(next: number, point?: Point): void {
        if (!options.zoomable()) {
            return;
        }

        const target = Math.min(Math.max(next, 1), options.maxZoom());

        if (target <= 1.01) {
            reset();

            return;
        }

        const rect = options.stage.value?.getBoundingClientRect();
        const ratio = target / scale.value;

        if (rect && point) {
            const px = point.x - (rect.left + rect.width / 2);
            const py = point.y - (rect.top + rect.height / 2);

            offsetX.value = px - (px - offsetX.value) * ratio;
            offsetY.value = py - (py - offsetY.value) * ratio;
        }

        if (!point) {
            offsetX.value *= ratio;
            offsetY.value *= ratio;
        }

        scale.value = target;
        clampOffsets();
    }

    const zoomIn = (): void => zoomTo(scale.value * ZOOM_STEP);
    const zoomOut = (): void => zoomTo(scale.value / ZOOM_STEP);

    function toggleZoom(point?: Point): void {
        if (zoomed.value) {
            reset();

            return;
        }

        zoomTo(Math.min(DOUBLE_TAP_ZOOM, options.maxZoom()), point);
    }

    function begin(point: Point): void {
        mode = 'pending';
        start = {
            x: point.x,
            y: point.y,
            time: performance.now(),
            offsetX: offsetX.value,
            offsetY: offsetY.value,
        };
    }

    function onPointerDown(event: PointerEvent): void {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        if ((event.target as Element | null)?.closest?.('button, a')) {
            return;
        }

        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        (event.currentTarget as Element | null)?.setPointerCapture?.(
            event.pointerId,
        );

        if (pointers.size === 2 && options.zoomable()) {
            const [a, b] = Array.from(pointers.values());

            mode = 'pinch';
            pinch = { distance: distance(a, b) || 1, scale: scale.value };
            dragX.value = 0;
            dragY.value = 0;
            gesturing.value = true;

            return;
        }

        if (pointers.size === 1) {
            begin({ x: event.clientX, y: event.clientY });
        }
    }

    function decide(dx: number, dy: number): Mode {
        if (zoomed.value) {
            return 'pan';
        }

        if (Math.abs(dx) > Math.abs(dy)) {
            return 'page';
        }

        return options.swipeToClose() ? 'dismiss' : 'idle';
    }

    function onPointerMove(event: PointerEvent): void {
        if (!pointers.has(event.pointerId)) {
            return;
        }

        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

        if (mode === 'pinch' && pointers.size >= 2) {
            const [a, b] = Array.from(pointers.values());
            const next = pinch.scale * (distance(a, b) / pinch.distance);

            zoomTo(next, midpoint(a, b));

            return;
        }

        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;

        if (mode === 'pending') {
            if (Math.hypot(dx, dy) < DRAG_THRESHOLD) {
                return;
            }

            mode = decide(dx, dy);
            gesturing.value = mode !== 'idle';
        }

        if (mode === 'pan') {
            offsetX.value = start.offsetX + dx;
            offsetY.value = start.offsetY + dy;
            clampOffsets();

            return;
        }

        if (mode === 'page') {
            // Resist past the first/last item instead of moving freely.
            dragX.value = options.canPage(dx < 0 ? 1 : -1) ? dx : dx * 0.3;

            return;
        }

        if (mode === 'dismiss') {
            dragY.value = dy;
        }
    }

    function finish(event: PointerEvent): void {
        const elapsed = Math.max(performance.now() - start.time, 1);
        const stage = options.stage.value;

        if (mode === 'page') {
            const dx = dragX.value;
            const fast = Math.abs(dx) / elapsed > 0.4;
            const far = Math.abs(dx) > (stage?.clientWidth ?? 600) * 0.15;
            const direction: 1 | -1 = dx < 0 ? 1 : -1;

            dragX.value = 0;

            if ((fast || far) && options.canPage(direction)) {
                options.onPage(direction);
            }

            return;
        }

        if (mode === 'dismiss') {
            const dy = dragY.value;
            const fast = Math.abs(dy) / elapsed > 0.5;
            const far = Math.abs(dy) > (stage?.clientHeight ?? 600) * 0.15;

            if (fast || far) {
                options.onDismiss();

                return;
            }

            dragY.value = 0;

            return;
        }

        if (mode !== 'pending') {
            return;
        }

        const now = performance.now();
        const point = { x: event.clientX, y: event.clientY };
        const isDoubleTap =
            now - lastTap.time < DOUBLE_TAP_MS &&
            distance(point, lastTap) < 24 &&
            (event.target as Element | null)?.closest?.(
                '[data-lightbox-media]',
            );

        lastTap = isDoubleTap
            ? { time: 0, x: 0, y: 0 }
            : { time: now, ...point };

        if (isDoubleTap) {
            toggleZoom(point);

            return;
        }

        options.onTap(event);
    }

    function onPointerUp(event: PointerEvent): void {
        if (!pointers.delete(event.pointerId)) {
            return;
        }

        if (mode === 'pinch') {
            // Lifting one finger of a pinch carries on as a pan with the other.
            const rest = Array.from(pointers.values())[0];

            if (rest) {
                begin(rest);
                mode = zoomed.value ? 'pan' : 'idle';

                return;
            }

            mode = 'idle';
            gesturing.value = false;

            return;
        }

        if (pointers.size > 0) {
            return;
        }

        finish(event);
        mode = 'idle';
        gesturing.value = false;
    }

    function onPointerCancel(event: PointerEvent): void {
        pointers.delete(event.pointerId);

        if (pointers.size > 0) {
            return;
        }

        mode = 'idle';
        gesturing.value = false;
        dragX.value = 0;
        dragY.value = 0;
    }

    function onWheel(event: WheelEvent): void {
        if (!options.zoomable()) {
            return;
        }

        event.preventDefault();
        zoomTo(scale.value * Math.exp(-event.deltaY * 0.002), {
            x: event.clientX,
            y: event.clientY,
        });
    }

    return {
        scale,
        offsetX,
        offsetY,
        dragX,
        dragY,
        gesturing,
        zoomed,
        canZoomIn,
        canZoomOut,
        dismissProgress,
        reset,
        zoomTo,
        zoomIn,
        zoomOut,
        toggleZoom,
        onPointerDown,
        onPointerMove,
        onPointerUp,
        onPointerCancel,
        onWheel,
    };
}
