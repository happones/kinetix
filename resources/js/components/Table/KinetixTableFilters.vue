<script setup lang="ts">
import { Filter as FilterIcon } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixTableFilter } from '@/types/kinetix';
import KinetixTableFilterField from './KinetixTableFilterField.vue';

const props = defineProps<{
    filters: KinetixTableFilter[];
    activeFilters: Record<string, unknown>;
}>();

const emit = defineEmits<{
    (e: 'set-filter', name: string, value: unknown): void;
    (e: 'clear'): void;
}>();

const { t } = useI18n();

const open = ref(false);

const activeCount = computed<number>(
    () => Object.keys(props.activeFilters).length,
);
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <button
                :class="[
                    buttonVariants({ variant: 'outline', size: 'sm' }),
                    activeCount > 0
                        ? 'border-primary bg-primary/10 text-primary'
                        : '',
                ]"
            >
                <FilterIcon class="h-3.5 w-3.5" />
                {{ t('kinetix.filters') }}
                <span
                    v-if="activeCount > 0"
                    class="ml-1 w-4 h-4 font-bold flex shrink-0 items-center justify-center rounded-full bg-primary text-[10px] text-primary-foreground"
                >
                    {{ activeCount }}
                </span>
            </button>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="4"
                class="w-72 rounded-lg p-4 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 border border-border bg-popover outline-none"
            >
                <div
                    class="pb-2 mb-3 flex items-center justify-between border-b border-border"
                >
                    <span
                        class="text-xs font-bold tracking-wider text-foreground uppercase"
                        >{{ t('kinetix.table_filters') }}</span
                    >
                    <button
                        class="text-xs text-muted-foreground underline-offset-4 transition-colors outline-none hover:text-foreground focus-visible:underline"
                        @click="emit('clear')"
                    >
                        {{ t('kinetix.reset') }}
                    </button>
                </div>
                <div class="space-y-4">
                    <div
                        v-for="filter in filters"
                        :key="filter.name"
                        class="gap-1.5 flex flex-col"
                    >
                        <label
                            class="text-xs font-semibold text-muted-foreground"
                            >{{ filter.label }}</label
                        >
                        <KinetixTableFilterField
                            :filter="filter"
                            :value="activeFilters[filter.name]"
                            @update="emit('set-filter', filter.name, $event)"
                        />
                    </div>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
