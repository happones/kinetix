<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { formatTime, statusClass } from '@/composables/kinetixLogFormat';
import type { KinetixApiLog } from '@/types';

defineProps<{
    logs: KinetixApiLog[];
    loading: boolean;
}>();

const emit = defineEmits<{ (e: 'select', log: KinetixApiLog): void }>();

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
                    v-for="log in logs"
                    :key="String(log.id)"
                    class="cursor-pointer border-b border-border last:border-0 hover:bg-accent/50"
                    @click="emit('select', log)"
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
                    <td class="px-3 py-2 text-muted-foreground tabular-nums">
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
