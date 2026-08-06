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
import KinetixRangeCalendar from './KinetixRangeCalendar.vue';
import { cn } from './primitives/cn';

type Range = { from?: string | null; to?: string | null } | null;

/**
 * Date-range field. Renders the shadcn range calendar in a popover by default,
 * or two native <input type="date"> via `native`. Value is `{ from, to }`.
 *
 * Closes once BOTH ends are picked (`closeOnSelect`, the shadcn behavior).
 * `showToday` adds a Today shortcut (from = to = today, read in the effective
 * timezone); `confirm` builds a DRAFT and commits only via the Apply button,
 * discarding on any other dismissal — same contract as the other pickers.
 */
const props = withDefaults(
    defineProps<{
        value?: Range;
        native?: boolean;
        disabled?: boolean;
        placeholder?: string | null;
        locale?: string | null;
        weekdayFormat?: 'narrow' | 'short' | 'long' | null;
        numberOfMonths?: number;
        fixedWeeks?: boolean;
        minValue?: string | null;
        maxValue?: string | null;
        /** Whether completing the range closes the popover (default true). */
        closeOnSelect?: boolean;
        /** Show a "Today" shortcut (from = to = today) in a popover footer. */
        showToday?: boolean;
        /** Commit only on Apply; outside-click/Escape discards the draft. */
        confirm?: boolean;
        /**
         * IANA timezone the Today preset (and the calendar's initial month)
         * reads the clock in. Defaults to the app timezone Kinetix shares
         * (`config('app.timezone')`), then the browser clock.
         */
        timezone?: string | null;
    }>(),
    {
        value: null,
        native: false,
        disabled: false,
        placeholder: null,
        locale: null,
        weekdayFormat: null,
        numberOfMonths: 1,
        fixedWeeks: false,
        minValue: null,
        maxValue: null,
        closeOnSelect: true,
        showToday: false,
        confirm: false,
        timezone: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: Range): void }>();

const { t } = useI18n();
const open = ref(false);
const effectiveTimezone = useKinetixTimezone(() => props.timezone);

// Confirm mode edits a DRAFT the calendar displays; live mode passes the
// committed value straight through.
const draft = ref<Range>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = props.value ? { ...props.value } : null;
    }
});

const calendarValue = computed(() =>
    props.confirm ? draft.value : props.value,
);

const hasFooter = computed(() => props.confirm || props.showToday);

const fmt = (d?: string | null) => {
    if (!d) {
        return null;
    }

    const [y, m, day] = d.slice(0, 10).split('-').map(Number);

    return new Date(y, m - 1, day).toLocaleDateString(
        props.locale || undefined,
        {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        },
    );
};

const label = computed(() => {
    const from = fmt(props.value?.from);
    const to = fmt(props.value?.to);

    if (!from && !to) {
        return null;
    }

    return `${from ?? '…'} – ${to ?? '…'}`;
});

const setPart = (part: 'from' | 'to', v: string) => {
    emit('update:value', { ...(props.value ?? {}), [part]: v || null });
};

const onCalendar = (range: Range) => {
    if (props.confirm) {
        draft.value = range;

        return;
    }

    emit('update:value', range);

    if (props.closeOnSelect && range?.from && range?.to) {
        open.value = false;
    }
};

const setToday = () => {
    const today = zonedTodayIso(effectiveTimezone.value);

    onCalendar({ from: today, to: today });
};

/** Confirm mode's ONLY commit path. */
const apply = () => {
    if (draft.value !== null) {
        emit('update:value', draft.value);
    }

    open.value = false;
};
</script>

<template>
    <div v-if="native" class="gap-2 flex items-center">
        <input
            type="date"
            :value="value?.from ?? ''"
            :disabled="disabled"
            :min="minValue || undefined"
            :max="maxValue || undefined"
            :class="inputClass"
            @change="setPart('from', ($event.target as HTMLInputElement).value)"
        />
        <span class="text-muted-foreground">–</span>
        <input
            type="date"
            :value="value?.to ?? ''"
            :disabled="disabled"
            :min="minValue || undefined"
            :max="maxValue || undefined"
            :class="inputClass"
            @change="setPart('to', ($event.target as HTMLInputElement).value)"
        />
    </div>

    <PopoverRoot v-else v-model:open="open">
        <PopoverTrigger
            :disabled="disabled"
            :class="
                cn(
                    buttonVariants({ variant: 'outline' }),
                    'font-normal w-full touch-manipulation justify-start text-left',
                    !label && 'text-muted-foreground',
                )
            "
        >
            <CalendarIcon class="mr-2 h-4 w-4" />
            {{ label ?? placeholder ?? t('kinetix.pick_date_range') }}
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="p-0 z-[var(--kinetix-z-popover,120)] w-auto outline-none"
            >
                <KinetixRangeCalendar
                    :value="calendarValue"
                    :timezone="timezone"
                    :locale="locale"
                    :weekday-format="weekdayFormat"
                    :number-of-months="numberOfMonths"
                    :fixed-weeks="fixedWeeks"
                    :min-value="minValue"
                    :max-value="maxValue"
                    @update:value="onCalendar"
                />

                <!-- Footer: Today shortcut and/or the confirm-mode Apply -->
                <div
                    v-if="hasFooter"
                    class="gap-2 p-2 flex items-center justify-between border-t border-border"
                >
                    <KinetixButton
                        v-if="showToday"
                        variant="ghost"
                        size="sm"
                        @click="setToday"
                    >
                        {{ t('kinetix.calendar_today') }}
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
