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
import KinetixCalendar from './KinetixCalendar.vue';
import KinetixTypedPickerTrigger from './KinetixTypedPickerTrigger.vue';
import { cn } from './primitives/cn';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        /** The field's id: what its label points at. */
        id?: string;
        /** Selected date as an ISO 'Y-m-d' string. */
        value?: string | null;
        /** Render a plain native <input type="date"> instead of the shadcn calendar. */
        native?: boolean;
        /**
         * Type the date on the keyboard over a mask, with the calendar on a
         * button to the right.
         */
        typed?: boolean;
        /** The typed mask's format (`dd/mm/yyyy`); null = the locale's order. */
        inputFormat?: string | null;
        disabled?: boolean;
        /** Overrides the default `kinetix.pick_date` placeholder. */
        placeholder?: string | null;
        locale?: string | null;
        weekdayFormat?: 'narrow' | 'short' | 'long' | null;
        numberOfMonths?: number;
        fixedWeeks?: boolean;
        minValue?: string | null;
        maxValue?: string | null;
        /**
         * Whether picking a date closes the popover — the shadcn default.
         * Pass false to keep it open (e.g. while comparing nearby fields).
         */
        closeOnSelect?: boolean;
        /** Show a "Today" shortcut in a popover footer. */
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
        typed: false,
        inputFormat: null,
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

const emit = defineEmits<{
    (e: 'update:value', value: string | null): void;
}>();

// The field's wiring (`aria-describedby`, `aria-invalid`) goes on the control
// the label points at — the popover root renders no element of its own.
defineOptions({ inheritAttrs: false });

const open = ref(false);

// Confirm mode edits a DRAFT the calendar displays; live mode passes the
// committed value straight through.
const draft = ref<string | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = props.value;
    }
});

const calendarValue = computed(() =>
    props.confirm ? draft.value : props.value,
);

const hasFooter = computed(() => props.confirm || props.showToday);

const formatted = computed(() => {
    if (!props.value) {
        return null;
    }

    try {
        const [y, m, d] = props.value.slice(0, 10).split('-').map(Number);

        return new Date(y, m - 1, d).toLocaleDateString(
            props.locale || undefined,
            {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            },
        );
    } catch {
        return props.value;
    }
});

const onCalendarSelect = (next: string | null) => {
    if (props.confirm) {
        draft.value = next;

        return;
    }

    emit('update:value', next);

    if (props.closeOnSelect) {
        open.value = false;
    }
};

const effectiveTimezone = useKinetixTimezone(() => props.timezone);

const setToday = () => onCalendarSelect(zonedTodayIso(effectiveTimezone.value));

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
        v-bind="$attrs"
        :id="id"
        v-if="native"
        type="date"
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
        <KinetixTypedPickerTrigger
            v-if="typed"
            v-bind="$attrs"
            :id="id"
            kind="date"
            :value="value"
            :format="inputFormat"
            :locale="locale"
            :disabled="disabled"
            :min-value="minValue"
            :max-value="maxValue"
            :button-label="t('kinetix.pick_date')"
            @update:value="emit('update:value', $event)"
        />
        <!-- as-child: the trigger is our button, so it keeps the id its label points at. -->
        <PopoverTrigger v-else as-child>
            <button
                v-bind="{ ...$attrs, ...(id ? { id } : {}) }"
                type="button"
                :disabled="disabled"
                :class="
                    cn(
                        buttonVariants({ variant: 'outline' }),
                        'font-normal w-full touch-manipulation justify-start text-left',
                        !value && 'text-muted-foreground',
                    )
                "
            >
                <CalendarIcon class="mr-2 h-4 w-4" />
                {{ formatted ?? placeholder ?? t('kinetix.pick_date') }}
            </button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="p-0 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] w-auto outline-none"
            >
                <KinetixCalendar
                    :value="calendarValue"
                    :timezone="timezone"
                    :locale="locale"
                    :weekday-format="weekdayFormat"
                    :number-of-months="numberOfMonths"
                    :fixed-weeks="fixedWeeks"
                    :min-value="minValue"
                    :max-value="maxValue"
                    @update:value="onCalendarSelect"
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
