import {
    CalendarDate,
    CalendarDateTime,
    parseAbsolute,
    today as zonedToday,
    toZoned,
} from '@internationalized/date';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import {
    dateKeyOf,
    dayOfWeekOf,
    eventsCoveringDay,
    prepareEvents,
} from '@/composables/kinetixCalendarDates';
import type { PreparedEvent } from '@/composables/kinetixCalendarDates';
import type {
    KinetixCalendarEvent,
    KinetixCalendarView,
} from '@/types/kinetix';

export interface MonthDay {
    date: string;
    day: number;
    inMonth: boolean;
    isToday: boolean;
    events: KinetixCalendarEvent[];
}

export interface TimedEvent {
    event: KinetixCalendarEvent;
    topPct: number;
    heightPct: number;
}

export interface DayColumn {
    key: string;
    date: CalendarDate;
    label: string;
    isToday: boolean;
    allDayEvents: KinetixCalendarEvent[];
    timedEvents: TimedEvent[];
}

export interface UseKinetixCalendarGridsOptions {
    anchor: () => CalendarDate;
    activeView: () => KinetixCalendarView;
    events: () => KinetixCalendarEvent[];
    tz: () => string;
    locale: () => string | undefined;
    weekStartsOn: () => number;
    startHour: () => number;
    endHour: () => number;
    todayKey: () => string;
}

export interface UseKinetixCalendarGrids {
    monthGrid: ComputedRef<MonthDay[]>;
    hours: ComputedRef<number[]>;
    totalMinutes: ComputedRef<number>;
    gridContentHeight: ComputedRef<string>;
    dayColumns: ComputedRef<DayColumn[]>;
    nowIndicator: ComputedRef<{ key: string; topPct: number } | null>;
    formatHourLabel: (hour: number) => string;
    slotInstant: (date: CalendarDate, hour: number) => string;
}

/**
 * All grid geometry for the event calendar: the 6-week month grid and the
 * hourly week/day columns with absolute-positioned timed events. Events are
 * resolved into the effective timezone once (`prepareEvents`) and shared across
 * both grids.
 */
export function useKinetixCalendarGrids(
    options: UseKinetixCalendarGridsOptions,
): UseKinetixCalendarGrids {
    const preparedEvents = computed<PreparedEvent[]>(() =>
        prepareEvents(options.events(), options.tz()),
    );

    // ===== Month view =====
    const monthGrid = computed<MonthDay[]>(() => {
        const anchor = options.anchor();
        const first = new CalendarDate(anchor.year, anchor.month, 1);
        const offset = (dayOfWeekOf(first) - options.weekStartsOn() + 7) % 7;
        const start = first.subtract({ days: offset });
        const today = options.todayKey();

        return Array.from({ length: 42 }, (_, i) => {
            const d = start.add({ days: i });
            const key = dateKeyOf(d);

            return {
                date: key,
                day: d.day,
                inMonth: d.month === anchor.month,
                isToday: key === today,
                events: eventsCoveringDay(preparedEvents.value, key).map(
                    (p) => p.event,
                ),
            };
        });
    });

    // ===== Week / day view (hourly grid) =====
    const visibleDays = computed<CalendarDate[]>(() => {
        const anchor = options.anchor();

        if (options.activeView() === 'day') {
            return [anchor];
        }

        const offset = (dayOfWeekOf(anchor) - options.weekStartsOn() + 7) % 7;
        const start = anchor.subtract({ days: offset });

        return Array.from({ length: 7 }, (_, i) => start.add({ days: i }));
    });

    const hours = computed<number[]>(() =>
        Array.from(
            { length: options.endHour() - options.startHour() },
            (_, i) => options.startHour() + i,
        ),
    );

    const totalMinutes = computed(
        () => (options.endHour() - options.startHour()) * 60,
    );

    // Explicit content height (matching h-16 = 4rem per hour row) so absolutely
    // positioned events resolve their top/height percentages against the full
    // grid height rather than the collapsed visible height.
    const gridContentHeight = computed(() => `${hours.value.length * 4}rem`);

    // A fixed UTC reference — these are abstract "hour N of the effective
    // timezone" labels, not tied to the viewing browser's own system timezone.
    const formatHourLabel = (hour: number): string =>
        new Intl.DateTimeFormat(options.locale(), {
            hour: 'numeric',
            timeZone: 'UTC',
        }).format(new Date(Date.UTC(2024, 0, 1, hour)));

    const dayLabelFmt = computed(
        () =>
            new Intl.DateTimeFormat(options.locale(), {
                weekday: 'short',
                day: 'numeric',
                timeZone: 'UTC',
            }),
    );

    const dayColumns = computed<DayColumn[]>(() => {
        const startHour = options.startHour();
        const endHour = options.endHour();
        const total = totalMinutes.value;
        const spanCount = hours.value.length;

        return visibleDays.value.map((d) => {
            const key = dateKeyOf(d);
            const dayEvents = eventsCoveringDay(preparedEvents.value, key);
            const allDayEvents = dayEvents
                .filter((p) => p.event.allDay)
                .map((p) => p.event);

            const timedEvents = dayEvents
                .filter((p) => !p.event.allDay)
                .map((p) => {
                    const s = p.start;
                    const e = p.end;
                    const sameDayStart = p.startKey === key;
                    const startMin = sameDayStart
                        ? s.hour * 60 + s.minute
                        : startHour * 60;
                    const endMin = e
                        ? p.endKey === key
                            ? e.hour * 60 + e.minute
                            : (startHour + spanCount) * 60
                        : startMin + 60;

                    const clampedStart = Math.max(startMin, startHour * 60);
                    const clampedEnd = Math.min(
                        Math.max(endMin, clampedStart + 15),
                        endHour * 60,
                    );

                    return {
                        event: p.event,
                        topPct: ((clampedStart - startHour * 60) / total) * 100,
                        heightPct: ((clampedEnd - clampedStart) / total) * 100,
                    };
                });

            return {
                key,
                date: d,
                label: dayLabelFmt.value.format(
                    new Date(Date.UTC(d.year, d.month - 1, d.day)),
                ),
                isToday: key === options.todayKey(),
                allDayEvents,
                timedEvents,
            };
        });
    });

    const nowIndicator = computed<{ key: string; topPct: number } | null>(
        () => {
            const key = dateKeyOf(zonedToday(options.tz()));
            const nowZoned = parseAbsolute(
                new Date().toISOString(),
                options.tz(),
            );
            const minutes = nowZoned.hour * 60 + nowZoned.minute;

            if (
                minutes < options.startHour() * 60 ||
                minutes >= options.endHour() * 60
            ) {
                return null;
            }

            return {
                key,
                topPct:
                    ((minutes - options.startHour() * 60) /
                        totalMinutes.value) *
                    100,
            };
        },
    );

    const slotInstant = (date: CalendarDate, hour: number): string => {
        const cdt = new CalendarDateTime(
            date.year,
            date.month,
            date.day,
            hour,
            0,
        );

        return toZoned(cdt, options.tz()).toDate().toISOString();
    };

    return {
        monthGrid,
        hours,
        totalMinutes,
        gridContentHeight,
        dayColumns,
        nowIndicator,
        formatHourLabel,
        slotInstant,
    };
}
