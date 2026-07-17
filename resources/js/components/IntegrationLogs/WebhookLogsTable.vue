<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { formatTime, statusClass } from '@/composables/kinetixLogFormat';
import type { KinetixWebhookLog } from '@/types';

defineProps<{
    logs: KinetixWebhookLog[];
    loading: boolean;
}>();

const emit = defineEmits<{ (e: 'select', log: KinetixWebhookLog): void }>();

const { t } = useI18n();
</script>

<template>
    <div class="rounded-xl overflow-x-auto border border-border">
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
                    v-for="log in logs"
                    :key="String(log.id)"
                    class="cursor-pointer border-b border-border last:border-0 hover:bg-accent/50"
                    @click="emit('select', log)"
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
                    <td class="px-3 py-2 text-muted-foreground tabular-nums">
                        #{{ log.attempt }}
                    </td>
                    <td
                        class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                    >
                        {{ formatTime(log.createdAt) }}
                    </td>
                </tr>
                <tr v-if="!loading && logs.length === 0">
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
</template>
