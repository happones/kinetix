import { today as zonedToday } from '@internationalized/date';
import type { CalendarDate } from '@internationalized/date';
import { computed, shallowRef } from 'vue';
import type { ComputedRef, ShallowRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { dateKeyOf, parseAnchorDate } from '@/composables/kinetixCalendarDates';
import type { KinetixCalendarView } from '@/types/kinetix';

export interface UseKinetixCalendarNavigationOptions {
    weekStartsOn: () => number;
    locale: () => string | undefined;
    tz: () => string;
    views: () => KinetixCalendarView[];
    initialView: KinetixCalendarView;
    anchorDate: () => string | null;
    /** Notified whenever the active view changes (drives `update:view`). */
    onViewChange: (view: KinetixCalendarView) => void;
}

export interface UseKinetixCalendarNavigation {
    activeView: ShallowRef<KinetixCalendarView>;
    anchor: ShallowRef<CalendarDate>;
    todayKey: ComputedRef<string>;
    monthLabel: ComputedRef<string>;
    weekdays: ComputedRef<string[]>;
    prevAriaLabel: ComputedRef<string>;
    nextAriaLabel: ComputedRef<string>;
    setActiveView: (view: KinetixCalendarView) => void;
    shiftAnchor: (delta: number) => void;
    goToToday: () => void;
}

/**
 * Anchor + active-view state and the derived header labels for the event
 * calendar. Navigation shifts the anchor by the active view's granularity
 * (month/week/day). Timezone-sensitive side effects (scroll-to-now) stay in the
 * component, which reacts to the state this composable owns.
 */
export function useKinetixCalendarNavigation(
    options: UseKinetixCalendarNavigationOptions,
): UseKinetixCalendarNavigation {
    const { t } = useI18n();

    // shallowRef: CalendarDate is immutable and uses real private class fields,
    // which Vue's deep-ref unwrapping mangles.
    const activeView = shallowRef<KinetixCalendarView>(options.initialView);

    const anchor = shallowRef<CalendarDate>(
        (options.anchorDate()
            ? parseAnchorDate(options.anchorDate() as string)
            : null) ?? zonedToday(options.tz()),
    );

    const todayKey = computed(() => dateKeyOf(zonedToday(options.tz())));

    const monthLabel = computed(() =>
        new Intl.DateTimeFormat(options.locale(), {
            month: 'long',
            year: 'numeric',
            timeZone: options.tz(),
        }).format(anchor.value.toDate(options.tz())),
    );

    const weekdays = computed(() => {
        const fmt = new Intl.DateTimeFormat(options.locale(), {
            weekday: 'short',
        });

        // 2024-01-07 is a Sunday — build labels from the configured start day.
        return Array.from({ length: 7 }, (_, i) =>
            fmt.format(
                new Date(2024, 0, 7 + ((options.weekStartsOn() + i) % 7)),
            ),
        );
    });

    const prevAriaLabel = computed(() =>
        t(
            activeView.value === 'month'
                ? 'kinetix.calendar_prev'
                : activeView.value === 'week'
                  ? 'kinetix.calendar_prev_week'
                  : 'kinetix.calendar_prev_day',
        ),
    );
    const nextAriaLabel = computed(() =>
        t(
            activeView.value === 'month'
                ? 'kinetix.calendar_next'
                : activeView.value === 'week'
                  ? 'kinetix.calendar_next_week'
                  : 'kinetix.calendar_next_day',
        ),
    );

    const setActiveView = (view: KinetixCalendarView): void => {
        activeView.value = view;
        options.onViewChange(view);
    };

    const shiftAnchor = (delta: number): void => {
        if (activeView.value === 'month') {
            anchor.value = anchor.value.add({ months: delta });
        } else if (activeView.value === 'week') {
            anchor.value = anchor.value.add({ days: 7 * delta });
        } else {
            anchor.value = anchor.value.add({ days: delta });
        }
    };

    const goToToday = (): void => {
        anchor.value = zonedToday(options.tz());
    };

    return {
        activeView,
        anchor,
        todayKey,
        monthLabel,
        weekdays,
        prevAriaLabel,
        nextAriaLabel,
        setActiveView,
        shiftAnchor,
        goToToday,
    };
}
