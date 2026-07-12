<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixReportRuns } from '@/composables/useKinetixReportRuns';
import KinetixTable from './KinetixTable.vue';

/**
 * Live table of report runs — status/progress/download/cancel/retry,
 * "failed jobs"-style. Self-polls on an interval (`kinetix.reports_center.poll`);
 * row actions (download/cancel/retry) are ordinary `Action`s already wired
 * through `<KinetixTable>`'s existing action plumbing.
 */
const { t } = useI18n();
const { table, loading, start } = useKinetixReportRuns();

onMounted(start);
</script>

<template>
    <div>
        <KinetixTable v-if="table" :table="table" />
        <p v-else-if="loading" class="text-sm text-muted-foreground">
            {{ t('kinetix.report_runs_loading') }}
        </p>
    </div>
</template>
