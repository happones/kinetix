<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    tabsListClass,
    tabsTriggerClass,
} from '@/composables/useKinetixShadcnVariants';
import KinetixReportLauncher from './KinetixReportLauncher.vue';
import KinetixReportRunsTable from './KinetixReportRunsTable.vue';
import KinetixReportSchedules from './KinetixReportSchedules.vue';

/**
 * All-in-one Reports Center: tabs between the report launcher, the runs
 * table, and the scheduled reports list. Each is also usable standalone
 * (`<KinetixReportLauncher>`/`<KinetixReportRunsTable>`/
 * `<KinetixReportSchedules>`) — both entry points are valid.
 */
const { t } = useI18n();
const active = ref('launcher');
</script>

<template>
    <TabsRoot v-model="active" class="w-full">
        <TabsList :class="tabsListClass">
            <TabsTrigger value="launcher" :class="tabsTriggerClass">
                {{ t('kinetix.report_launcher_title') }}
            </TabsTrigger>
            <TabsTrigger value="runs" :class="tabsTriggerClass">
                {{ t('kinetix.report_runs_title') }}
            </TabsTrigger>
            <TabsTrigger value="schedules" :class="tabsTriggerClass">
                {{ t('kinetix.report_schedules_title') }}
            </TabsTrigger>
        </TabsList>

        <TabsContent value="launcher" class="mt-4 focus-visible:outline-none">
            <KinetixReportLauncher />
        </TabsContent>
        <TabsContent value="runs" class="mt-4 focus-visible:outline-none">
            <KinetixReportRunsTable />
        </TabsContent>
        <TabsContent value="schedules" class="mt-4 focus-visible:outline-none">
            <KinetixReportSchedules />
        </TabsContent>
    </TabsRoot>
</template>
