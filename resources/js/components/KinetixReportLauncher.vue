<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixReportTypes } from '@/composables/useKinetixReportTypes';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { ReportTypeData } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardDescription from './primitives/CardDescription.vue';
import CardFooter from './primitives/CardFooter.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';

/**
 * Lists every registered `Report` type (auto-discovered server-side from
 * `kinetix.reports_center.discover_path`) as a card with a "Run now" button.
 * Launching creates a tracked `ReportRun` row — check
 * `<KinetixReportRunsTable>` for its progress.
 */
const { t } = useI18n();
const { types, loading, load, launch } = useKinetixReportTypes();

onMounted(load);

const launching = ref<string | null>(null);

async function runNow(type: ReportTypeData): Promise<void> {
    launching.value = type.token;

    try {
        await launch(type.token);
        toast.success(t('kinetix.report_launched'));
    } catch {
        toast.error(t('kinetix.report_launch_failed'));
    } finally {
        launching.value = null;
    }
}
</script>

<template>
    <div class="gap-4 sm:grid-cols-2 lg:grid-cols-3 grid">
        <Card v-for="type in types" :key="type.token">
            <CardHeader>
                <CardTitle>{{ type.label }}</CardTitle>
                <CardDescription v-if="type.description">
                    {{ type.description }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <span
                    class="text-xs font-medium text-muted-foreground uppercase"
                >
                    {{ type.format }}
                </span>
            </CardContent>
            <CardFooter>
                <button
                    type="button"
                    :class="buttonVariants({ size: 'sm' })"
                    :disabled="launching === type.token"
                    @click="runNow(type)"
                >
                    {{ t('kinetix.run_now') }}
                </button>
            </CardFooter>
        </Card>

        <p
            v-if="!loading && types.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ t('kinetix.report_launcher_empty') }}
        </p>
    </div>
</template>
