<script setup lang="ts">
import { X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { useI18n } from 'vue-i18n';
import {
    formatTime,
    pretty,
    statusClass,
} from '@/composables/kinetixLogFormat';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixApiLog, KinetixWebhookLog } from '@/types/kinetix';

defineProps<{
    open: boolean;
    webhook: KinetixWebhookLog | null;
    api: KinetixApiLog | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', open: boolean): void;
    (e: 'redeliver', log: KinetixWebhookLog): void;
}>();

const { t } = useI18n();
</script>

<template>
    <DialogRoot :open="open" @update:open="emit('update:open', $event)">
        <DialogPortal>
            <DialogOverlay
                class="inset-0 bg-black/80 fixed z-[var(--kinetix-z-overlay,100)]"
            />
            <DialogContent
                class="max-w-2xl rounded-xl p-6 shadow-lg fixed top-1/2 left-1/2 z-[var(--kinetix-z-modal,100)] max-h-[90vh] w-[92vw] -translate-x-1/2 -translate-y-1/2 overflow-auto border border-border bg-card text-card-foreground outline-none"
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
                <div v-if="webhook" class="space-y-4 text-sm">
                    <div class="gap-x-6 gap-y-1 grid grid-cols-[auto_1fr]">
                        <span class="text-muted-foreground">{{
                            t('kinetix.logs_event')
                        }}</span>
                        <span class="font-medium">{{ webhook.event }}</span>
                        <span class="text-muted-foreground">{{
                            t('kinetix.logs_endpoint')
                        }}</span>
                        <span class="font-mono text-xs break-all">
                            {{
                                webhook.endpointUrl ??
                                webhook.endpointName ??
                                '—'
                            }}
                        </span>
                        <span class="text-muted-foreground">{{
                            t('kinetix.logs_status')
                        }}</span>
                        <span>
                            <span
                                class="px-1.5 py-0.5 font-semibold rounded-md text-[11px] tabular-nums"
                                :class="statusClass(webhook.success)"
                            >
                                {{ webhook.statusCode ?? '—' }}
                            </span>
                            <span class="ml-2 text-muted-foreground">
                                {{ t('kinetix.logs_attempt') }} #{{
                                    webhook.attempt
                                }}
                            </span>
                        </span>
                        <span class="text-muted-foreground">{{
                            t('kinetix.logs_time')
                        }}</span>
                        <span>{{ formatTime(webhook.createdAt) }}</span>
                    </div>

                    <div>
                        <p
                            class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
                        >
                            {{ t('kinetix.logs_payload') }}
                        </p>
                        <pre
                            class="max-h-56 rounded-lg p-3 font-mono text-xs overflow-auto border border-border bg-muted/40"
                            >{{ pretty(webhook.payload) }}</pre
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
                            >{{ pretty(webhook.response) }}</pre
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
                            @click="emit('redeliver', webhook)"
                        >
                            {{ t('kinetix.logs_redeliver') }}
                        </button>
                    </div>
                </div>

                <!-- API request detail -->
                <div v-else-if="api" class="space-y-4 text-sm">
                    <div class="gap-x-6 gap-y-1 grid grid-cols-[auto_1fr]">
                        <span class="text-muted-foreground">{{
                            t('kinetix.logs_request')
                        }}</span>
                        <span class="font-mono text-xs break-all">
                            {{ api.method }} {{ api.path }}
                        </span>
                        <span class="text-muted-foreground">{{
                            t('kinetix.logs_status')
                        }}</span>
                        <span>
                            <span
                                class="px-1.5 py-0.5 font-semibold rounded-md text-[11px] tabular-nums"
                                :class="statusClass(api.status < 400)"
                            >
                                {{ api.status }}
                            </span>
                            <span
                                v-if="api.durationMs !== null"
                                class="ml-2 text-muted-foreground tabular-nums"
                            >
                                {{ api.durationMs }} ms
                            </span>
                        </span>
                        <span class="text-muted-foreground">{{
                            t('kinetix.logs_token')
                        }}</span>
                        <span>{{ api.tokenName ?? '—' }}</span>
                        <span class="text-muted-foreground">IP</span>
                        <span class="font-mono text-xs">{{
                            api.ip ?? '—'
                        }}</span>
                        <span class="text-muted-foreground">{{
                            t('kinetix.logs_time')
                        }}</span>
                        <span>{{ formatTime(api.createdAt) }}</span>
                    </div>

                    <div>
                        <p
                            class="mb-1 text-xs font-semibold text-muted-foreground uppercase"
                        >
                            {{ t('kinetix.logs_request_body') }}
                        </p>
                        <pre
                            class="max-h-56 rounded-lg p-3 font-mono text-xs overflow-auto border border-border bg-muted/40"
                            >{{ pretty(api.requestBody) }}</pre
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
                            >{{ pretty(api.responseBody) }}</pre
                        >
                    </div>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
