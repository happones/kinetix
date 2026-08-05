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
    }>(),
    {
        value: null,
        native: false,
        disabled: false,
        placeholder: null,
        minValue: null,
        maxValue: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

const { t } = useI18n();
const open = ref(false);

const PAGE = 12;
const selectedYear = computed(() => Number(props.value) || null);
// Start the page on the decade containing the selected/current year.
const pageStart = ref(
    Math.floor(
        (selectedYear.value ?? zonedNow(useKinetixTimezone().value).year) /
            PAGE,
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

    emit('update:value', String(y));
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
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
