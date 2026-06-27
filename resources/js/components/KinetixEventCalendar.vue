<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixCalendarData, KinetixCalendarEvent } from '@/types';

/**
 * A month-view event calendar (scheduler). Lays the supplied events out on a
 * 6-week grid and navigates months client-side. Emits `event-click` /
 * `day-click`; events with a `url` render as links. Distinct from the
 * date-picker's <KinetixCalendar> (a single-date selector).
 */
const props = withDefaults(
    defineProps<{
        calendar: KinetixCalendarData;
        /** First day of the week: 0=Sun … 6=Sat (default Monday). */
        weekStartsOn?: number;
        locale?: string | null;
    }>(),
    { weekStartsOn: 1, locale: null },
);

const emit = defineEmits<{
    (e: 'event-click', event: KinetixCalendarEvent): void;
    (e: 'day-click', date: string): void;
}>();

const { t } = useI18n();
const locale = computed(() => props.locale ?? undefined);

const today = new Date();
const cursor = ref({ year: today.getFullYear(), month: today.getMonth() });

const iso = (d: Date): string =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(
        d.getDate(),
    ).padStart(2, '0')}`;

const monthLabel = computed(() =>
    new Intl.DateTimeFormat(locale.value, {
        month: 'long',
        year: 'numeric',
    }).format(new Date(cursor.value.year, cursor.value.month, 1)),
);

const weekdays = computed(() => {
    const fmt = new Intl.DateTimeFormat(locale.value, { weekday: 'short' });
    // 2024-01-07 is a Sunday — build labels from the configured start day.
    return Array.from({ length: 7 }, (_, i) =>
        fmt.format(new Date(2024, 0, 7 + ((props.weekStartsOn + i) % 7))),
    );
});

interface Day {
    date: string;
    day: number;
    inMonth: boolean;
    isToday: boolean;
    events: KinetixCalendarEvent[];
}

const grid = computed<Day[]>(() => {
    const first = new Date(cursor.value.year, cursor.value.month, 1);
    const offset = (first.getDay() - props.weekStartsOn + 7) % 7;
    const start = new Date(first);
    start.setDate(1 - offset);
    const todayIso = iso(today);

    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const date = iso(d);

        return {
            date,
            day: d.getDate(),
            inMonth: d.getMonth() === cursor.value.month,
            isToday: date === todayIso,
            events: props.calendar.events.filter((e) =>
                e.end ? date >= e.start && date <= e.end : date === e.start,
            ),
        };
    });
});

function shift(delta: number): void {
    const m = cursor.value.month + delta;
    cursor.value = {
        year: cursor.value.year + Math.floor(m / 12),
        month: ((m % 12) + 12) % 12,
    };
}

function goToday(): void {
    cursor.value = { year: today.getFullYear(), month: today.getMonth() };
}
</script>

<template>
    <div class="space-y-4">
        <div class="gap-3 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-foreground">
                {{ calendar.heading ?? monthLabel }}
            </h2>
            <div class="gap-1 flex items-center">
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    @click="goToday"
                >
                    {{ t('kinetix.calendar_today') }}
                </button>
                <button
                    type="button"
                    :class="
                        buttonVariants({ variant: 'outline', size: 'icon-sm' })
                    "
                    :aria-label="t('kinetix.calendar_prev')"
                    @click="shift(-1)"
                >
                    <ChevronLeft class="size-4" />
                </button>
                <button
                    type="button"
                    :class="
                        buttonVariants({ variant: 'outline', size: 'icon-sm' })
                    "
                    :aria-label="t('kinetix.calendar_next')"
                    @click="shift(1)"
                >
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </div>

        <div
            v-if="calendar.heading"
            class="text-sm font-medium text-muted-foreground"
        >
            {{ monthLabel }}
        </div>

        <div class="rounded-lg overflow-hidden border border-border">
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
                    v-for="cell in grid"
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
                        <component
                            :is="event.url ? 'a' : 'button'"
                            v-for="event in cell.events.slice(0, 3)"
                            :key="String(event.id)"
                            :href="event.url ?? undefined"
                            type="button"
                            class="rounded px-1.5 py-0.5 text-xs text-white block w-full truncate text-left"
                            :style="{
                                backgroundColor: event.color ?? '#3b82f6',
                            }"
                            @click.stop="emit('event-click', event)"
                        >
                            {{ event.title }}
                        </component>
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
    </div>
</template>
