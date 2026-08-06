<script setup lang="ts">
import { Copy, Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixButton from './KinetixButton.vue';
import KinetixSwitch from './primitives/KinetixSwitch.vue';

/**
 * Weekly business-hours editor — one row per day: enable switch + one or
 * more HH:MM ranges (native time inputs) + add/remove range + "apply to all
 * days". The BusinessHours form field's frontend; value is the normalized
 * full-week object the `AsWeeklySchedule` cast stores. Day names come from
 * `Intl` in the active locale — no per-day translation keys.
 */
const props = withDefaults(
    defineProps<{
        value?: Record<string, DayEntry> | null;
        disabled?: boolean;
    }>(),
    { value: null, disabled: false },
);

const emit = defineEmits<{
    (e: 'update:value', value: Record<string, DayEntry>): void;
}>();

interface TimeRange {
    start: string;
    end: string;
}

interface DayEntry {
    enabled: boolean;
    ranges: TimeRange[];
}

const DAYS = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
] as const;

const SEED_RANGE: TimeRange = { start: '09:00', end: '17:00' };

const { t, locale } = useI18n();

// 2024-01-01 was a Monday — anchor for locale weekday names via Intl.
const dayLabel = (index: number): string =>
    new Intl.DateTimeFormat(locale.value, { weekday: 'long' }).format(
        new Date(Date.UTC(2024, 0, 1 + index, 12)),
    );

/** The full normalized week, whatever partial/null value arrived. */
const schedule = computed<Record<string, DayEntry>>(() => {
    const source = props.value ?? {};
    const days: Record<string, DayEntry> = {};

    for (const day of DAYS) {
        const raw = source[day];
        const ranges = Array.isArray(raw?.ranges)
            ? raw.ranges
                  .filter((r) => r && typeof r === 'object')
                  .map((r) => ({ start: r.start ?? '', end: r.end ?? '' }))
            : [];

        days[day] = {
            enabled: !!raw?.enabled && ranges.length > 0,
            ranges: ranges.length > 0 ? ranges : [{ ...SEED_RANGE }],
        };
    }

    return days;
});

/** Emit a fresh object with one day replaced (immutability). */
const patch = (day: string, entry: DayEntry): void => {
    emit('update:value', { ...schedule.value, [day]: entry });
};

const toggleDay = (day: string, enabled: boolean): void => {
    patch(day, { ...schedule.value[day], enabled });
};

const updateRange = (
    day: string,
    index: number,
    side: 'start' | 'end',
    time: string,
): void => {
    const ranges = schedule.value[day].ranges.map((range, i) =>
        i === index ? { ...range, [side]: time } : range,
    );

    patch(day, { ...schedule.value[day], ranges });
};

const addRange = (day: string): void => {
    patch(day, {
        ...schedule.value[day],
        ranges: [...schedule.value[day].ranges, { ...SEED_RANGE }],
    });
};

const removeRange = (day: string, index: number): void => {
    const ranges = schedule.value[day].ranges.filter((_, i) => i !== index);

    patch(day, {
        enabled: ranges.length > 0 && schedule.value[day].enabled,
        ranges: ranges.length > 0 ? ranges : [{ ...SEED_RANGE }],
    });
};

/** Copy this day's enabled state + ranges onto every day of the week. */
const applyToAll = (day: string): void => {
    const source = schedule.value[day];
    const next: Record<string, DayEntry> = {};

    for (const target of DAYS) {
        next[target] = {
            enabled: source.enabled,
            ranges: source.ranges.map((range) => ({ ...range })),
        };
    }

    emit('update:value', next);
};
</script>

<template>
    <div class="divide-y divide-border rounded-md border border-border">
        <div
            v-for="(day, dayIndex) in DAYS"
            :key="day"
            class="gap-3 p-3 sm:flex-row sm:items-start flex flex-col"
        >
            <div class="gap-2 sm:w-40 sm:pt-1 flex shrink-0 items-center">
                <KinetixSwitch
                    :model-value="schedule[day].enabled"
                    :disabled="disabled"
                    :aria-label="dayLabel(dayIndex)"
                    @update:model-value="toggleDay(day, $event)"
                />
                <span class="text-sm font-medium text-foreground capitalize">
                    {{ dayLabel(dayIndex) }}
                </span>
            </div>

            <div class="min-w-0 flex-1">
                <p
                    v-if="!schedule[day].enabled"
                    class="text-sm py-1.5 text-muted-foreground"
                >
                    {{ t('kinetix.business_hours_closed') }}
                </p>

                <div v-else class="space-y-2">
                    <div
                        v-for="(range, index) in schedule[day].ranges"
                        :key="index"
                        class="gap-2 flex items-center"
                    >
                        <input
                            type="time"
                            :value="range.start"
                            :class="inputClass"
                            class="w-28"
                            :disabled="disabled"
                            @input="
                                updateRange(
                                    day,
                                    index,
                                    'start',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                        <span
                            class="text-sm text-muted-foreground"
                            aria-hidden="true"
                            >–</span
                        >
                        <input
                            type="time"
                            :value="range.end"
                            :class="inputClass"
                            class="w-28"
                            :disabled="disabled"
                            @input="
                                updateRange(
                                    day,
                                    index,
                                    'end',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                        <KinetixButton
                            v-if="schedule[day].ranges.length > 1"
                            variant="ghost"
                            size="icon-sm"
                            type="button"
                            :disabled="disabled"
                            :aria-label="
                                t('kinetix.business_hours_remove_range')
                            "
                            @click="removeRange(day, index)"
                        >
                            <template #icon><X /></template>
                        </KinetixButton>
                    </div>

                    <div class="gap-2 flex items-center">
                        <KinetixButton
                            variant="ghost"
                            size="sm"
                            type="button"
                            :disabled="disabled"
                            @click="addRange(day)"
                        >
                            <template #icon><Plus /></template>
                            {{ t('kinetix.business_hours_add_range') }}
                        </KinetixButton>
                        <KinetixButton
                            variant="ghost"
                            size="sm"
                            type="button"
                            :disabled="disabled"
                            @click="applyToAll(day)"
                        >
                            <template #icon><Copy /></template>
                            {{ t('kinetix.business_hours_apply_all') }}
                        </KinetixButton>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
