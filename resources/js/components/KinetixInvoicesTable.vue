<script setup lang="ts">
import { Download } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { KinetixInvoice } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        invoices?: KinetixInvoice[];
        /** Build a download href per invoice; when omitted a `download` event fires. */
        downloadUrl?: (invoice: KinetixInvoice) => string;
        /** When true, uses the Stripe-hosted invoice PDF URL directly. */
        useStripeUrl?: boolean;
    }>(),
    {
        invoices: () => [],
        downloadUrl: undefined,
        useStripeUrl: false,
    },
);

const emit = defineEmits<{
    (e: 'download', invoice: KinetixInvoice): void;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ t('kinetix.billing_invoices') }}</CardTitle>
        </CardHeader>

        <CardContent>
            <p
                v-if="invoices.length === 0"
                class="text-sm text-muted-foreground italic"
            >
                {{ t('kinetix.billing_no_invoices') }}
            </p>

            <div v-else class="overflow-x-auto">
                <table class="text-sm w-full">
                    <thead>
                        <tr
                            class="border-b border-border text-left text-muted-foreground"
                        >
                            <th class="py-2 pr-4 font-medium">
                                {{ t('kinetix.billing_date') }}
                            </th>
                            <th class="py-2 pr-4 font-medium">
                                {{ t('kinetix.billing_total') }}
                            </th>
                            <th class="py-2 pr-4 font-medium">
                                {{ t('kinetix.billing_status') }}
                            </th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="invoice in invoices"
                            :key="invoice.id"
                            class="border-b border-border/60 last:border-0"
                        >
                            <td class="py-3 pr-4 text-foreground">
                                {{ invoice.date }}
                            </td>
                            <td class="py-3 pr-4 text-foreground">
                                {{ invoice.total }}
                            </td>
                            <td class="py-3 pr-4">
                                <span
                                    class="px-2 py-0.5 text-xs font-medium inline-flex items-center rounded-md bg-secondary text-secondary-foreground capitalize"
                                >
                                    {{ invoice.status }}
                                </span>
                            </td>
                            <td class="py-3 text-right">
                                <a
                                    v-if="props.useStripeUrl && invoice.url"
                                    :href="invoice.url"
                                    target="_blank"
                                    class="gap-1.5 px-2 py-1 text-sm font-medium inline-flex items-center rounded-md text-foreground hover:bg-accent hover:text-accent-foreground"
                                >
                                    <Download class="h-4 w-4" />
                                </a>
                                <a
                                    v-else-if="downloadUrl"
                                    :href="downloadUrl(invoice)"
                                    class="gap-1.5 px-2 py-1 text-sm font-medium inline-flex items-center rounded-md text-foreground hover:bg-accent hover:text-accent-foreground"
                                >
                                    <Download class="h-4 w-4" />
                                </a>
                                <button
                                    v-else
                                    type="button"
                                    class="gap-1.5 px-2 py-1 text-sm font-medium inline-flex items-center rounded-md text-foreground hover:bg-accent hover:text-accent-foreground"
                                    @click="emit('download', invoice)"
                                >
                                    <Download class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </CardContent>
    </Card>
</template>
