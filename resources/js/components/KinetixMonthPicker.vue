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
 * Month picker: a shadcn popover with a 12-month grid + year navigation, or a
 * native <input type="month"> via `native`. Value is a 'Y-m' string.
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        native?: boolean;
        disabled?: boolean;
        placeholder?: string | null;
        locale?: string | null;
        /** 'Y-m' bounds. */
        minValue?: string | null;
        maxValue?: string | null;
        /** Whether picking a month closes the popover (default true). */
        closeOnSelect?: boolean;
        /** Show a "This month" shortcut in a popover footer. */
        showToday?: boolean;
        /** Commit only on Apply; outside-click/Escape discards the draft. */
        confirm?: boolean;
        /**
         * IANA timezone the This-month preset (and the initial year view)
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

// Confirm mode edits a DRAFT the grid highlights; live mode passes the
// committed value straight through.
const draft = ref<string | null>(null);
const currentValue = computed(() =>
    props.confirm ? draft.value : props.value,
);
const hasFooter = computed(() => props.confirm || props.showToday);

const selectedYear = computed(
    () => Number(currentValue.value?.slice(0, 4)) || null,
);
const selectedMonth = computed(
    () => Number(currentValue.value?.slice(5, 7)) || null,
);

const viewYear = ref(
    selectedYear.value ?? zonedNow(effectiveTimezone.value).year,
);
watch(
    () => props.value,
    () => {
        if (selectedYear.value) {
            viewYear.value = selectedYear.value;
        }
    },
);
watch(open, (isOpen) => {
    if (isOpen) {
        draft.value = props.value;
        viewYear.value =
            Number(props.value?.slice(0, 4)) ||
            zonedNow(effectiveTimezone.value).year;
    }
});

const monthLabels = computed(() =>
    Array.from({ length: 12 }, (_, i) =>
        new Date(2000, i, 1).toLocaleString(props.locale || undefined, {
            month: 'short',
        }),
    ),
);

const formatted = computed(() => {
    if (!props.value) {
        return null;
    }

    const [y, m] = props.value.split('-').map(Number);

    return new Date(y, m - 1, 1).toLocaleString(props.locale || undefined, {
        month: 'long',
        year: 'numeric',
    });
});

const monthValue = (m: number) => `${viewYear.value}-${pad(m)}`;
const isDisabled = (m: number) => {
    const v = monthValue(m);

    return (
        (props.minValue != null && v < props.minValue) ||
        (props.maxValue != null && v > props.maxValue)
    );
};

const select = (m: number) => {
    if (isDisabled(m)) {
        return;
    }

    if (props.confirm) {
        draft.value = monthValue(m);

        return;
    }

    emit('update:value', monthValue(m));

    if (props.closeOnSelect) {
        open.value = false;
    }
};

/** Current month in the effective timezone (app timezone by default). */
const setThisMonth = () => {
    const now = zonedNow(effectiveTimezone.value);
    viewYear.value = now.year;

    if (props.confirm) {
        draft.value = `${now.year}-${pad(now.month)}`;

        return;
    }

    emit('update:value', `${now.year}-${pad(now.month)}`);

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
        type="month"
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
            {{ formatted ?? placeholder ?? t('kinetix.pick_month') }}
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="4"
                class="p-3 shadow-md z-[var(--kinetix-z-popover,120)] w-auto rounded-md border border-border bg-popover outline-none"
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
                        @click="viewYear--"
                    >
                        <ChevronLeft class="h-4 w-4" />
                    </button>
                    <span class="text-sm font-medium">{{ viewYear }}</span>
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        @click="viewYear++"
                    >
                        <ChevronRight class="h-4 w-4" />
                    </button>
                </div>
                <div class="gap-1 grid grid-cols-3">
                    <button
                        v-for="(label, i) in monthLabels"
                        :key="i"
                        type="button"
                        :disabled="isDisabled(i + 1)"
                        :class="
                            cn(
                                buttonVariants({
                                    variant:
                                        selectedYear === viewYear &&
                                        selectedMonth === i + 1
                                            ? 'default'
                                            : 'ghost',
                                    size: 'sm',
                                }),
                                'disabled:opacity-40',
                            )
                        "
                        @click="select(i + 1)"
                    >
                        {{ label }}
                    </button>
                </div>

                <!-- Footer: This-month shortcut and/or the confirm-mode Apply -->
                <div
                    v-if="hasFooter"
                    class="gap-2 pt-2 mt-2 flex items-center justify-between border-t border-border"
                >
                    <KinetixButton
                        v-if="showToday"
                        variant="ghost"
                        size="sm"
                        @click="setThisMonth"
                    >
                        {{ t('kinetix.picker_this_month') }}
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
