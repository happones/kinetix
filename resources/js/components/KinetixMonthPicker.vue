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
    }>(),
    {
        value: null,
        native: false,
        disabled: false,
        placeholder: null,
        locale: null,
        minValue: null,
        maxValue: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

const { t } = useI18n();
const open = ref(false);
const pad = (n: number) => String(n).padStart(2, '0');

const selectedYear = computed(() => Number(props.value?.slice(0, 4)) || null);
const selectedMonth = computed(() => Number(props.value?.slice(5, 7)) || null);

const viewYear = ref(selectedYear.value ?? new Date().getFullYear());
watch(
    () => props.value,
    () => {
        if (selectedYear.value) {
            viewYear.value = selectedYear.value;
        }
    },
);

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

    emit('update:value', monthValue(m));
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
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
