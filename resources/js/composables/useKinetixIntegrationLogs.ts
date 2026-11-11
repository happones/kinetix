import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { ComputedRef, Ref, WritableComputedRef } from 'vue';
import {
    KINETIX_SUPERSEDED,
    createKinetixLatest,
} from '@/composables/kinetixLatest';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixApiLog,
    KinetixSharedProps,
    KinetixWebhookLog,
} from '@/types/kinetix';

export type IntegrationLogTab = 'webhooks' | 'api';
export type IntegrationLogResult = 'all' | 'success' | 'failed';

interface Paginated<T> {
    data: T[];
    pagination: { current_page: number; last_page: number; total: number };
}

export interface UseKinetixIntegrationLogs {
    tab: Ref<IntegrationLogTab>;
    tabs: ComputedRef<IntegrationLogTab[]>;
    result: Ref<IntegrationLogResult>;
    search: Ref<string>;
    pageNumber: Ref<number>;
    lastPage: Ref<number>;
    total: Ref<number>;
    loading: Ref<boolean>;
    webhookLogs: Ref<KinetixWebhookLog[]>;
    apiLogs: Ref<KinetixApiLog[]>;
    detailWebhook: Ref<KinetixWebhookLog | null>;
    detailApi: Ref<KinetixApiLog | null>;
    detailOpen: WritableComputedRef<boolean>;
    load: () => Promise<void>;
    redeliver: (log: KinetixWebhookLog) => Promise<void>;
}

/**
 * Data layer for the integration-logs viewer: the two paginated feeds
 * (webhook deliveries + token-authenticated API requests), their shared
 * filters (result/search/page), and the detail-modal + redelivery actions.
 * Fetching state lives here so the component stays presentational.
 */
export function useKinetixIntegrationLogs(options: {
    only: () => 'webhooks' | 'api' | null;
}): UseKinetixIntegrationLogs {
    const page = usePage<KinetixSharedProps>();
    const base = computed(() => `/${kinetixRoutePrefix(page)}`);

    const tab = ref<IntegrationLogTab>(options.only() ?? 'webhooks');
    const tabs = computed<IntegrationLogTab[]>(() =>
        options.only()
            ? [options.only() as IntegrationLogTab]
            : ['webhooks', 'api'],
    );

    const result = ref<IntegrationLogResult>('all');
    const search = ref('');
    const pageNumber = ref(1);
    const lastPage = ref(1);
    const total = ref(0);
    const latest = createKinetixLatest();
    const loading = latest.pending;

    const webhookLogs = ref<KinetixWebhookLog[]>([]);
    const apiLogs = ref<KinetixApiLog[]>([]);

    async function load(): Promise<void> {
        const params = new URLSearchParams({ page: String(pageNumber.value) });

        if (result.value !== 'all') {
            params.set('result', result.value);
        }

        if (search.value.trim() !== '') {
            params.set('search', search.value.trim());
        }

        // Tab, result filter, search and page all trigger a load: only the
        // newest answer may land, whatever order the responses arrive in.
        const isWebhooks = tab.value === 'webhooks';
        const url = isWebhooks
            ? `${base.value}/webhooks/logs?${params}`
            : `${base.value}/api-logs?${params}`;

        const res = await latest.run((signal) =>
            kinetixFetch<Paginated<KinetixWebhookLog | KinetixApiLog>>(url, {
                signal,
            }),
        );

        if (res === KINETIX_SUPERSEDED) {
            return;
        }

        if (isWebhooks) {
            webhookLogs.value = (res?.data ?? []) as KinetixWebhookLog[];
        } else {
            apiLogs.value = (res?.data ?? []) as KinetixApiLog[];
        }

        lastPage.value = res?.pagination.last_page ?? 1;
        total.value = res?.pagination.total ?? 0;
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

    // A pending search is a read-only fetch, so it is dropped on unmount rather
    // than firing into a component that is already gone.
    onBeforeUnmount(() => {
        clearTimeout(searchTimer);
        latest.cancel();
    });

    // --- Detail modal --------------------------------------------------------
    const detailWebhook = ref<KinetixWebhookLog | null>(null);
    const detailApi = ref<KinetixApiLog | null>(null);
    const detailOpen = computed<boolean>({
        get: () => detailWebhook.value !== null || detailApi.value !== null,
        set: (open: boolean) => {
            if (!open) {
                detailWebhook.value = null;
                detailApi.value = null;
            }
        },
    });

    async function redeliver(log: KinetixWebhookLog): Promise<void> {
        await kinetixFetch(`${base.value}/webhooks/logs/${log.id}/redeliver`, {
            method: 'POST',
        });
        detailWebhook.value = null;
        await load();
    }

    return {
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
    };
}
