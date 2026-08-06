<script setup lang="ts">
import { CalendarIcon } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import {
    useKinetixTimezone,
    zonedTodayIso,
} from '@/composables/useKinetixTimezone';
import KinetixButton from './KinetixButton.vue';
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
        /** Whether picking a week closes the popover (default true). */
        closeOnSelect?: boolean;
        /** Show a "This week" shortcut in a popover footer. */
        showToday?: boolean;
        /** Commit only on Apply; outside-click/Escape discards the draft. */
        confirm?: boolean;
        /**
         * IANA timezone the This-week preset (and the calendar's initial
         * month) reads the clock in. Defaults to the app timezone Kinetix
         * shares (`config('app.timezone')`), then the browser clock.
         */
        timezone?: string | null;
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
        closeOnSelect: true,
        showToday: false,
        confirm: false,
        timezone: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

const { t } = useI18n();
const open = ref(false);
const pad = (n: number) => String(n).padStart(2, '0');
const effectiveTimezone = useKinetixTimezone(() => props.timezone);

// Confirm mode edits a DRAFT the calendar highlights; live mode passes the
// committed value straight through.
const draft = ref<string | null>(null);
const currentValue = computed(() =>
    props.confirm ? draft.value : props.value,
);
const hasFooter = computed(() => props.confirm || props.showToday);

watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = props.value;
    }
});

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

const calendarValue = computed(() => weekToMonday(currentValue.value ?? ''));

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

    if (props.confirm) {
        draft.value = isoWeek(day);

        return;
    }

    emit('update:value', isoWeek(day));

    if (props.closeOnSelect) {
        open.value = false;
    }
};

/** Current ISO week in the effective timezone (app timezone by default). */
const setThisWeek = () => onDaySelect(zonedTodayIso(effectiveTimezone.value));

/** Confirm mode's ONLY commit path. */
const apply = () => {
    if (draft.value !== null) {
        emit('update:value', draft.value);
    }

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
                class="p-0 z-[var(--kinetix-z-popover,120)] w-auto outline-none"
            >
                <KinetixWeekCalendar
                    :value="calendarValue"
                    :timezone="timezone"
                    :locale="locale"
                    :week-starts-on="weekStartsOn"
                    @update:value="onDaySelect"
                />

                <!-- Footer: This-week shortcut and/or the confirm-mode Apply -->
                <div
                    v-if="hasFooter"
                    class="gap-2 p-2 flex items-center justify-between border-t border-border"
                >
                    <KinetixButton
                        v-if="showToday"
                        variant="ghost"
                        size="sm"
                        @click="setThisWeek"
                    >
                        {{ t('kinetix.picker_this_week') }}
                    </KinetixButton>
                    <span v-else />
                    <KinetixButton v-if="confirm" size="sm" @click="apply">
                        {{ t('kinetix.apply') }}
                    </KinetixButton>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
