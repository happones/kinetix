<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import { useKinetixWebhooks } from '@/composables/useKinetixWebhooks';
import type {
    KinetixWebhookEndpoint,
    KinetixWebhookLog,
} from '@/types/kinetix';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixLabel from './KinetixLabel.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';

/**
 * Drop-in customer webhook dashboard: register/edit endpoints, pick subscribed
 * events, rotate the secret, send a test event, and inspect delivery logs. Gate
 * it behind the `webhooks.manage` ability where you mount it.
 */
const { t } = useI18n();
const {
    endpoints,
    availableEvents,
    load,
    create,
    update,
    remove,
    rotate,
    test,
    logs,
} = useKinetixWebhooks();

const editing = ref<KinetixWebhookEndpoint | null>(null);
const openLogsFor = ref<string | number | null>(null);
const logEntries = ref<KinetixWebhookLog[]>([]);

// O(1) membership for the per-event checkbox state in the edit form.
const editingEventSet = computed<Set<string>>(
    () => new Set((editing.value?.events ?? []).map((e) => String(e))),
);

onMounted(load);

function blank(): KinetixWebhookEndpoint {
    return {
        id: null,
        name: '',
        url: '',
        events: [],
        active: true,
        createdAt: null,
    };
}

function startCreate(): void {
    editing.value = blank();
}

function edit(endpoint: KinetixWebhookEndpoint): void {
    editing.value = { ...endpoint, events: [...endpoint.events] };
}

function toggleEvent(name: string, checked: boolean): void {
    if (!editing.value) {
        return;
    }

    editing.value.events = checked
        ? [...editing.value.events, name]
        : editing.value.events.filter((e) => e !== name);
}

async function save(): Promise<void> {
    if (!editing.value) {
        return;
    }

    const payload = {
        name: editing.value.name,
        url: editing.value.url,
        events: editing.value.events,
        active: editing.value.active,
    };

    try {
        if (editing.value.id) {
            await update(editing.value, payload);
            toast.success(t('kinetix.saved'));
        } else {
            const result = await create(payload);

            if (result?.secret) {
                toast.success(
                    t('kinetix.webhook_secret_shown', {
                        secret: result.secret,
                    }),
                );
            }
        }

        editing.value = null;
        await load();
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : t('kinetix.save_failed'),
        );
    }
}

async function onDelete(endpoint: KinetixWebhookEndpoint): Promise<void> {
    try {
        await remove(endpoint);
        await load();
        toast.success(t('kinetix.deleted'));
    } catch {
        toast.error(t('kinetix.delete_failed'));
    }
}

async function onRotate(endpoint: KinetixWebhookEndpoint): Promise<void> {
    const result = await rotate(endpoint);

    if (result?.secret) {
        toast.success(
            t('kinetix.webhook_secret_shown', { secret: result.secret }),
        );
    }
}

async function onTest(endpoint: KinetixWebhookEndpoint): Promise<void> {
    await test(endpoint);
    toast.success(t('kinetix.webhook_test_queued'));
}

async function viewLogs(endpoint: KinetixWebhookEndpoint): Promise<void> {
    if (openLogsFor.value === endpoint.id) {
        openLogsFor.value = null;

        return;
    }

    openLogsFor.value = endpoint.id;
    logEntries.value = await logs(endpoint);
}

