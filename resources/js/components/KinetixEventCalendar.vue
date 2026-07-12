<script setup lang="ts">
import {
    CalendarDate,
    CalendarDateTime,
    getLocalTimeZone,
    parseAbsolute,
    today as zonedToday,
    toZoned,
} from '@internationalized/date';
import type { ZonedDateTime } from '@internationalized/date';
import { ChevronLeft, ChevronRight, X } from '@lucide/vue';
import { computed, onMounted, ref, shallowRef } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type {
    KinetixCalendarData,
    KinetixCalendarEvent,
    KinetixCalendarEventDisplay,
    KinetixCalendarView,
    KinetixSheetSide,
} from '@/types';
import KinetixSheet from './KinetixSheet.vue';
import { cn } from './primitives/cn';

/**
 * An event calendar (scheduler): month/week/day views over events from any
 * Eloquent model. Events are absolute-instant ISO datetimes — this component
 * re-renders them in `timezone` (or `calendar.timezone`, the server's
 * resolved default) via `@internationalized/date`, so placement is correct
 * regardless of the viewing browser's own local timezone.
 *
 * `views` opts into the switcher (default month-only, unchanged from before).
 * Clicking an event opens a built-in details modal/sheet (`eventDisplay`) —
 * set `showEventDetails="false"` to rely purely on `@event-click`.
 */
const props = withDefaults(
    defineProps<{
        calendar: KinetixCalendarData;
        /** First day of the week: 0=Sun … 6=Sat (default Monday). */
        weekStartsOn?: number;
        locale?: string | null;
        /** Overrides `calendar.timezone` (e.g. the viewer's own browser zone). */
        timezone?: string | null;
        /** Which views are available; a switcher shows once more than one. */
        views?: KinetixCalendarView[];
        /** Controlled active view (v-model:view). Defaults to `views[0]`. */
        view?: KinetixCalendarView;
        /**
         * Which month/week/day to show initially (ISO 'Y-MM-DD'), e.g. for
         * deep-linking to a specific date. Defaults to today.
         */
        anchorDate?: string | null;
        /** First visible hour in week/day views (0-23). */
        startHour?: number;
        /** Last visible hour, exclusive, in week/day views (1-24). */
        endHour?: number;
        /** How a clicked event's details are presented. */
        eventDisplay?: KinetixCalendarEventDisplay;
        /** Which edge the sheet slides from, when eventDisplay="sheet". */
        sheetSide?: KinetixSheetSide;
        /** Set false to suppress the built-in popup and rely on @event-click. */
        showEventDetails?: boolean;
    }>(),
    {
        weekStartsOn: 1,
        locale: null,
        timezone: null,
        views: () => ['month'],
        view: undefined,
        anchorDate: null,
        startHour: 0,
        endHour: 24,
        eventDisplay: 'modal',
        sheetSide: 'right',
        showEventDetails: true,
    },
);

const emit = defineEmits<{
    (e: 'event-click', event: KinetixCalendarEvent): void;
    (e: 'day-click', date: string): void;
    (e: 'slot-click', dateTime: string): void;
    (e: 'update:view', view: KinetixCalendarView): void;
}>();

const { t } = useI18n();
const locale = computed(() => props.locale ?? undefined);
const tz = computed(
    () => props.timezone || props.calendar.timezone || getLocalTimeZone(),
);

// Guards the modal's Teleport — no `document.body` during SSR.
const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

const activeView = ref<KinetixCalendarView>(props.view ?? props.views[0]);

function setView(v: KinetixCalendarView): void {
    activeView.value = v;
    emit('update:view', v);
}

// The anchor day driving every view's visible window; navigation shifts it by
// the active view's granularity (month/week/day).
function parseAnchorDate(value: string): CalendarDate | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    return match
        ? new CalendarDate(Number(match[1]), Number(match[2]), Number(match[3]))
        : null;
}

// shallowRef: CalendarDate is immutable (every add()/subtract() call returns a
// new instance) and uses real private class fields, which Vue's deep ref()
// unwrapping mangles into a structurally-similar-but-not-nominally-equal type.
const anchor = shallowRef<CalendarDate>(
    (props.anchorDate ? parseAnchorDate(props.anchorDate) : null) ??
        zonedToday(tz.value),
);

const pad = (n: number) => String(n).padStart(2, '0');
const dateKeyOf = (d: { year: number; month: number; day: number }): string =>
    `${d.year}-${pad(d.month)}-${pad(d.day)}`;

/** Parse an event's absolute ISO instant into the effective timezone. */
const zonedStart = (e: KinetixCalendarEvent): ZonedDateTime =>
    parseAbsolute(e.start, tz.value);
