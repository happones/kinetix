<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import type { KinetixRelationManagerData } from '@/types/kinetix';
import KinetixRelationManager from './KinetixRelationManager.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';

/**
 * The relation managers HOST for a resource page — pass everything
 * `relationManagersFor()` returned and it picks the right layout:
 *
 * - one manager  → a plain section (heading + table);
 * - several      → an automatic TAB per manager (title + optional badge),
 *   rendering only the active one. Table state (search/sort/page) lives in
 *   namespaced query params (`{relationship}_…`), so switching tabs never
 *   clobbers another manager's state — and the ACTIVE tab itself lives in
 *   `?relation=…`, so table reloads, modal saves (back()) and shared links
 *   land on the tab the user was on.
 *
 * LAZY managers (`$isLazy`) arrive as tab stubs (no table); the child
 * component requests the full payload on activation via the same
 * `?relation=` param, showing a skeleton meanwhile — nothing to wire here.
 *
 * Set `tabs: false` to force the stacked layout regardless of count. NOTE:
 * with several LAZY managers prefer the tabs layout — the stacked one mounts
 * them all at once and only the last-requested `?relation=` can load.
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

/**
 * One rendered tab: a plain manager, or a GROUP of managers sharing
 * `$group` (their sections stack inside the panel, each with its own
 * heading). A group's badge is the sum of its members' numeric badges.
 */
interface ManagerTab {
    key: string;
    title: string;
    badge: number | string | null;
    badgeColor: string | null;
    isGroup: boolean;
    managers: KinetixRelationManagerData[];
}

const tabs = computed<ManagerTab[]>(() => {
    const list: ManagerTab[] = [];
    const byKey = new Map<string, ManagerTab>();

    for (const manager of props.managers) {
        const key = manager.groupKey ?? manager.relationship;
        let tab = byKey.get(key);

        if (!tab) {
            tab = {
                key,
                title: manager.group ?? manager.title,
                badge: null,
                badgeColor: null,
                isGroup: !!manager.groupKey,
                managers: [],
            };
            byKey.set(key, tab);
            list.push(tab);
        }

        tab.managers.push(manager);
    }

    for (const tab of list) {
        if (!tab.isGroup) {
            tab.badge = tab.managers[0].badge ?? null;
            tab.badgeColor = tab.managers[0].badgeColor ?? null;

            continue;
        }

        const numeric = tab.managers
            .map((m) => m.badge)
            .filter((b): b is number => typeof b === 'number');

        tab.badge = numeric.length
            ? numeric.reduce((sum, b) => sum + b, 0)
            : null;
        tab.badgeColor =
            tab.managers.find((m) => m.badgeColor)?.badgeColor ?? null;
    }

    return list;
});

const useTabs = computed(() => props.tabs && tabs.value.length > 1);

/** The `?relation=` query param persisting the active tab across reloads. */
const TAB_PARAM = 'relation';

const tabFromUrl = (): string | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const requested = new URLSearchParams(window.location.search).get(
        TAB_PARAM,
    );

    // A group member's relationship also lands on its group's tab (lazy
    // members revisit with their own relation param).
    const tab = tabs.value.find(
        (candidate) =>
            candidate.key === requested ||
            candidate.managers.some((m) => m.relationship === requested),
    );

    return tab?.key ?? null;
};

const active = ref(tabFromUrl() ?? tabs.value[0]?.key ?? '');

/**
 * Write the active tab into the URL with a CLIENT-side history replace — no
 * server round-trip, no history-stack spam. Table reloads preserve foreign
 * query params, so `?relation=` survives search/sort/filter visits, and a
 * modal save's `back()` redirect returns to the same URL (same tab).
 */
const selectTab = (relationship: string): void => {
    active.value = relationship;

    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set(TAB_PARAM, relationship);

    try {
        router.replace({
            url: url.pathname + url.search,
            preserveScroll: true,
            preserveState: true,
        });
    } catch {
        window.history.replaceState(
            window.history.state,
            '',
            url.pathname + url.search,
        );
    }
};

// Keep the active tab valid when the manager list changes (e.g. an Inertia
// reload dropping a manager the record no longer allows).
watch(
    () => props.managers,
    () => {
        if (!tabs.value.some((tab) => tab.key === active.value)) {
            active.value = tabs.value[0]?.key ?? '';
        }
    },
);

const activeTab = computed(
    () => tabs.value.find((tab) => tab.key === active.value) ?? tabs.value[0],
);
</script>

<template>
    <div v-if="managers.length > 0">
        <!-- Several managers: auto-tabs -->
        <!-- Reka Tabs: aria-controls wiring + roving tabindex (arrow-key
             navigation) come from the primitive; activation still routes
             through selectTab so ?relation= stays in the URL. -->
        <TabsRoot
            v-if="useTabs"
            :model-value="active"
            class="space-y-4"
            @update:model-value="selectTab(String($event))"
        >
            <TabsList
                class="h-9 rounded-lg p-1 gap-1 inline-flex max-w-full items-center overflow-x-auto bg-muted text-muted-foreground"
            >
                <TabsTrigger
                    v-for="tab in tabs"
                    :key="tab.key"
                    :value="tab.key"
                    class="gap-1.5 px-3 py-1 text-sm font-medium data-[state=active]:shadow-sm inline-flex cursor-pointer touch-manipulation items-center rounded-md whitespace-nowrap transition-all focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none data-[state=active]:bg-background data-[state=active]:text-foreground"
                >
                    {{ tab.title }}
                    <KinetixBadge
                        v-if="tab.badge !== null && tab.badge !== undefined"
                        size="sm"
                        :color="tab.badgeColor"
                    >
                        {{ tab.badge }}
                    </KinetixBadge>
                </TabsTrigger>
            </TabsList>

            <div v-if="activeTab" :key="activeTab.key" role="tabpanel">
                <!-- Plain tab: one manager, heading hidden (the tab shows it).
                     Group tab: members stacked, each with its own heading
                     (and collapse toggle when collapsible). -->
                <KinetixRelationManager
                    v-if="!activeTab.isGroup"
                    :manager="activeTab.managers[0]"
                    hide-title
                />
                <div v-else class="space-y-8">
                    <KinetixRelationManager
                        v-for="manager in activeTab.managers"
                        :key="manager.relationship"
                        :manager="manager"
                    />
                </div>
            </div>
        </TabsRoot>

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
