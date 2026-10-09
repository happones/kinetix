import { router, usePage } from '@inertiajs/vue3';
import {
    CalendarDateTime,
    parseAbsolute,
    toZoned,
} from '@internationalized/date';
import type { CalendarDate } from '@internationalized/date';
import { computed, nextTick, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import {
    formatEventInstant,
    parseAnchorDate,
} from '@/composables/kinetixCalendarDates';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { useKinetixTouchDrag } from '@/composables/useKinetixTouchDrag';
import type {
    KinetixCalendarData,
    KinetixCalendarEvent,
    KinetixCalendarView,
    KinetixSharedProps,
} from '@/types/kinetix';

export interface UseKinetixCalendarEventMoveOptions {
    calendar: () => KinetixCalendarData;
    tz: () => string;
    locale: () => string | undefined;
    activeView: () => KinetixCalendarView;
    /** Container auto-scrolled horizontally during touch drags (week grid). */
    scrollContainer: () => HTMLElement | null;
    onMoved?: (event: KinetixCalendarEvent, newStart: string) => void;
    /**
     * Inertia prop names to refresh after a move. When set, the post-move
     * resync is a PARTIAL reload (`router.reload({ only })`) instead of a full
     * one. Omit to keep the safe full reload.
     */
    reloadOnly?: () => string[] | undefined;
}

/** The instants a calendar write changes on an event. */
export type KinetixCalendarEventPatch = Partial<
    Pick<KinetixCalendarEvent, 'start' | 'end'>
>;

/** Where a calendar write goes and what it says on failure. */
export interface KinetixCalendarEventWrite {
    endpoint: 'calendar-move' | 'calendar-resize';
    body: Record<string, string>;
    failMessage: string;
}

export interface UseKinetixCalendarEventMove {
    /** Optimistic copy of the events the grids render from. */
    localEvents: ComputedRef<KinetixCalendarEvent[]>;
    /** True when the calendar opted into moves (`Calendar::moveable()`). */
    canMove: ComputedRef<boolean>;
    /**
     * Show `event` with a patch over it in the local copy, or put it back as
     * it was (`null`). For previews while a gesture is in flight.
     */
    patchEvent: (
        event: KinetixCalendarEvent,
        patch: KinetixCalendarEventPatch | null,
    ) => void;
    /**
     * Persist a change to one event: applied optimistically, put back with an
     * error toast when the server refuses, followed by a reload on success.
     * Resolves to whether it stuck.
     */
    saveEvent: (
        event: KinetixCalendarEvent,
        patch: KinetixCalendarEventPatch,
        write: KinetixCalendarEventWrite,
    ) => Promise<boolean>;
    /** The event in flight — labels the drop-preview ghost. */
    draggingEvent: Ref<KinetixCalendarEvent | null>;
    /** Id of the event in flight — dims its chips. */
    draggingEventId: ComputedRef<string | number | null>;
    /** The hovered drop key (`day:Y-MM-DD` / `slot:Y-MM-DD:H`), for highlights. */
    dropTarget: Ref<string | null>;
    onEventDragStart: (event: KinetixCalendarEvent) => void;
    onEventDragEnd: () => void;
    onDropKeyOver: (key: string) => void;
    onDropKeyDrop: (key: string) => void;
    onEventPointerDown: (
        event: KinetixCalendarEvent,
        pointerEvent: PointerEvent,
    ) => void;
    onEventKeydown: (
        event: KinetixCalendarEvent,
        keyboardEvent: KeyboardEvent,
    ) => void;
    slotDropKey: (dateKey: string, hour: number) => string;
    dayDropKey: (dateKey: string) => string;
}

/**
 * Drag-and-drop rescheduling for the event calendar, mirroring the Kanban
 * board's move pipeline: optimistic update, signed-descriptor POST, revert +
 * toast on failure, `router.reload()` on success. Desktop uses native HTML5
 * drag events; touch devices go through the shared long-press fallback; and
 * Alt+arrow keys are the keyboard alternative (day left/right, week up/down
 * in month view; day left/right, hour up/down in the time grids).
 */
export function useKinetixCalendarEventMove(
    options: UseKinetixCalendarEventMoveOptions,
): UseKinetixCalendarEventMove {
    const { t } = useI18n();
    const page = usePage<KinetixSharedProps>();
    const { announce } = useKinetixAnnounce();

    // Local, mutable copy of the events so drags update the UI immediately.
    const events = ref<KinetixCalendarEvent[]>([...options.calendar().events]);

    watch(
        () => options.calendar().events,
        (fresh) => {
            events.value = [...fresh];
        },
    );

    const localEvents = computed(() => events.value);
    // A descriptor from before `moveable` was sent only came with moves.
    const canMove = computed(
        () =>
            Boolean(options.calendar().model) &&
            options.calendar().moveable !== false,
    );

    const draggingEvent = ref<KinetixCalendarEvent | null>(null);
    const draggingEventId = computed(() => draggingEvent.value?.id ?? null);
    const dropTarget = ref<string | null>(null);

    const dayDropKey = (dateKey: string): string => `day:${dateKey}`;
    const slotDropKey = (dateKey: string, hour: number): string =>
        `slot:${dateKey}:${hour}`;

    /** The absolute instant a drop key resolves to for the given event. */
    const resolveDropInstant = (
        key: string,
        event: KinetixCalendarEvent,
    ): string | null => {
        const [kind, dateKey, hour] = key.split(':');
        const date = dateKey ? parseAnchorDate(dateKey) : null;

        if (!date) {
            return null;
        }

        if (kind === 'slot') {
            return slotInstant(date, Number(hour ?? 0));
        }

        // Day drop: keep the event's time-of-day in the effective timezone.
        const start = parseAbsolute(event.start, options.tz());

        return toZoned(
            new CalendarDateTime(
                date.year,
                date.month,
                date.day,
                start.hour,
                start.minute,
                start.second,
            ),
            options.tz(),
        )
            .toDate()
            .toISOString();
    };

    const slotInstant = (date: CalendarDate, hour: number): string =>
        toZoned(
            new CalendarDateTime(date.year, date.month, date.day, hour, 0),
            options.tz(),
        )
            .toDate()
            .toISOString();

    const patchEvent = (
        event: KinetixCalendarEvent,
        patch: KinetixCalendarEventPatch | null,
    ): void => {
        events.value = events.value.map((e) =>
            e.id === event.id ? (patch ? { ...event, ...patch } : event) : e,
        );
    };

    async function saveEvent(
        event: KinetixCalendarEvent,
        patch: KinetixCalendarEventPatch,
        write: KinetixCalendarEventWrite,
    ): Promise<boolean> {
        if (!options.calendar().model) {
            return false;
        }

        patchEvent(event, patch);

        try {
            await kinetixFetch(
                `/${kinetixRoutePrefix(page)}/tables/${write.endpoint}`,
                {
                    method: 'POST',
                    body: {
                        model: options.calendar().model,
                        recordId: event.id,
                        ...write.body,
                    },
                },
            );
            const only = options.reloadOnly?.();
            router.reload(only?.length ? { only } : {});

            return true;
        } catch {
            patchEvent(event, null);
            toast.error(write.failMessage);

            return false;
        }
    }

    /**
     * Persist a move. The end shifts by the same delta client-side so
     * multi-day/timed spans keep their duration while the reload is in
     * flight.
     */
    async function moveEvent(
        event: KinetixCalendarEvent,
        newStart: string,
    ): Promise<boolean> {
        const deltaMs =
            new Date(newStart).getTime() - new Date(event.start).getTime();

        if (deltaMs === 0 || !canMove.value) {
            return false;
        }

        const moved = await saveEvent(
            event,
            {
                start: newStart,
                end: event.end
                    ? new Date(
                          new Date(event.end).getTime() + deltaMs,
                      ).toISOString()
                    : null,
            },
            {
                endpoint: 'calendar-move',
                body: { start: newStart },
                failMessage: t('kinetix.calendar_move_failed'),
            },
        );

        if (moved) {
            options.onMoved?.(event, newStart);
        }

        return moved;
    }

    // --- Native HTML5 drag (mouse) -------------------------------------------
    const onEventDragStart = (event: KinetixCalendarEvent): void => {
        draggingEvent.value = event;
    };

    const onEventDragEnd = (): void => {
        draggingEvent.value = null;
        dropTarget.value = null;
    };

    const onDropKeyOver = (key: string): void => {
        dropTarget.value = key;
    };

    const onDropKeyDrop = (key: string): void => {
        const event = draggingEvent.value;
        onEventDragEnd();

        if (!event) {
            return;
        }

        const newStart = resolveDropInstant(key, event);

        if (newStart) {
            moveEvent(event, newStart);
        }
    };

    // --- Touch drag (long-press) ----------------------------------------------
    const touchDrag = useKinetixTouchDrag<KinetixCalendarEvent>({
        targetAttr: 'data-calendar-drop',
        scrollContainer: () => options.scrollContainer(),
        onStart: (event) => {
            draggingEvent.value = event;
        },
        onHover: (key) => {
            dropTarget.value = key;
        },
        onDrop: (event, key) => {
            draggingEvent.value = null;
            dropTarget.value = null;

            const newStart = key ? resolveDropInstant(key, event) : null;

            if (newStart) {
                moveEvent(event, newStart);
            }
        },
    });

    const onEventPointerDown = (
        event: KinetixCalendarEvent,
        pointerEvent: PointerEvent,
    ): void => {
        if (!canMove.value) {
            return;
        }

        touchDrag.startFromPointerDown(
            pointerEvent,
            pointerEvent.currentTarget as HTMLElement,
            event,
        );
    };

    // --- Keyboard alternative (Alt + arrows) -----------------------------------
    const onEventKeydown = (
        event: KinetixCalendarEvent,
        keyboardEvent: KeyboardEvent,
    ): void => {
        if (!canMove.value || !keyboardEvent.altKey) {
            return;
        }

        const inMonth = options.activeView() === 'month';
        const byKey: Record<string, { days?: number; hours?: number }> = {
            ArrowLeft: { days: -1 },
            ArrowRight: { days: 1 },
            ArrowUp: inMonth ? { days: -7 } : { hours: -1 },
            ArrowDown: inMonth ? { days: 7 } : { hours: 1 },
        };
        const delta = byKey[keyboardEvent.key];

        if (!delta) {
            return;
        }

        // All-day events have no meaningful time-of-day to nudge by the hour.
        if (delta.hours && event.allDay) {
            delta.days = delta.hours > 0 ? 1 : -1;
            delta.hours = 0;
        }

        keyboardEvent.preventDefault();

        const newStart = parseAbsolute(event.start, options.tz())
            .add({ days: delta.days ?? 0, hours: delta.hours ?? 0 })
            .toDate()
            .toISOString();

        moveEvent(event, newStart).then((moved) => {
            if (!moved) {
                return;
            }

            announce(
                t('kinetix.calendar_moved_to', {
                    date: formatEventInstant(
                        newStart,
                        event.allDay,
                        options.locale(),
                        options.tz(),
                    ),
                }),
            );

            // The chip re-renders in its new cell; put focus back on it.
            nextTick(() => {
                document
                    .querySelector<HTMLElement>(
                        `[data-calendar-event="${event.id}"]`,
                    )
                    ?.focus();
            });
        });
    };

    return {
        localEvents,
        canMove,
        patchEvent,
        saveEvent,
        draggingEvent,
        draggingEventId,
        dropTarget,
        onEventDragStart,
        onEventDragEnd,
        onDropKeyOver,
        onDropKeyDrop,
        onEventPointerDown,
        onEventKeydown,
        slotDropKey,
        dayDropKey,
    };
}