const logColor = (success: boolean): KinetixStatusColor =>
    success ? 'success' : 'danger';
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-foreground">
                {{ t('kinetix.webhooks_title') }}
            </h2>
            <button
                v-if="!editing"
                type="button"
                :class="buttonVariants({ size: 'sm' })"
                @click="startCreate"
            >
                {{ t('kinetix.webhook_add') }}
            </button>
        </div>

        <!-- Editor -->
        <div
            v-if="editing"
            class="space-y-3 rounded-lg p-4 border border-border bg-card"
        >
            <div class="space-y-2">
                <KinetixLabel for="wh-name">{{
                    t('kinetix.webhook_name')
                }}</KinetixLabel>
                <input
                    id="wh-name"
                    v-model="editing.name"
                    type="text"
                    :class="inputClass"
                />
            </div>
            <div class="space-y-2">
                <KinetixLabel for="wh-url">{{
                    t('kinetix.webhook_url')
                }}</KinetixLabel>
                <input
                    id="wh-url"
                    v-model="editing.url"
                    type="url"
                    placeholder="https://"
                    :class="inputClass"
                />
            </div>

            <div class="space-y-2">
                <KinetixLabel>{{ t('kinetix.webhook_events') }}</KinetixLabel>
                <label
                    v-for="(label, name) in availableEvents"
                    :key="name"
                    class="gap-2 text-sm flex items-center text-foreground"
                >
                    <KinetixCheckbox
                        :checked="editingEventSet.has(String(name))"
                        @change="toggleEvent(String(name), $event)"
                    />
                    <span
                        >{{ label }}
                        <span class="text-muted-foreground"
                            >({{ name }})</span
                        ></span
                    >
                </label>
            </div>

            <label class="gap-2 text-sm flex items-center text-foreground">
                <KinetixCheckbox
                    :checked="editing.active"
                    @change="editing.active = $event"
                />
                {{ t('kinetix.webhook_active') }}
            </label>

            <div class="gap-2 flex justify-end">
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    @click="editing = null"
                >
                    {{ t('kinetix.cancel') }}
                </button>
                <button
                    type="button"
                    :class="buttonVariants({ size: 'sm' })"
                    @click="save"
                >
                    {{ t('kinetix.save') }}
                </button>
            </div>
        </div>

        <!-- List -->
        <div
            v-else
            class="rounded-lg divide-y divide-border border border-border bg-card"
        >
            <p
                v-if="endpoints.length === 0"
                class="p-4 text-sm text-muted-foreground"
            >
                {{ t('kinetix.no_webhooks') }}
            </p>

            <div
                v-for="endpoint in endpoints"
                :key="String(endpoint.id)"
                class="p-3"
            >
                <div class="gap-2 flex flex-wrap items-center justify-between">
                    <div class="min-w-0">
                        <span class="text-sm font-medium text-foreground">{{
                            endpoint.name
                        }}</span>
                        <span
                            class="ml-2 text-xs truncate text-muted-foreground"
                            >{{ endpoint.url }}</span
                        >
                    </div>
                    <div class="gap-2 flex items-center">
                        <span
                            v-if="!endpoint.active"
                            class="px-2 py-0.5 text-xs rounded-full bg-muted text-muted-foreground"
                        >
                            {{ t('kinetix.webhook_inactive') }}
                        </span>
                        <button
                            :class="
                                buttonVariants({ variant: 'ghost', size: 'sm' })
                            "
                            @click="onTest(endpoint)"
                        >
                            {{ t('kinetix.webhook_test') }}
                        </button>
                        <button
                            :class="
                                buttonVariants({ variant: 'ghost', size: 'sm' })
                            "
                            @click="onRotate(endpoint)"
                        >
                            {{ t('kinetix.webhook_rotate') }}
                        </button>
                        <button
                            :class="
                                buttonVariants({ variant: 'ghost', size: 'sm' })
                            "
                            @click="viewLogs(endpoint)"
                        >
                            {{ t('kinetix.webhook_logs') }}
                        </button>
                        <button
                            :class="
                                buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })
                            "
                            @click="edit(endpoint)"
                        >
                            {{ t('kinetix.edit') }}
                        </button>
                        <button
                            :class="
                                buttonVariants({ variant: 'ghost', size: 'sm' })
                            "
                            @click="onDelete(endpoint)"
                        >
                            {{ t('kinetix.delete') }}
                        </button>
                    </div>
                </div>

                <!-- Logs -->
                <div v-if="openLogsFor === endpoint.id" class="mt-3 space-y-1">
                    <p
                        v-if="logEntries.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('kinetix.activity_empty') }}
                    </p>
                    <div
                        v-for="log in logEntries"
                        :key="String(log.id)"
                        class="gap-2 text-xs flex items-center justify-between"
                    >
                        <span class="font-mono text-foreground">{{
                            log.event
                        }}</span>
                        <KinetixBadge :color="logColor(log.success)">
                            {{ log.statusCode ?? '—' }}
                        </KinetixBadge>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
