<script setup lang="ts">
import { TabsRoot, TabsList, TabsTrigger, TabsContent } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { schemaHasError } from '@/composables/useKinetixFormErrors';
import { resolveIcon } from '@/composables/useKinetixIcons';
import {
    gridColumnVars,
    resolveColumns,
} from '@/composables/useKinetixResponsiveGrid';
import KinetixFormSchema from './KinetixFormSchema.vue';

/**
 * Renders a `tabs` layout component: a Reka UI tab strip whose panels each
 * recurse back into KinetixFormSchema. Active tab state is local to this
 * instance (so multiple tab groups on one form stay independent).
 *
 * Validation-aware: a tab whose fields hold an error shows a marker on its
 * trigger, and when errors arrive the strip switches to the first offending tab
 * (unless the current one already has an error) so the message is never hidden.
 */
const props = defineProps<{
    tabs: any[];
    values: Record<string, any>;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'update:value', name: string, value: any): void;
}>();

const active = ref('0');

const errorKeys = computed(() => Object.keys(props.errors ?? {}));

const tabHasError = (index: number): boolean =>
    schemaHasError(props.tabs[index]?.schema, errorKeys.value);

// Reveal the first tab carrying an error whenever the error set changes — but
// don't yank the user off a tab that already has one (e.g. live validation of a
// field they're editing).
watch(
    errorKeys,
    (keys) => {
        if (keys.length === 0 || tabHasError(Number(active.value))) {
            return;
        }

        const firstBad = props.tabs.findIndex((_, i) => tabHasError(i));

        if (firstBad !== -1) {
            active.value = String(firstBad);
        }
    },
    { deep: true },
);
</script>

<template>
    <TabsRoot v-model="active" class="w-full">
        <TabsList
            class="h-9 rounded-lg p-1 inline-flex items-center justify-center bg-muted text-muted-foreground"
        >
            <TabsTrigger
                v-for="(tab, index) in props.tabs"
                :key="index"
                :value="String(index)"
                :aria-invalid="tabHasError(index) || undefined"
                class="gap-1.5 px-3 py-1 text-sm font-medium data-[state=active]:shadow-sm inline-flex items-center rounded-md whitespace-nowrap transition-all focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 aria-[invalid=true]:text-destructive data-[state=active]:bg-background data-[state=active]:text-foreground"
            >
                <component
                    :is="resolveIcon(tab.icon)"
                    v-if="resolveIcon(tab.icon)"
                    class="size-4"
                />
                {{ tab.heading }}
                <!-- Error marker: a destructive dot on tabs with an error. -->
                <span
                    v-if="tabHasError(index)"
                    class="size-1.5 ml-0.5 rounded-full bg-destructive"
                    aria-hidden="true"
                />
            </TabsTrigger>
        </TabsList>

        <TabsContent
            v-for="(tab, index) in props.tabs"
            :key="index"
            :value="String(index)"
            class="kinetix-grid-host mt-4 focus-visible:outline-none"
        >
            <div
                class="kinetix-grid gap-4 grid"
                :style="gridColumnVars(resolveColumns(tab.columns))"
            >
                <KinetixFormSchema
                    :schema="tab.schema"
                    :values="values"
                    :errors="errors"
                    :parent-columns="resolveColumns(tab.columns)"
                    @update:value="
                        (name, val) => emit('update:value', name, val)
                    "
                />
            </div>
        </TabsContent>
    </TabsRoot>
</template>
