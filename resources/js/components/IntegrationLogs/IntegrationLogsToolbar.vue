<script setup lang="ts">
import { RefreshCw } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type {
    IntegrationLogResult,
    IntegrationLogTab,
} from '@/composables/useKinetixIntegrationLogs';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import { cn } from '../primitives/cn';

defineProps<{
    tabs: IntegrationLogTab[];
    tab: IntegrationLogTab;
    result: IntegrationLogResult;
    search: string;
    loading: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:tab', tab: IntegrationLogTab): void;
    (e: 'update:result', result: IntegrationLogResult): void;
    (e: 'update:search', search: string): void;
    (e: 'refresh'): void;
}>();

const { t } = useI18n();

const RESULTS: IntegrationLogResult[] = ['all', 'success', 'failed'];
</script>

<template>
    <div
        class="gap-3 sm:flex-row sm:items-center flex flex-col justify-between"
    >
        <div
            v-if="tabs.length > 1"
            class="gap-1 rounded-lg p-1 inline-flex bg-muted"
        >
            <button
                v-for="option in tabs"
                :key="option"
                type="button"
                class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                :class="
                    tab === option
                        ? 'shadow-sm bg-background text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="emit('update:tab', option)"
            >
                {{
                    option === 'webhooks'
                        ? t('kinetix.logs_webhooks_tab')
                        : t('kinetix.logs_api_tab')
                }}
            </button>
        </div>

        <div class="gap-2 flex items-center">
            <input
                :value="search"
                type="search"
                :class="cn(inputClass, 'h-8 w-44 text-xs')"
                :placeholder="t('kinetix.logs_search')"
                @input="
                    emit(
                        'update:search',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
            <div class="gap-1 rounded-lg p-1 inline-flex bg-muted">
                <button
                    v-for="option in RESULTS"
                    :key="option"
                    type="button"
                    class="px-2.5 py-1 text-xs font-medium rounded-md transition-colors"
                    :class="
                        result === option
                            ? 'shadow-sm bg-background text-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="emit('update:result', option)"
                >
                    {{ t(`kinetix.logs_result_${option}`) }}
                </button>
            </div>
            <button
                type="button"
                :class="buttonVariants({ variant: 'ghost', size: 'icon-sm' })"
                :title="t('kinetix.refresh')"
                @click="emit('refresh')"
            >
                <RefreshCw
                    class="size-4"
                    :class="loading ? 'animate-spin' : ''"
                />
            </button>
        </div>
    </div>
</template>
