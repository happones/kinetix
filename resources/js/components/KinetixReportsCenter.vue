<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
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
        <TabsList
            class="h-9 rounded-lg p-1 inline-flex items-center justify-center bg-muted text-muted-foreground"
        >
            <TabsTrigger
                value="launcher"
                class="px-3 py-1 text-sm font-medium data-[state=active]:shadow-sm inline-flex items-center rounded-md whitespace-nowrap transition-all focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground"
            >
                {{ t('kinetix.report_launcher_title') }}
            </TabsTrigger>
            <TabsTrigger
                value="runs"
                class="px-3 py-1 text-sm font-medium data-[state=active]:shadow-sm inline-flex items-center rounded-md whitespace-nowrap transition-all focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground"
            >
                {{ t('kinetix.report_runs_title') }}
            </TabsTrigger>
            <TabsTrigger
                value="schedules"
                class="px-3 py-1 text-sm font-medium data-[state=active]:shadow-sm inline-flex items-center rounded-md whitespace-nowrap transition-all focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground"
            >
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
