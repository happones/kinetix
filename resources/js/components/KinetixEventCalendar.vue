<script setup lang="ts">
import { getLocalTimeZone } from '@internationalized/date';
import type { CalendarDate } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixCalendarEventDetails } from '@/composables/useKinetixCalendarEventDetails';
import { useKinetixCalendarGrids } from '@/composables/useKinetixCalendarGrids';
import { useKinetixCalendarNavigation } from '@/composables/useKinetixCalendarNavigation';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type {
    KinetixCalendarData,
    KinetixCalendarEventDisplay,
    KinetixCalendarView,
    KinetixSheetSide,
} from '@/types';
import CalendarEventDetails from './Calendar/CalendarEventDetails.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';

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
 *
 * Grid geometry, navigation, and event-details state live in dedicated
 * composables (`useKinetixCalendar*`); this component wires them to the view
 * and owns the timezone-sensitive scroll-to-now behaviour.
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
    (e: 'event-click', event: KinetixCalendarData['events'][number]): void;
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

const {
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
} = useKinetixCalendarNavigation({
    weekStartsOn: () => props.weekStartsOn,
    locale: () => locale.value,
    tz: () => tz.value,
    views: () => props.views,
    initialView: props.view ?? props.views[0],
    anchorDate: () => props.anchorDate,
    onViewChange: (v) => emit('update:view', v),
});

const {
    monthGrid,
    hours,
    gridContentHeight,
    dayColumns,
    nowIndicator,
    formatHourLabel,
    slotInstant,
} = useKinetixCalendarGrids({
    anchor: () => anchor.value,
    activeView: () => activeView.value,
    events: () => props.calendar.events,
    tz: () => tz.value,
    locale: () => locale.value,
    weekStartsOn: () => props.weekStartsOn,
    startHour: () => props.startHour,
    endHour: () => props.endHour,
    todayKey: () => todayKey.value,
});

const {
    selectedEvent,
    detailsOpen,
    selectedEventActions,
    eventRangeLabel,
    openEvent,
    closeDetails,
    eventActionClass,
    handleEventAction,
    pendingAction,
    isConfirmOpen,
    processing,
    onConfirmAction,
    onCancelAction,
} = useKinetixCalendarEventDetails({
    locale: () => locale.value,
    tz: () => tz.value,
    showEventDetails: () => props.showEventDetails,
    onEventClick: (event) => emit('event-click', event),
});

// --- Scroll-to-now (owns the hourly grid element) ---------------------------
// Scrolled programmatically so the current time stays in view when switching
// into week/day, rather than defaulting to the top of the hour range.
const hourlyGridRef = ref<HTMLElement | null>(null);
const HOUR_ROW_PX = 64; // h-16 = 4rem = 64px, matches the hourly grid rows.

function scrollToNow(): void {
    const el = hourlyGridRef.value;

    if (!el || !nowIndicator.value) {
        return;
    }

    const totalHeightPx = hours.value.length * HOUR_ROW_PX;
    const targetPx = (nowIndicator.value.topPct / 100) * totalHeightPx;

    // Leave a third of the viewport above "now" for context.
    el.scrollTop = Math.max(0, targetPx - el.clientHeight / 3);
}

const isTimeGridView = (view: KinetixCalendarView): boolean =>
    view === 'week' || view === 'day';

function setView(v: KinetixCalendarView): void {
    setActiveView(v);

    if (isTimeGridView(v)) {
        nextTick(() => scrollToNow());
    }
}

function goToday(): void {
    goToToday();

    if (isTimeGridView(activeView.value)) {
        nextTick(() => scrollToNow());
    }
}

function onSlotClick(date: CalendarDate, hour: number): void {
    emit('slot-click', slotInstant(date, hour));
}

onMounted(() => {
    isMounted.value = true;

    if (isTimeGridView(activeView.value)) {
        nextTick(() => scrollToNow());
    }
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
                    <div
                        ref="hourlyGridRef"
                        class="flex max-h-[36rem] overflow-y-auto"
                    >
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

        <!-- Event details (modal / sheet) -->
        <CalendarEventDetails
            :is-mounted="isMounted"
            :event-display="eventDisplay"
            :sheet-side="sheetSide"
            :open="detailsOpen"
            :event="selectedEvent"
            :range-label="eventRangeLabel"
            :actions="selectedEventActions"
            :action-class="eventActionClass"
            @update:open="detailsOpen = $event"
            @close="closeDetails"
            @run-action="handleEventAction"
        />

        <!-- Confirmation modal for event actions that require it -->
        <KinetixConfirmModal
            v-model:open="isConfirmOpen"
            :heading="pendingAction?.modalHeading"
            :description="pendingAction?.modalDescription"
            :icon="pendingAction?.modalIcon"
            :color="pendingAction?.color"
            :submit-label="pendingAction?.modalSubmitActionLabel"
            :cancel-label="pendingAction?.modalCancelActionLabel"
            :processing="processing"
            @confirm="onConfirmAction"
            @cancel="onCancelAction"
        />
    </div>
</template>
