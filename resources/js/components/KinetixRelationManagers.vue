<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type { KinetixRelationManagerData } from '@/types/kinetix';
import KinetixRelationManager from './KinetixRelationManager.vue';
import KinetixTable from './KinetixTable.vue';

/**
 * The relation managers HOST for a resource page — pass everything
 * `relationManagersFor()` returned and it picks the right layout, exactly
 * like Filament:
 *
 * - one manager  → a plain section (heading + table);
 * - several      → an automatic TAB per manager (title + optional badge),
 *   rendering only the active one. Table state (search/sort/page) lives in
 *   namespaced query params (`{relationship}_…`), so switching tabs never
 *   clobbers another manager's state.
 *
 * Set `tabs: false` to force the stacked layout regardless of count.
 */
const props = withDefaults(
    defineProps<{
        managers: KinetixRelationManagerData[];
        /** Auto-tab when more than one manager (default). false = stack. */
        tabs?: boolean;
    }>(),
    {
        tabs: true,
    },
);

const useTabs = computed(() => props.tabs && props.managers.length > 1);

const active = ref(props.managers[0]?.relationship ?? '');

// Keep the active tab valid when the manager list changes (e.g. an Inertia
// reload dropping a manager the record no longer allows).
watch(
    () => props.managers,
    (managers) => {
        if (!managers.some((m) => m.relationship === active.value)) {
            active.value = managers[0]?.relationship ?? '';
        }
    },
);

const activeManager = computed(
    () =>
        props.managers.find((m) => m.relationship === active.value) ??
        props.managers[0],
);

const badgeClass = (color?: string | null): string =>
    statusBadgeClass((color ?? 'gray') as KinetixStatusColor);
</script>

<template>
    <div v-if="managers.length > 0">
        <!-- Several managers: auto-tabs (Filament-style) -->
        <div v-if="useTabs" class="space-y-4">
            <div
                role="tablist"
                class="h-9 rounded-lg p-1 gap-1 inline-flex max-w-full items-center overflow-x-auto bg-muted text-muted-foreground"
            >
                <button
                    v-for="manager in managers"
                    :key="manager.relationship"
                    type="button"
                    role="tab"
                    :aria-selected="manager.relationship === active"
                    :data-state="
                        manager.relationship === active ? 'active' : 'inactive'
                    "
                    class="gap-1.5 px-3 py-1 text-sm font-medium data-[state=active]:shadow-sm inline-flex cursor-pointer touch-manipulation items-center rounded-md whitespace-nowrap transition-all focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none data-[state=active]:bg-background data-[state=active]:text-foreground"
                    @click="active = manager.relationship"
                >
                    {{ manager.title }}
                    <span
                        v-if="
                            manager.badge !== null &&
                            manager.badge !== undefined
                        "
                        class="px-1.5 py-0.5 font-semibold inline-flex items-center rounded-full text-[11px]"
                        :class="badgeClass(manager.badgeColor)"
                    >
                        {{ manager.badge }}
                    </span>
                </button>
            </div>

            <div
                v-if="activeManager"
                :key="activeManager.relationship"
                role="tabpanel"
            >
                <KinetixTable :table="activeManager.table" />
            </div>
        </div>

        <!-- One manager (or tabs disabled): stacked sections -->
        <div v-else class="space-y-8">
            <KinetixRelationManager
                v-for="manager in managers"
                :key="manager.relationship"
                :manager="manager"
            />
        </div>
    </div>
</template>
