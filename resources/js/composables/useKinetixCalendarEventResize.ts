import type { CalendarDate, ZonedDateTime } from '@internationalized/date';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    effectiveEnd,
    formatEventInstant,
    gridInstant,
    parseAnchorDate,
    RESIZE_STEP_MINUTES,
    resizedEnd,
} from '@/composables/kinetixCalendarDates';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import type {
    KinetixCalendarData,
    KinetixCalendarEvent,
    KinetixCalendarView,
} from '@/types/kinetix';

/** How a resize reads the pointer: down a day's hour grid, or across day cells. */
export type KinetixCalendarResizeAxis = 'time' | 'day';

export interface UseKinetixCalendarEventResizeOptions {
    calendar: () => KinetixCalendarData;
    tz: () => string;
    locale: () => string | undefined;
    activeView: () => KinetixCalendarView;
    startHour: () => number;
    endHour: () => number;
    /** The hour grid's scroller, auto-scrolled while an end is dragged near its top or bottom. */
    hourlyGrid: () => HTMLElement | null;
    /** Show a provisional end while dragging; null puts the event back as it was. */
    preview: (event: KinetixCalendarEvent, end: string | null) => void;
    /** Persist the new end (optimistic, reverting on error); resolves to whether it stuck. */
    save: (event: KinetixCalendarEvent, end: string) => Promise<boolean>;
    onResized?: (event: KinetixCalendarEvent, newEnd: string) => void;
}

export interface UseKinetixCalendarEventResize {
    /** True when the calendar opted into resizing (`Calendar::resizable()`). */
    canResize: ComputedRef<boolean>;
    /** Id of the event whose end is being dragged. */
    resizingEventId: Ref<string | number | null>;
    /**
     * Wire to pointerdown on an event's end handle, with the day (`Y-MM-DD`)
     * the handle sits on: `time` drags down that day's hour grid, `day` across
     * day cells.
     */
    onResizePointerDown: (
        event: KinetixCalendarEvent,
        axis: KinetixCalendarResizeAxis,
        dayKey: string,
        pointerEvent: PointerEvent,
    ) => void;
    /** Alt+Shift+arrows on a focused event; true when it took the key. */
    onResizeKeydown: (
        event: KinetixCalendarEvent,
        keyboardEvent: KeyboardEvent,
    ) => boolean;
}

const EDGE_SCROLL_ZONE_PX = 32;
const EDGE_SCROLL_STEP_PX = 8;

/** Which way each arrow key moves the end: earlier (-1) or later (1). */
const ARROW_SIGNS: Record<string, number> = {
    ArrowLeft: -1,
    ArrowRight: 1,
    ArrowUp: -1,
    ArrowDown: 1,
};

interface ResizeSession {
    /** The event as it was when the drag began. */
    event: KinetixCalendarEvent;
    axis: KinetixCalendarResizeAxis;
    /** The day the handle sits on: the event's last day. */
    day: CalendarDate;
    /** The day's hour-grid column (time axis). */
    column: HTMLElement | null;
    /** The end shown so far; null while it's where the event already ends. */
    end: string | null;
    x: number;
    y: number;
    frame: number | null;
    cursor: string;
}

/**
 * Resizing events by their end edge: dragging the bottom of a timed event down
 * its hour grid (snapped to 15 minutes, the grid scrolling near its edges), or
 * the end of a day chip across day cells. The event grows and shrinks in place
 * while dragging; letting go saves the new end, Escape puts it back. Mouse,
 * touch and pen all drag the handle directly — it's a dedicated grip, so no
 * long-press. Alt+Shift+arrows are the keyboard alternative (left/right = a
 * day; up/down = a week in month view, 15 minutes in the hour grids).
 */
