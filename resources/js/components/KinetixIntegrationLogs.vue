<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, RefreshCw, X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import type {
    KinetixApiLog,
    KinetixSharedProps,
    KinetixWebhookLog,
} from '@/types';
import { cn } from './primitives/cn';

/**
 * Integration logs viewer for SaaS back-offices: what each webhook delivered
 * (endpoint, payload, response, attempts — with one-click redelivery) and what
 * hit your token-authenticated API (method, path, status, duration, token).
 *
 * Feeds: `GET {prefix}/webhooks/logs` (`webhooks.manage`) and
 * `GET {prefix}/api-logs` (`viewKinetixApiLogs`). Show a single tab with the
 * `only` prop when just one module is enabled.
 */
const props = withDefaults(
    defineProps<{
        /** Restrict to a single feed. */
        only?: 'webhooks' | 'api' | null;
    }>(),
    { only: null },
);

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();
const base = computed(() => `/${kinetixRoutePrefix(page)}`);

type Tab = 'webhooks' | 'api';
const tab = ref<Tab>(props.only ?? 'webhooks');
const tabs = computed<Tab[]>(() =>
    props.only ? [props.only] : ['webhooks', 'api'],
);

const result = ref<'all' | 'success' | 'failed'>('all');
const search = ref('');
const pageNumber = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);

const webhookLogs = ref<KinetixWebhookLog[]>([]);
const apiLogs = ref<KinetixApiLog[]>([]);

interface Paginated<T> {
    data: T[];
    pagination: { current_page: number; last_page: number; total: number };
}

async function load(): Promise<void> {
    loading.value = true;

    const params = new URLSearchParams({ page: String(pageNumber.value) });

    if (result.value !== 'all') {
        params.set('result', result.value);
    }

    if (search.value.trim() !== '') {
        params.set('search', search.value.trim());
    }

    try {
        if (tab.value === 'webhooks') {
            const res = await kinetixFetch<Paginated<KinetixWebhookLog>>(
                `${base.value}/webhooks/logs?${params}`,
            );
            webhookLogs.value = res?.data ?? [];
            lastPage.value = res?.pagination.last_page ?? 1;
            total.value = res?.pagination.total ?? 0;
        } else {
            const res = await kinetixFetch<Paginated<KinetixApiLog>>(
                `${base.value}/api-logs?${params}`,
            );
            apiLogs.value = res?.data ?? [];
            lastPage.value = res?.pagination.last_page ?? 1;
            total.value = res?.pagination.total ?? 0;
        }
    } finally {
        loading.value = false;
    }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch([tab, result], () => {
    pageNumber.value = 1;
    void load();
});
watch(pageNumber, () => void load());
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        pageNumber.value = 1;
        void load();
    }, 300);
});

onMounted(load);

// --- Detail modal -----------------------------------------------------------

const detailWebhook = ref<KinetixWebhookLog | null>(null);
const detailApi = ref<KinetixApiLog | null>(null);
const detailOpen = computed({
    get: () => detailWebhook.value !== null || detailApi.value !== null,
    set: (open: boolean) => {
        if (!open) {
            detailWebhook.value = null;
            detailApi.value = null;
        }
    },
});

const pretty = (value: unknown): string => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch {
            return value;
        }
    }

    return JSON.stringify(value, null, 2);
};

const statusClass = (ok: boolean): string =>
    ok
        ? 'bg-success/10 text-success border border-success/20'
        : 'bg-destructive/10 text-destructive border border-destructive/20';

const formatTime = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString() : '—';

async function redeliver(log: KinetixWebhookLog): Promise<void> {
    await kinetixFetch(`${base.value}/webhooks/logs/${log.id}/redeliver`, {
        method: 'POST',
    });
    detailWebhook.value = null;
    await load();
}
</script>

