<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixIntegrationLogs } from '@/composables/useKinetixIntegrationLogs';
import { buttonVariants } from '@/composables/useShadcnVariants';
import ApiLogsTable from './IntegrationLogs/ApiLogsTable.vue';
import IntegrationLogsToolbar from './IntegrationLogs/IntegrationLogsToolbar.vue';
import LogDetailModal from './IntegrationLogs/LogDetailModal.vue';
import WebhookLogsTable from './IntegrationLogs/WebhookLogsTable.vue';

/**
 * Integration logs viewer for SaaS back-offices: what each webhook delivered
 * (endpoint, payload, response, attempts — with one-click redelivery) and what
 * hit your token-authenticated API (method, path, status, duration, token).
 *
 * Feeds: `GET {prefix}/webhooks/logs` (`webhooks.manage`) and
 * `GET {prefix}/api-logs` (`viewKinetixApiLogs`). Show a single tab with the
 * `only` prop when just one module is enabled. Fetching state lives in
 * `useKinetixIntegrationLogs`; each feed renders through its own table.
 */
const props = withDefaults(
    defineProps<{
        /** Restrict to a single feed. */
        only?: 'webhooks' | 'api' | null;
    }>(),
    { only: null },
);

const { t } = useI18n();

const {
    tab,
    tabs,
    result,
    search,
    pageNumber,
    lastPage,
    total,
    loading,
    webhookLogs,
    apiLogs,
    detailWebhook,
    detailApi,
    detailOpen,
    load,
    redeliver,
} = useKinetixIntegrationLogs({ only: () => props.only });

// tab → table component. Two feeds today; a lookup keeps adding a third to a
// single map entry rather than another template branch.
const TABLES: Record<string, Component> = {
    webhooks: WebhookLogsTable,
    api: ApiLogsTable,
};

const activeTable = computed<Component>(
    () => TABLES[tab.value] ?? WebhookLogsTable,
);
const activeLogs = computed(() =>
    tab.value === 'webhooks' ? webhookLogs.value : apiLogs.value,
);

const onSelect = (log: any): void => {
    if (tab.value === 'webhooks') {
        detailWebhook.value = log;
    } else {
        detailApi.value = log;
    }
};
</script>

<template>
    <div class="space-y-4">
        <IntegrationLogsToolbar
            :tabs="tabs"
            :tab="tab"
            :result="result"
            :search="search"
            :loading="loading"
            @update:tab="tab = $event"
            @update:result="result = $event"
            @update:search="search = $event"
            @refresh="load"
        />

        <!-- Active feed (webhook deliveries / API requests) via the table map. -->
        <component
            :is="activeTable"
            :logs="activeLogs"
            :loading="loading"
            @select="onSelect"
        />

        <!-- Pagination -->
        <div
            class="gap-2 text-xs flex items-center justify-between text-muted-foreground"
        >
            <span>{{ t('kinetix.logs_total', { total }) }}</span>
            <div class="gap-1 flex items-center">
                <button
                    type="button"
                    :class="
                        buttonVariants({ variant: 'outline', size: 'icon-sm' })
                    "
                    :disabled="pageNumber <= 1"
                    @click="pageNumber--"
                >
                    <ChevronLeft class="size-4" />
                </button>
                <span class="px-2 tabular-nums">
                    {{ pageNumber }} / {{ lastPage }}
                </span>
                <button
                    type="button"
                    :class="
                        buttonVariants({ variant: 'outline', size: 'icon-sm' })
                    "
                    :disabled="pageNumber >= lastPage"
                    @click="pageNumber++"
                >
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </div>

        <!-- Detail modal -->
        <LogDetailModal
            :open="detailOpen"
            :webhook="detailWebhook"
            :api="detailApi"
            @update:open="detailOpen = $event"
            @redeliver="redeliver"
        />
    </div>
</template>
