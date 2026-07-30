<script setup lang="ts">
import { CheckCircle2, CircleAlert, CircleX, HeartPulse } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixHealth } from '@/composables/useKinetixHealth';
import type { KinetixWidget } from '@/types/kinetix';

/**
 * A compact, live application-health widget powered by spatie/laravel-health.
 * Shows an overall status badge and a list of checks with their status. Polls on
 * the shared interval. Complements (doesn't replace) the health dashboard.
 *
 * Accepts (and ignores) an optional `widget` prop so it can be placed inside
 * a `<KinetixWidgetsGrid>` via `HealthStatusWidget` — it keeps self-polling
 * via `useKinetixHealth()` regardless, rather than reading from `widget.data`.
 */
defineProps<{ widget?: KinetixWidget }>();

const { t } = useI18n();
const { snapshot, failed, start } = useKinetixHealth();

onMounted(start);

const overall = computed(() => snapshot.value?.status ?? null);

function toneClass(status: string | null): string {
    switch (status) {
        case 'ok':
            return 'bg-green-500/10 text-green-600 dark:text-green-400';
        case 'warning':
            return 'bg-amber-500/10 text-amber-600 dark:text-amber-400';
        case 'failed':
        case 'crashed':
            return 'bg-red-500/10 text-red-600 dark:text-red-400';
        default:
            return 'bg-muted text-muted-foreground';
    }
}

function statusIcon(status: string): unknown {
    switch (status) {
        case 'ok':
            return CheckCircle2;
        case 'warning':
            return CircleAlert;
        default:
            return CircleX;
    }
}

function iconTone(status: string): string {
    switch (status) {
        case 'ok':
            return 'text-green-500';
        case 'warning':
            return 'text-amber-500';
        default:
            return 'text-red-500';
    }
}
</script>

<template>
    <div class="rounded-xl p-4 shadow-sm border border-border bg-card">
        <div class="mb-4 flex items-center justify-between">
            <h3
                class="gap-2 text-sm font-semibold flex items-center text-foreground"
            >
                <HeartPulse class="size-4 text-muted-foreground" />
                {{ t('kinetix.health_title') }}
            </h3>
            <span
                v-if="overall"
                class="px-2 py-0.5 text-xs font-medium rounded-full"
                :class="toneClass(overall)"
            >
                {{ t(`kinetix.health_status_${overall}`) }}
            </span>
        </div>

        <p
            v-if="
                (failed || snapshot?.available === false) &&
                !snapshot?.checks?.length
            "
            class="text-sm text-muted-foreground"
        >
            {{ t('kinetix.health_unavailable') }}
        </p>

        <ul v-else class="space-y-1">
            <li
                v-for="check in snapshot?.checks ?? []"
                :key="check.name"
                class="gap-3 px-2 py-1.5 text-sm flex items-center justify-between rounded-md hover:bg-accent/50"
            >
                <span class="min-w-0 gap-2 flex items-center">
                    <component
                        :is="statusIcon(check.status)"
                        class="size-4 shrink-0"
                        :class="iconTone(check.status)"
                    />
                    <span class="font-medium truncate text-foreground">{{
                        check.label
                    }}</span>
                </span>
                <span
                    v-if="check.message"
                    class="text-xs truncate text-muted-foreground"
                    :title="check.message"
                >
                    {{ check.message }}
                </span>
            </li>
        </ul>
    </div>
</template>
