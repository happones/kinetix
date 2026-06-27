<script setup lang="ts">
import { CalendarIcon } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import KinetixWeekCalendar from './KinetixWeekCalendar.vue';
import { cn } from './primitives/cn';

/**
 * Week picker: a shadcn calendar that selects the clicked day's ISO week, or a
 * native <input type="week"> via `native`. Value is an 'o-\WW' string
 * (e.g. "2026-W25") — the native week-input format.
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        native?: boolean;
        disabled?: boolean;
        placeholder?: string | null;
        locale?: string | null;
        /** First day of the week: 0=Sun … 6=Sat (default 1=Mon). */
        weekStartsOn?: number;
        /** 'o-\WW' bounds. */
        minValue?: string | null;
        maxValue?: string | null;
    }>(),
    {
        value: null,
        native: false,
        disabled: false,
        placeholder: null,
        locale: null,
        weekStartsOn: 1,
        minValue: null,
        maxValue: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

const { t } = useI18n();
const open = ref(false);
const pad = (n: number) => String(n).padStart(2, '0');

/** ISO week string ("o-\WW") for a 'Y-m-d' date. */
const isoWeek = (dateStr: string): string => {
    const d = new Date(dateStr + 'T00:00:00Z');
    const day = (d.getUTCDay() + 6) % 7; // Mon=0
    d.setUTCDate(d.getUTCDate() - day + 3); // nearest Thursday
    const firstThursday = new Date(Date.UTC(d.getUTCFullYear(), 0, 4));
    const ftDay = (firstThursday.getUTCDay() + 6) % 7;
    const week =
        1 +
        Math.round(
            ((d.getTime() - firstThursday.getTime()) / 86400000 - 3 + ftDay) /
                7,
        );

    return `${d.getUTCFullYear()}-W${pad(week)}`;
};

/** Monday (Y-m-d) of an ISO week string, for highlighting in the calendar. */
const weekToMonday = (week: string): string | null => {
    if (!week || !week.includes('-W')) {
        return null;
    }

    const [y, w] = week.split('-W').map(Number);
    const jan4 = new Date(Date.UTC(y, 0, 4));
    const jan4Day = (jan4.getUTCDay() + 6) % 7;
    const monday = new Date(jan4);
    monday.setUTCDate(jan4.getUTCDate() - jan4Day + (w - 1) * 7);

    return monday.toISOString().slice(0, 10);
};

const calendarValue = computed(() => weekToMonday(props.value ?? ''));

const formatted = computed(() => {
    if (!props.value || !props.value.includes('-W')) {
        return null;
    }

    const [y, w] = props.value.split('-W');

    return t('kinetix.week_of', { week: Number(w), year: y });
});

const onDaySelect = (day: string | null) => {
    if (!day) {
        return;
    }

    emit('update:value', isoWeek(day));
    open.value = false;
};
</script>

<template>
    <input
        v-if="native"
        type="week"
        :value="value"
        :disabled="disabled"
        :min="minValue || undefined"
        :max="maxValue || undefined"
        :class="inputClass"
        @input="
            emit(
                'update:value',
                ($event.target as HTMLInputElement).value || null,
            )
        "
    />

    <PopoverRoot v-else v-model:open="open">
        <PopoverTrigger
            :disabled="disabled"
            :class="
                cn(
                    buttonVariants({ variant: 'outline' }),
                    'font-normal w-full justify-start text-left',
                    !value && 'text-muted-foreground',
                )
            "
        >
            <CalendarIcon class="mr-2 h-4 w-4" />
            {{ formatted ?? placeholder ?? t('kinetix.pick_week') }}
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="p-0 z-50 w-auto outline-none"
            >
                <KinetixWeekCalendar
                    :value="calendarValue"
                    :locale="locale"
                    :week-starts-on="weekStartsOn"
                    @update:value="onDaySelect"
                />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
