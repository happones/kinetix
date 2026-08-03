<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuTrigger,
} from 'reka-ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixPeriodKey } from '@/composables/useKinetixPeriod';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';

/**
 * A dashboard period filter — segmented buttons (Last 7 days / 30 days / …) or a
 * select dropdown. Pairs with `useKinetixPeriod` (client range) and the PHP
 * `Period` parser (server query). v-model is the period key.
 */
const props = withDefaults(
    defineProps<{
        modelValue?: KinetixPeriodKey;
        /** Which period keys to offer. */
        periods?: KinetixPeriodKey[];
        /** 'segmented' (buttons) or 'select' (dropdown). */
        variant?: 'segmented' | 'select';
    }>(),
    {
        modelValue: '30d',
        periods: () => ['7d', '30d', '90d'],
        variant: 'segmented',
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: KinetixPeriodKey): void;
    (e: 'change', value: KinetixPeriodKey): void;
}>();

const { t } = useI18n();

const label = (key: KinetixPeriodKey): string => t(`kinetix.period_${key}`);
const currentLabel = computed(() => label(props.modelValue));

function select(key: KinetixPeriodKey): void {
    if (key !== props.modelValue) {
        emit('update:modelValue', key);
        emit('change', key);
    }
}
</script>

<template>
    <!-- Segmented buttons -->
    <div
        v-if="variant === 'segmented'"
        class="gap-1 rounded-lg p-1 inline-flex items-center border border-border bg-muted/40"
        role="group"
    >
        <button
            v-for="key in periods"
            :key="key"
            type="button"
            class="px-3 py-1 text-sm font-medium rounded-md transition-colors"
            :class="
                modelValue === key
                    ? 'shadow-sm bg-background text-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            "
            @click="select(key)"
        >
            {{ label(key) }}
        </button>
    </div>

    <!-- Select dropdown -->
    <DropdownMenuRoot v-else>
        <DropdownMenuTrigger
            :class="buttonVariants({ variant: 'outline', size: 'sm' })"
        >
            {{ currentLabel }}
            <ChevronDown class="size-4 opacity-60" />
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent
                align="end"
                :side-offset="6"
                class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 z-50 min-w-[10rem] border border-border bg-popover outline-none"
            >
                <DropdownMenuItem
                    v-for="key in periods"
                    :key="key"
                    class="px-3 py-2 text-sm flex w-full cursor-default items-center rounded-md text-left transition-colors outline-none select-none hover:bg-accent focus:bg-accent"
                    :class="
                        modelValue === key
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                    @click="select(key)"
                >
                    {{ label(key) }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
