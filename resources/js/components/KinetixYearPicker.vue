<script setup lang="ts">
import { CalendarIcon, ChevronLeft, ChevronRight } from '@lucide/vue';
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
import { useKinetixTimezone, zonedNow } from '@/composables/useKinetixTimezone';
import KinetixButton from './KinetixButton.vue';
import { cn } from './primitives/cn';

/**
 * Year picker: a shadcn popover with a paginated 12-year grid, or a native
 * number input via `native`. Value is a 'Y' string (e.g. "2026").
 */
const props = withDefaults(
    defineProps<{
        value?: string | number | null;
        native?: boolean;
        disabled?: boolean;
        placeholder?: string | null;
        /** 'Y' bounds. */
        minValue?: string | null;
        maxValue?: string | null;
        /** Whether picking a year closes the popover (default true). */
        closeOnSelect?: boolean;
        /** Show a "This year" shortcut in a popover footer. */
        showToday?: boolean;
        /** Commit only on Apply; outside-click/Escape discards the draft. */
        confirm?: boolean;
        /**
         * IANA timezone the This-year preset (and the initial page) reads the
         * clock in. Defaults to the app timezone Kinetix shares
         * (`config('app.timezone')`), then the browser clock.
         */
        timezone?: string | null;
    }>(),
    {
        value: null,
        native: false,
        disabled: false,
        placeholder: null,
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
const effectiveTimezone = useKinetixTimezone(() => props.timezone);

// Confirm mode edits a DRAFT the grid highlights; live mode passes the
// committed value straight through.
const draft = ref<string | null>(null);
const currentValue = computed(() =>
    props.confirm ? draft.value : props.value,
);
const hasFooter = computed(() => props.confirm || props.showToday);

const PAGE = 12;
const selectedYear = computed(() => Number(currentValue.value) || null);
// Start the page on the decade containing the selected/current year.
const pageStart = ref(
    Math.floor(
        (selectedYear.value ?? zonedNow(effectiveTimezone.value).year) / PAGE,
    ) * PAGE,
);
watch(
    () => props.value,
    () => {
        if (selectedYear.value) {
            pageStart.value = Math.floor(selectedYear.value / PAGE) * PAGE;
        }
    },
);
watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = props.value != null ? String(props.value) : null;
        const year =
            Number(props.value) || zonedNow(effectiveTimezone.value).year;
        pageStart.value = Math.floor(year / PAGE) * PAGE;
    }
});

const years = computed(() =>
    Array.from({ length: PAGE }, (_, i) => pageStart.value + i),
);

const isDisabled = (y: number) =>
    (props.minValue != null && y < Number(props.minValue)) ||
    (props.maxValue != null && y > Number(props.maxValue));

const select = (y: number) => {
    if (isDisabled(y)) {
        return;
    }

    if (props.confirm) {
        draft.value = String(y);

        return;
    }

    emit('update:value', String(y));

    if (props.closeOnSelect) {
        open.value = false;
    }
};

/** Current year in the effective timezone (app timezone by default). */
const setThisYear = () => {
    const year = zonedNow(effectiveTimezone.value).year;
    pageStart.value = Math.floor(year / PAGE) * PAGE;

    if (props.confirm) {
        draft.value = String(year);

        return;
    }

    emit('update:value', String(year));

    if (props.closeOnSelect) {
        open.value = false;
    }
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
    <input
        v-if="native"
        type="number"
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
            {{ value || placeholder || t('kinetix.pick_year') }}
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="p-3 shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] w-auto origin-(--reka-popover-content-transform-origin) rounded-md border border-border bg-popover outline-none"
            >
                <div class="mb-2 flex items-center justify-between">
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        @click="pageStart -= PAGE"
                    >
                        <ChevronLeft class="h-4 w-4" />
                    </button>
                    <span class="text-sm font-medium"
                        >{{ years[0] }} – {{ years[years.length - 1] }}</span
                    >
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        @click="pageStart += PAGE"
                    >
                        <ChevronRight class="h-4 w-4" />
                    </button>
                </div>
                <div class="gap-1 grid grid-cols-3">
                    <button
                        v-for="y in years"
                        :key="y"
                        type="button"
                        :disabled="isDisabled(y)"
                        :class="
                            cn(
                                buttonVariants({
                                    variant:
                                        selectedYear === y
                                            ? 'default'
                                            : 'ghost',
                                    size: 'sm',
                                }),
                                'disabled:opacity-40',
                            )
                        "
                        @click="select(y)"
                    >
                        {{ y }}
                    </button>
                </div>

                <!-- Footer: This-year shortcut and/or the confirm-mode Apply -->
                <div
                    v-if="hasFooter"
                    class="gap-2 pt-2 mt-2 flex items-center justify-between border-t border-border"
                >
                    <KinetixButton
                        v-if="showToday"
                        variant="ghost"
                        size="sm"
                        @click="setThisYear"
                    >
                        {{ t('kinetix.picker_this_year') }}
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
