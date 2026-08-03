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
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import KinetixCalendar from './KinetixCalendar.vue';
import { cn } from './primitives/cn';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        /** Selected date as an ISO 'Y-m-d' string. */
        value?: string | null;
        /** Render a plain native <input type="date"> instead of the shadcn calendar. */
        native?: boolean;
        disabled?: boolean;
        /** Overrides the default `kinetix.pick_date` placeholder. */
        placeholder?: string | null;
        locale?: string | null;
        weekdayFormat?: 'narrow' | 'short' | 'long' | null;
        numberOfMonths?: number;
        fixedWeeks?: boolean;
        minValue?: string | null;
        maxValue?: string | null;
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
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: string | null): void;
}>();

const open = ref(false);

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
    emit('update:value', next);
    open.value = false;
};
</script>

<template>
    <input
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
            {{ formatted ?? placeholder ?? t('kinetix.pick_date') }}
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="p-0 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 z-50 w-auto outline-none"
            >
                <KinetixCalendar
                    :value="value"
                    :locale="locale"
                    :weekday-format="weekdayFormat"
                    :number-of-months="numberOfMonths"
                    :fixed-weeks="fixedWeeks"
                    :min-value="minValue"
                    :max-value="maxValue"
                    @update:value="onCalendarSelect"
                />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
