<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixReportSchedules } from '@/composables/useKinetixReportSchedules';
import { useKinetixReportTypes } from '@/composables/useKinetixReportTypes';
import { buttonVariants } from '@/composables/useShadcnVariants';
import KinetixSelect from './KinetixSelect.vue';
import KinetixTable from './KinetixTable.vue';

/**
 * Live table of scheduled/recurring report definitions, plus a compact form
 * to create new ones. Each firing produces a `ReportRun` row visible in
 * `<KinetixReportRunsTable>`. Enable/disable and delete/run-now ride on the
 * generic `<KinetixTable>` toggle-column/action plumbing.
 */
const { t } = useI18n();
const { table, loading, start, create } = useKinetixReportSchedules();
const { types, load: loadTypes } = useKinetixReportTypes();

onMounted(() => {
    start();
    loadTypes();
});

const reportOptions = computed<Record<string, string>>(() =>
    Object.fromEntries(types.value.map((type) => [type.token, type.label])),
);

const frequencyOptions: Record<string, string> = {
    once: 'Once',
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
};

const selectedReport = ref<string | null>(null);
const selectedFrequency = ref<string>('daily');
const creating = ref(false);

async function submit(): Promise<void> {
    if (!selectedReport.value) {
        return;
    }

    creating.value = true;

    try {
        await create({
            report: selectedReport.value,
            frequency: selectedFrequency.value,
        });
        toast.success(t('kinetix.report_schedule_created'));
        selectedReport.value = null;
    } catch {
        toast.error(t('kinetix.report_schedule_create_failed'));
    } finally {
        creating.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div
            class="gap-3 p-4 rounded-lg flex flex-wrap items-end border border-border bg-muted/30"
        >
            <div class="min-w-[12rem] flex-1">
                <label
                    class="mb-1 text-xs font-medium block text-muted-foreground"
                >
                    {{ t('kinetix.report_runs_report_column') }}
                </label>
                <KinetixSelect
                    :value="selectedReport"
                    :options="reportOptions"
                    :placeholder="t('kinetix.report_launcher_title')"
                    @update:value="selectedReport = $event"
                />
            </div>
            <div class="min-w-[10rem]">
                <label
                    class="mb-1 text-xs font-medium block text-muted-foreground"
                >
                    {{ t('kinetix.report_schedule_frequency') }}
                </label>
                <KinetixSelect
                    :value="selectedFrequency"
                    :options="frequencyOptions"
                    @update:value="selectedFrequency = $event"
                />
            </div>
            <button
                type="button"
                :class="buttonVariants({ size: 'sm' })"
                :disabled="!selectedReport || creating"
                @click="submit"
            >
                {{ t('kinetix.report_schedule_create') }}
            </button>
        </div>

        <KinetixTable v-if="table" :table="table" />
        <p v-else-if="loading" class="text-sm text-muted-foreground">
            {{ t('kinetix.report_runs_loading') }}
        </p>
    </div>
</template>