<template>
    <div class="space-y-4">
        <!-- Tabs + filters -->
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
                    @click="tab = option"
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
                    v-model="search"
                    type="search"
                    :class="cn(inputClass, 'h-8 w-44 text-xs')"
                    :placeholder="t('kinetix.logs_search')"
                />
                <div class="gap-1 rounded-lg p-1 inline-flex bg-muted">
                    <button
                        v-for="option in ['all', 'success', 'failed'] as const"
                        :key="option"
                        type="button"
                        class="px-2.5 py-1 text-xs font-medium rounded-md transition-colors"
                        :class="
                            result === option
                                ? 'shadow-sm bg-background text-foreground'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="result = option"
                    >
                        {{ t(`kinetix.logs_result_${option}`) }}
                    </button>
                </div>
                <button
                    type="button"
                    :class="
                        buttonVariants({ variant: 'ghost', size: 'icon-sm' })
                    "
                    :title="t('kinetix.refresh')"
                    @click="load"
                >
                    <RefreshCw
                        class="size-4"
                        :class="loading ? 'animate-spin' : ''"
                    />
                </button>
            </div>
        </div>

        <!-- Webhook deliveries -->
        <div
            v-if="tab === 'webhooks'"
            class="rounded-xl overflow-x-auto border border-border"
        >
            <table class="text-sm w-full">
                <thead class="bg-muted/40">
                    <tr class="border-b border-border">
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_status') }}
                        </th>
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_event') }}
                        </th>
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_endpoint') }}
                        </th>
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_attempt') }}
                        </th>
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_time') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="log in webhookLogs"
                        :key="String(log.id)"
                        class="cursor-pointer border-b border-border last:border-0 hover:bg-accent/50"
                        @click="detailWebhook = log"
                    >
                        <td class="px-3 py-2">
                            <span
                                class="px-1.5 py-0.5 font-semibold rounded-md text-[11px] tabular-nums"
                                :class="statusClass(log.success)"
                            >
                                {{ log.statusCode ?? '—' }}
                            </span>
                        </td>
                        <td class="px-3 py-2 font-medium text-foreground">
                            {{ log.event }}
                        </td>
                        <td
                            class="max-w-56 px-3 py-2 truncate text-muted-foreground"
                        >
                            {{ log.endpointName ?? log.endpointUrl ?? '—' }}
                        </td>
                        <td
                            class="px-3 py-2 text-muted-foreground tabular-nums"
                        >
                            #{{ log.attempt }}
                        </td>
                        <td
                            class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                        >
                            {{ formatTime(log.createdAt) }}
                        </td>
                    </tr>
                    <tr v-if="!loading && webhookLogs.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-8 text-sm text-center text-muted-foreground"
                        >
                            {{ t('kinetix.logs_empty') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- API requests -->
        <div v-else class="rounded-xl overflow-x-auto border border-border">
            <table class="text-sm w-full">
                <thead class="bg-muted/40">
                    <tr class="border-b border-border">
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_status') }}
                        </th>
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_request') }}
                        </th>
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_token') }}
                        </th>
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_duration') }}
                        </th>
                        <th class="px-3 py-2 font-medium text-left">
                            {{ t('kinetix.logs_time') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="log in apiLogs"
                        :key="String(log.id)"
                        class="cursor-pointer border-b border-border last:border-0 hover:bg-accent/50"
                        @click="detailApi = log"
                    >
                        <td class="px-3 py-2">
                            <span
                                class="px-1.5 py-0.5 font-semibold rounded-md text-[11px] tabular-nums"
                                :class="statusClass(log.status < 400)"
                            >
                                {{ log.status }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="font-mono text-xs font-semibold text-foreground"
                            >
                                {{ log.method }}
                            </span>
                            <span
                                class="ml-1.5 font-mono text-xs text-muted-foreground"
                            >
                                {{ log.path }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ log.tokenName ?? '—' }}
                        </td>
                        <td
                            class="px-3 py-2 text-muted-foreground tabular-nums"
                        >
                            {{
                                log.durationMs !== null
                                    ? `${log.durationMs} ms`
                                    : '—'
                            }}
                        </td>
                        <td
                            class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                        >
                            {{ formatTime(log.createdAt) }}
                        </td>
                    </tr>
                    <tr v-if="!loading && apiLogs.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-8 text-sm text-center text-muted-foreground"
                        >
                            {{ t('kinetix.logs_empty') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

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
        <DialogRoot v-model:open="detailOpen">
            <DialogPortal>
                <DialogOverlay class="inset-0 bg-black/80 fixed z-50" />
                <DialogContent
                    class="max-w-2xl rounded-xl p-6 shadow-lg fixed top-1/2 left-1/2 z-50 max-h-[90vh] w-[92vw] -translate-x-1/2 -translate-y-1/2 overflow-auto border border-border bg-card text-card-foreground outline-none"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <DialogTitle
                            class="text-lg font-semibold tracking-tight leading-none"
                        >
                            {{ t('kinetix.logs_detail') }}
                        </DialogTitle>
                        <DialogClose
                            :class="
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                })
                            "
                        >
                            <X class="h-4 w-4" />
                        </DialogClose>
                    </div>

                    <!-- Webhook detail -->
                    <div v-if="detailWebhook" class="space-y-4 text-sm">
                        <div class="gap-x-6 gap-y-1 grid grid-cols-[auto_1fr]">
                            <span class="text-muted-foreground">{{
                                t('kinetix.logs_event')
                            }}</span>
                            <span class="font-medium">{{
                                detailWebhook.event
                            }}</span>
                            <span class="text-muted-foreground">{{
                                t('kinetix.logs_endpoint')
                            }}</span>
                            <span class="font-mono text-xs break-all">
                                {{
                                    detailWebhook.endpointUrl ??
                                    detailWebhook.endpointName ??
                                    '—'
                                }}
                            </span>
                            <span class="text-muted-foreground">{{
                                t('kinetix.logs_status')
                            }}</span>
                            <span>
                                <span
                                    class="px-1.5 py-0.5 font-semibold rounded-md text-[11px] tabular-nums"
                                    :class="statusClass(detailWebhook.success)"
                                >
                                    {{ detailWebhook.statusCode ?? '—' }}
                                </span>
                                <span class="ml-2 text-muted-foreground">
                                    {{ t('kinetix.logs_attempt') }} #{{
                                        detailWebhook.attempt
                                    }}
                                </span>
                            </span>
                            <span class="text-muted-foreground">{{
                                t('kinetix.logs_time')
                            }}</span>
                            <span>{{
                                formatTime(detailWebhook.createdAt)
                            }}</span>
                        </div>

                        <div>
                            <p
                                class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
                            >
                                {{ t('kinetix.logs_payload') }}
                            </p>
                            <pre
                                class="max-h-56 rounded-lg p-3 font-mono text-xs overflow-auto border border-border bg-muted/40"
                                >{{ pretty(detailWebhook.payload) }}</pre
                            >
                        </div>
                        <div>
                            <p
                                class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
                            >
                                {{ t('kinetix.logs_response') }}
                            </p>
                            <pre
                                class="max-h-40 rounded-lg p-3 font-mono text-xs overflow-auto border border-border bg-muted/40"
                                >{{ pretty(detailWebhook.response) }}</pre
                            >
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="button"
                                :class="
                                    buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    })
                                "
                                @click="redeliver(detailWebhook)"
                            >
                                {{ t('kinetix.logs_redeliver') }}
                            </button>
                        </div>
                    </div>

                    <!-- API request detail -->
                    <div v-else-if="detailApi" class="space-y-4 text-sm">
                        <div class="gap-x-6 gap-y-1 grid grid-cols-[auto_1fr]">
                            <span class="text-muted-foreground">{{
                                t('kinetix.logs_request')
                            }}</span>
                            <span class="font-mono text-xs break-all">
                                {{ detailApi.method }} {{ detailApi.path }}
                            </span>
                            <span class="text-muted-foreground">{{
                                t('kinetix.logs_status')
                            }}</span>
                            <span>
                                <span
                                    class="px-1.5 py-0.5 font-semibold rounded-md text-[11px] tabular-nums"
                                    :class="statusClass(detailApi.status < 400)"
                                >
                                    {{ detailApi.status }}
                                </span>
                                <span
                                    v-if="detailApi.durationMs !== null"
                                    class="ml-2 text-muted-foreground tabular-nums"
                                >
                                    {{ detailApi.durationMs }} ms
                                </span>
                            </span>
                            <span class="text-muted-foreground">{{
                                t('kinetix.logs_token')
                            }}</span>
                            <span>{{ detailApi.tokenName ?? '—' }}</span>
                            <span class="text-muted-foreground">IP</span>
                            <span class="font-mono text-xs">{{
                                detailApi.ip ?? '—'
                            }}</span>
                            <span class="text-muted-foreground">{{
                                t('kinetix.logs_time')
                            }}</span>
                            <span>{{ formatTime(detailApi.createdAt) }}</span>
                        </div>

                        <div>
                            <p
                                class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
                            >
                                {{ t('kinetix.logs_request_body') }}
                            </p>
                            <pre
                                class="max-h-56 rounded-lg p-3 font-mono text-xs overflow-auto border border-border bg-muted/40"
                                >{{ pretty(detailApi.requestBody) }}</pre
                            >
                        </div>
                        <div>
                            <p
                                class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
                            >
                                {{ t('kinetix.logs_response_body') }}
                            </p>
                            <pre
                                class="max-h-40 rounded-lg p-3 font-mono text-xs overflow-auto border border-border bg-muted/40"
                                >{{ pretty(detailApi.responseBody) }}</pre
                            >
                        </div>
                    </div>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>
    </div>
</template>