export function useKinetixCalendarEventResize(
    options: UseKinetixCalendarEventResizeOptions,
): UseKinetixCalendarEventResize {
    const { t } = useI18n();
    const { announce } = useKinetixAnnounce();

    const canResize = computed(() =>
        Boolean(options.calendar().model && options.calendar().resizable),
    );
    const resizingEventId = ref<string | number | null>(null);

    let session: ResizeSession | null = null;

    /**
     * The end under the pointer (null: where the event already ends), or
     * undefined while the pointer is off the grid/cells — the last end stands.
     */
    const endUnderPointer = (s: ResizeSession): string | null | undefined => {
        const tz = options.tz();

        if (s.axis === 'time') {
            const rect = s.column?.getBoundingClientRect();

            if (!rect || rect.height <= 0) {
                return undefined;
            }

            return resizedEnd(
                s.event,
                gridInstant(
                    s.day,
                    (s.y - rect.top) / rect.height,
                    options.startHour(),
                    options.endHour(),
                    tz,
                ),
                tz,
            );
        }

        const key = document
            .elementFromPoint(s.x, s.y)
            ?.closest('[data-calendar-drop^="day:"]')
            ?.getAttribute('data-calendar-drop');
        const target = key ? parseAnchorDate(key.slice('day:'.length)) : null;

        if (!target) {
            return undefined;
        }

        // The end moves by as many days as the pointer is from the handle's
        // day, keeping its time of day.
        return resizedEnd(
            s.event,
            effectiveEnd(s.event, tz).add({ days: target.compare(s.day) }),
            tz,
        );
    };

    const update = (): void => {
        if (!session) {
            return;
        }

        const end = endUnderPointer(session);

        if (end !== undefined && end !== session.end) {
            session.end = end;
            options.preview(session.event, end);
        }
    };

    /** Keep scrolling the hour grid while the pointer holds near its top or bottom. */
    const edgeScroll = (): void => {
        if (!session) {
            return;
        }

        const grid = options.hourlyGrid();

        if (grid) {
            const rect = grid.getBoundingClientRect();
            const before = grid.scrollTop;

            if (session.y < rect.top + EDGE_SCROLL_ZONE_PX) {
                grid.scrollTop -= EDGE_SCROLL_STEP_PX;
            } else if (session.y > rect.bottom - EDGE_SCROLL_ZONE_PX) {
                grid.scrollTop += EDGE_SCROLL_STEP_PX;
            }

            if (grid.scrollTop !== before) {
                update();
            }
        }

        session.frame = requestAnimationFrame(edgeScroll);
    };

    const onPointerMove = (event: PointerEvent): void => {
        if (session) {
            session.x = event.clientX;
            session.y = event.clientY;
            update();
        }
    };

    /** End the gesture, leaving the event as it's shown. */
    const finish = (): ResizeSession | null => {
        const ended = session;

        if (!ended) {
            return null;
        }

        if (ended.frame !== null) {
            cancelAnimationFrame(ended.frame);
        }

        document.documentElement.style.cursor = ended.cursor;
        session = null;
        resizingEventId.value = null;

        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', cancel);
        window.removeEventListener('keydown', onEscape, true);

        return ended;
    };

    /** End the gesture and put the event back as it was. */
    const cancel = (): void => {
        const ended = finish();

        if (ended && ended.end !== null) {
            options.preview(ended.event, null);
        }
    };

    const onEscape = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            cancel();
        }
    };

    /**
     * The click that follows letting go must not open the event or reach a
     * day cell. It comes in the same task as the release, so the guard is
     * dropped right after and a later click isn't eaten.
     */
    const swallowNextClick = (): void => {
        const swallow = (event: Event): void => {
            event.preventDefault();
            event.stopPropagation();
        };

        window.addEventListener('click', swallow, {
            capture: true,
            once: true,
        });
        setTimeout(() => window.removeEventListener('click', swallow, true));
    };

    const onPointerUp = (): void => {
        const ended = finish();

        if (!ended || ended.end === null) {
            return;
        }

        const end = ended.end;
        swallowNextClick();

        options.save(ended.event, end).then((saved) => {
            if (saved) {
                options.onResized?.(ended.event, end);
            }
        });
    };

    const onResizePointerDown = (
        event: KinetixCalendarEvent,
        axis: KinetixCalendarResizeAxis,
        dayKey: string,
        pointerEvent: PointerEvent,
    ): void => {
        const day = parseAnchorDate(dayKey);

        if (
            !canResize.value ||
            !day ||
            (pointerEvent.pointerType === 'mouse' && pointerEvent.button !== 0)
        ) {
            return;
        }

        // The handle sits inside the event: neither the event's own touch
        // long-press nor text selection starts from it.
        pointerEvent.preventDefault();
        pointerEvent.stopPropagation();
        cancel();

        const handle = pointerEvent.currentTarget as HTMLElement | null;

        session = {
            event,
            axis,
            day,
            column:
                axis === 'time'
                    ? (handle?.closest<HTMLElement>('[data-calendar-column]') ??
                      null)
                    : null,
            end: null,
            x: pointerEvent.clientX,
            y: pointerEvent.clientY,
            frame: null,
            cursor: document.documentElement.style.cursor,
        };
        document.documentElement.style.cursor =
            axis === 'time' ? 'ns-resize' : 'ew-resize';
        resizingEventId.value = event.id;

        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', cancel);
        window.addEventListener('keydown', onEscape, true);

        if (axis === 'time') {
            session.frame = requestAnimationFrame(edgeScroll);
        }
    };

    // --- Keyboard alternative (Alt + Shift + arrows) ---------------------------
    const onResizeKeydown = (
        event: KinetixCalendarEvent,
        keyboardEvent: KeyboardEvent,
    ): boolean => {
        const sign: number | undefined = ARROW_SIGNS[keyboardEvent.key];

        if (
            !canResize.value ||
            !keyboardEvent.altKey ||
            !keyboardEvent.shiftKey ||
            sign === undefined
        ) {
            return false;
        }

        keyboardEvent.preventDefault();

        const tz = options.tz();
        const current = effectiveEnd(event, tz);
        const across =
            keyboardEvent.key === 'ArrowLeft' ||
            keyboardEvent.key === 'ArrowRight';

        // Up/down step a week in the month grid and 15 minutes in the hour
        // grids — a day for an all-day event, which has no hours.
        let candidate: ZonedDateTime;

        if (across) {
            candidate = current.add({ days: sign });
        } else if (options.activeView() === 'month') {
            candidate = current.add({ days: 7 * sign });
        } else if (event.allDay) {
            candidate = current.add({ days: sign });
        } else {
            candidate = current.add({ minutes: RESIZE_STEP_MINUTES * sign });
        }

        const end = resizedEnd(event, candidate, tz);

        if (end === null) {
            return true;
        }

        const chip =
            keyboardEvent.currentTarget instanceof HTMLElement
                ? keyboardEvent.currentTarget
                : null;

        options.save(event, end).then((saved) => {
            if (!saved) {
                return;
            }

            announce(
                t('kinetix.calendar_resized_to', {
                    date: formatEventInstant(
                        end,
                        event.allDay,
                        options.locale(),
                        tz,
                    ),
                }),
            );
            options.onResized?.(event, end);

            // Shortening can drop the chip that had focus (a day the event
            // no longer covers); put focus back on the event.
            nextTick(() => {
                if (!chip?.isConnected) {
                    document
                        .querySelector<HTMLElement>(
                            `[data-calendar-event="${event.id}"]`,
                        )
                        ?.focus();
                }
            });
        });

        return true;
    };

    onBeforeUnmount(cancel);

    return {
        canResize,
        resizingEventId,
        onResizePointerDown,
        onResizeKeydown,
    };
}