const zonedEnd = (e: KinetixCalendarEvent): ZonedDateTime | null =>
    e.end ? parseAbsolute(e.end, tz.value) : null;

const todayKey = computed(() => dateKeyOf(zonedToday(tz.value)));

/** Whether an event covers a given day (allDay/multi-day span, by date key). */
function coversDay(e: KinetixCalendarEvent, key: string): boolean {
    const startKey = dateKeyOf(zonedStart(e));
    const end = zonedEnd(e);
    const endKey = end ? dateKeyOf(end) : startKey;

    return key >= startKey && key <= endKey;
}

function shiftAnchor(delta: number): void {
    if (activeView.value === 'month') {
        anchor.value = anchor.value.add({ months: delta });
    } else if (activeView.value === 'week') {
        anchor.value = anchor.value.add({ days: 7 * delta });
    } else {
        anchor.value = anchor.value.add({ days: delta });
    }
}

function goToday(): void {
    anchor.value = zonedToday(tz.value);
}

const monthLabel = computed(() =>
    new Intl.DateTimeFormat(locale.value, {
        month: 'long',
        year: 'numeric',
        timeZone: tz.value,
    }).format(anchor.value.toDate(tz.value)),
);

const weekdays = computed(() => {
    const fmt = new Intl.DateTimeFormat(locale.value, { weekday: 'short' });

    // 2024-01-07 is a Sunday — build labels from the configured start day.
    return Array.from({ length: 7 }, (_, i) =>
        fmt.format(new Date(2024, 0, 7 + ((props.weekStartsOn + i) % 7))),
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

// ===== Month view =====

interface MonthDay {
    date: string;
    day: number;
    inMonth: boolean;
    isToday: boolean;
    events: KinetixCalendarEvent[];
}

const monthGrid = computed<MonthDay[]>(() => {
    const first = new CalendarDate(anchor.value.year, anchor.value.month, 1);
    const offset = (dayOfWeekOf(first) - props.weekStartsOn + 7) % 7;
    const start = first.subtract({ days: offset });

    return Array.from({ length: 42 }, (_, i) => {
        const d = start.add({ days: i });
        const key = dateKeyOf(d);

        return {
            date: key,
            day: d.day,
            inMonth: d.month === anchor.value.month,
            isToday: key === todayKey.value,
            events: props.calendar.events.filter((e) => coversDay(e, key)),
        };
    });
});

// Sunday-based (0-6) day-of-week, computed from the plain calendar date —
// independent of any timezone (a CalendarDate has no time component).
function dayOfWeekOf(d: CalendarDate): number {
    return new Date(Date.UTC(d.year, d.month - 1, d.day)).getUTCDay();
}

// ===== Week / day view (hourly grid) =====

const visibleDays = computed(() => {
    if (activeView.value === 'day') {
        return [anchor.value];
    }

    const offset = (dayOfWeekOf(anchor.value) - props.weekStartsOn + 7) % 7;
    const start = anchor.value.subtract({ days: offset });

    return Array.from({ length: 7 }, (_, i) => start.add({ days: i }));
});

const hours = computed(() =>
    Array.from(
        { length: props.endHour - props.startHour },
        (_, i) => props.startHour + i,
    ),
);
const totalMinutes = computed(() => (props.endHour - props.startHour) * 60);
// Explicit content height (matching h-16 = 4rem per hour row) for each day
// column: without it, the flex row's default `align-items: stretch` collapses
// each column's containing-block height to the *visible* (scrolled) height,
// so `top`/`height` percentages on absolutely-positioned events resolve
// against the wrong reference and compress every event into the first screen.
const gridContentHeight = computed(() => `${hours.value.length * 4}rem`);

// Formatted via a fixed UTC reference — these are abstract "hour N of the
// effective timezone" row labels, not tied to the *viewing browser's* own
// system timezone (which can differ from `tz`, e.g. a shared team calendar).
function formatHourLabel(hour: number): string {
    return new Intl.DateTimeFormat(locale.value, {
        hour: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(2024, 0, 1, hour)));
}

interface DayColumn {
    key: string;
    date: CalendarDate;
    label: string;
    isToday: boolean;
    allDayEvents: KinetixCalendarEvent[];
    timedEvents: Array<{
        event: KinetixCalendarEvent;
        topPct: number;
        heightPct: number;
    }>;
}

// `timeZone: 'UTC'` because the reference Date is built via Date.UTC() from
// plain Y/M/D parts (no time-of-day) — formatting it in the *browser's* local
// timezone could shift it to the adjacent day near midnight.
const dayLabelFmt = computed(
    () =>
        new Intl.DateTimeFormat(locale.value, {
            weekday: 'short',
            day: 'numeric',
            timeZone: 'UTC',
        }),
);

const dayColumns = computed<DayColumn[]>(() =>
    visibleDays.value.map((d) => {
        const key = dateKeyOf(d);
        const dayEvents = props.calendar.events.filter((e) =>
            coversDay(e, key),
        );
        const allDayEvents = dayEvents.filter((e) => e.allDay);
        const timedEvents = dayEvents
            .filter((e) => !e.allDay)
            .map((event) => {
                const s = zonedStart(event);
                const e = zonedEnd(event);
                const sameDayStart = dateKeyOf(s) === key;
                const startMin = sameDayStart
                    ? s.hour * 60 + s.minute
                    : props.startHour * 60;
                const endMin = e
                    ? dateKeyOf(e) === key
                        ? e.hour * 60 + e.minute
                        : (props.startHour + hours.value.length) * 60
                    : startMin + 60;

                const clampedStart = Math.max(startMin, props.startHour * 60);
                const clampedEnd = Math.min(
                    Math.max(endMin, clampedStart + 15),
                    props.endHour * 60,
                );

                return {
                    event,
                    topPct:
                        ((clampedStart - props.startHour * 60) /
                            totalMinutes.value) *
                        100,
                    heightPct:
                        ((clampedEnd - clampedStart) / totalMinutes.value) *
                        100,
                };
            });

        return {
            key,
            date: d,
            label: dayLabelFmt.value.format(
                new Date(Date.UTC(d.year, d.month - 1, d.day)),
            ),
            isToday: key === todayKey.value,
            allDayEvents,
            timedEvents,
        };
    }),
);

const nowIndicator = computed(() => {
    const n = zonedToday(tz.value);
    const key = dateKeyOf(n);
    const nowZoned = parseAbsolute(new Date().toISOString(), tz.value);
    const minutes = nowZoned.hour * 60 + nowZoned.minute;

    if (minutes < props.startHour * 60 || minutes >= props.endHour * 60) {
        return null;
    }

    return {
        key,
        topPct: ((minutes - props.startHour * 60) / totalMinutes.value) * 100,
    };
});

function onSlotClick(date: CalendarDate, hour: number): void {
    const cdt = new CalendarDateTime(date.year, date.month, date.day, hour, 0);
    const instant = toZoned(cdt, tz.value).toDate();
    emit('slot-click', instant.toISOString());
}

// ===== Event details (modal / sheet) =====

const selectedEvent = ref<KinetixCalendarEvent | null>(null);
const detailsOpen = ref(false);

function openEvent(event: KinetixCalendarEvent): void {
    emit('event-click', event);

    if (props.showEventDetails) {
        selectedEvent.value = event;
        detailsOpen.value = true;
    }
}

function closeDetails(): void {
    detailsOpen.value = false;
}

const dateFmt = computed(
    () =>
        new Intl.DateTimeFormat(locale.value, {
            dateStyle: 'long',
            timeZone: tz.value,
        }),
);
const timeFmt = computed(
    () =>
        new Intl.DateTimeFormat(locale.value, {
            timeStyle: 'short',
            timeZone: tz.value,
        }),
);

const eventRangeLabel = computed(() => {
    const event = selectedEvent.value;

    if (!event) {
        return '';
    }

    const start = zonedStart(event);
    const end = zonedEnd(event);
    const startDate = dateFmt.value.format(start.toDate());

    if (event.allDay) {
        if (!end || dateKeyOf(end) === dateKeyOf(start)) {
            return startDate;
        }

        return `${startDate} – ${dateFmt.value.format(end.toDate())}`;
    }

    const startTime = timeFmt.value.format(start.toDate());

    if (!end) {
        return `${startDate} · ${startTime}`;
    }

    return `${startDate} · ${startTime} – ${timeFmt.value.format(end.toDate())}`;
});
</script>

<template>
    <div class="space-y-4">
        <div class="gap-3 flex flex-wrap items-center justify-between">
            <h2 class="text-lg font-semibold text-foreground">
                {{ calendar.heading ?? monthLabel }}
            </h2>
            <div class="gap-2 flex flex-wrap items-center">
                <div
                    v-if="views.length > 1"
                    class="gap-1 rounded-lg p-1 inline-flex items-center border border-border bg-muted/40"
                    role="group"
                >
                    <button
                        v-for="v in views"
                        :key="v"
                        type="button"
                        class="px-2.5 py-1 text-sm font-medium rounded-md transition-colors"
                        :class="
                            activeView === v
                                ? 'shadow-sm bg-background text-foreground'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="setView(v)"
                    >
                        {{ t(`kinetix.calendar_view_${v}`) }}
                    </button>
                </div>

                <div class="gap-1 flex items-center">
                    <button
                        type="button"
                        :class="
                            buttonVariants({ variant: 'outline', size: 'sm' })
                        "
                        @click="goToday"
                    >
                        {{ t('kinetix.calendar_today') }}
                    </button>
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'outline',
                                size: 'icon-sm',
                            })
                        "
                        :aria-label="prevAriaLabel"
                        @click="shiftAnchor(-1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'outline',
                                size: 'icon-sm',
                            })
                        "
                        :aria-label="nextAriaLabel"
                        @click="shiftAnchor(1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="calendar.heading"
            class="text-sm font-medium text-muted-foreground"
        >
            {{ monthLabel }}
        </div>

        <!-- ===== Month view ===== -->
        <div
            v-if="activeView === 'month'"
            class="rounded-lg overflow-hidden border border-border"
        >
            <div class="grid grid-cols-7 border-b border-border bg-muted/40">
                <div
                    v-for="wd in weekdays"
                    :key="wd"
                    class="px-2 py-2 text-xs font-medium text-center text-muted-foreground"
                >
                    {{ wd }}
                </div>
            </div>

            <div class="grid grid-cols-7">
                <div
                    v-for="cell in monthGrid"
                    :key="cell.date"
                    class="min-h-24 p-1 border-r border-b border-border [&:nth-child(7n)]:border-r-0"
                    :class="cell.inMonth ? '' : 'bg-muted/20'"
                    @click="emit('day-click', cell.date)"
                >
                    <div
                        class="mb-1 h-6 w-6 text-xs flex items-center justify-center rounded-full"
                        :class="
                            cell.isToday
                                ? 'font-semibold bg-primary text-primary-foreground'
                                : cell.inMonth
                                  ? 'text-foreground'
                                  : 'text-muted-foreground'
                        "
                    >
                        {{ cell.day }}
                    </div>

                    <div class="space-y-1">
                        <button
                            v-for="event in cell.events.slice(0, 3)"
                            :key="String(event.id)"
                            type="button"
                            class="rounded px-1.5 py-0.5 text-xs text-white block w-full truncate text-left"
                            :style="{
                                backgroundColor: event.color ?? '#3b82f6',
                            }"
                            @click.stop="openEvent(event)"
                        >
                            {{ event.title }}
                        </button>
                        <p
                            v-if="cell.events.length > 3"
                            class="px-1 text-[11px] text-muted-foreground"
                        >
                            {{
                                t('kinetix.calendar_more', {
                                    count: cell.events.length - 3,
                                })
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== Week / day view (hourly grid) ===== -->
        <div v-else class="rounded-lg overflow-hidden border border-border">
            <div class="overflow-x-auto">
                <div class="min-w-[40rem]">
                    <!-- Day headers -->
                    <div
                        class="flex border-b border-border bg-muted/40"
                        :style="{
                            paddingLeft: activeView === 'week' ? '3.5rem' : '0',
                        }"
                    >
                        <div
                            v-for="col in dayColumns"
                            :key="col.key"
                            class="px-2 py-2 text-xs font-medium flex-1 text-center"
                            :class="
                                col.isToday
                                    ? 'text-primary'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ col.label }}
                        </div>
                    </div>

                    <!-- All-day banner -->
                    <div
                        v-if="dayColumns.some((c) => c.allDayEvents.length)"
                        class="py-1 flex border-b border-border"
                    >
                        <div
                            v-if="activeView === 'week'"
                            class="w-14 pr-2 pt-0.5 shrink-0 text-right text-[10px] text-muted-foreground"
                        >
                            {{ t('kinetix.calendar_all_day') }}
                        </div>
                        <div
                            v-for="col in dayColumns"
                            :key="`allday-${col.key}`"
                            class="min-w-0 space-y-0.5 px-1 flex-1"
                        >
                            <button
                                v-for="event in col.allDayEvents"
                                :key="String(event.id)"
                                type="button"
                                class="rounded px-1.5 py-0.5 text-xs text-white block w-full truncate text-left"
                                :style="{
                                    backgroundColor: event.color ?? '#3b82f6',
                                }"
                                @click.stop="openEvent(event)"
                            >
                                {{ event.title }}
                            </button>
                        </div>
                    </div>

                    <!-- Hourly grid -->
                    <div class="flex max-h-[36rem] overflow-y-auto">
                        <div
                            class="w-14 left-0 sticky z-10 shrink-0 bg-background"
                        >
                            <div
                                v-for="h in hours"
                                :key="h"
                                class="h-16 pr-2 text-right text-[11px] text-muted-foreground"
                            >
                                {{ formatHourLabel(h) }}
                            </div>
                        </div>

                        <div
                            v-for="col in dayColumns"
                            :key="col.key"
                            class="min-w-0 relative flex-1 border-l border-border"
                            :class="col.isToday ? 'bg-primary/5' : ''"
                            :style="{ height: gridContentHeight }"
                        >
                            <button
                                v-for="h in hours"
                                :key="h"
                                type="button"
                                class="h-16 block w-full border-b border-border/60 hover:bg-accent/40"
                                @click="onSlotClick(col.date, h)"
                            />

                            <div
                                v-if="
                                    nowIndicator && col.key === nowIndicator.key
                                "
                                class="left-0 right-0 absolute z-10 h-px bg-destructive"
                                :style="{ top: `${nowIndicator.topPct}%` }"
                            >
                                <span
                                    class="-left-1 size-1.5 absolute -top-[3px] rounded-full bg-destructive"
                                />
                            </div>

                            <button
                                v-for="{
                                    event,
                                    topPct,
                                    heightPct,
                                } in col.timedEvents"
                                :key="String(event.id)"
                                type="button"
                                class="left-0.5 right-0.5 px-1.5 py-0.5 text-white rounded absolute overflow-hidden text-left text-[11px]"
                                :style="{
                                    top: `${topPct}%`,
                                    height: `${Math.max(heightPct, 4)}%`,
                                    backgroundColor: event.color ?? '#3b82f6',
                                }"
                                @click.stop="openEvent(event)"
                            >
                                {{ event.title }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== Event details: modal ===== -->
        <Teleport v-if="isMounted && eventDisplay === 'modal'" to="body">
            <Transition
                enter-active-class="transition-opacity duration-150"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-150"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="detailsOpen && selectedEvent"
                    class="inset-0 p-4 fixed z-[100] flex items-center justify-center"
                    role="dialog"
                    aria-modal="true"
                >
                    <div
                        class="inset-0 bg-black/50 backdrop-blur-sm absolute"
                        @click="closeDetails"
                    />

                    <div
                        class="max-w-sm rounded-xl shadow-2xl p-6 relative w-full border border-border bg-popover"
                    >
                        <button
                            type="button"
                            class="right-4 top-4 absolute text-muted-foreground transition-colors hover:text-foreground"
                            :aria-label="t('kinetix.close')"
                            @click="closeDetails"
                        >
                            <X class="size-4" />
                        </button>

                        <div class="gap-2 flex items-start">
                            <span
                                class="mt-1.5 size-2.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor:
                                        selectedEvent.color ?? '#3b82f6',
                                }"
                            />
                            <div class="min-w-0">
                                <h2
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    {{ selectedEvent.title }}
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ eventRangeLabel }}
                                </p>
                                <p
                                    v-if="selectedEvent.description"
                                    class="mt-3 text-sm text-foreground"
                                >
                                    {{ selectedEvent.description }}
                                </p>
                                <a
                                    v-if="selectedEvent.url"
                                    :href="selectedEvent.url"
                                    :class="
                                        cn(
                                            buttonVariants({ size: 'sm' }),
                                            'mt-4',
                                        )
                                    "
                                >
                                    {{ t('kinetix.calendar_view_event') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ===== Event details: sheet ===== -->
        <KinetixSheet
            v-else
            :open="detailsOpen"
            :side="sheetSide"
            :title="selectedEvent?.title"
            @update:open="detailsOpen = $event"
            @close="closeDetails"
        >
            <div v-if="selectedEvent" class="space-y-3">
                <div class="gap-2 flex items-center">
                    <span
                        class="size-2.5 shrink-0 rounded-full"
                        :style="{
                            backgroundColor: selectedEvent.color ?? '#3b82f6',
                        }"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ eventRangeLabel }}
                    </p>
                </div>
                <p
                    v-if="selectedEvent.description"
                    class="text-sm text-foreground"
                >
                    {{ selectedEvent.description }}
                </p>
                <a
                    v-if="selectedEvent.url"
                    :href="selectedEvent.url"
                    :class="buttonVariants({ size: 'sm' })"
                >
                    {{ t('kinetix.calendar_view_event') }}
                </a>
            </div>
        </KinetixSheet>
    </div>
</template>
