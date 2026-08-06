<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type { KinetixRelationManagerData } from '@/types/kinetix';
import KinetixRelationManager from './KinetixRelationManager.vue';

/**
 * The relation managers HOST for a resource page — pass everything
 * `relationManagersFor()` returned and it picks the right layout, exactly
 * like Filament:
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

const useTabs = computed(() => props.tabs && props.managers.length > 1);

/** The `?relation=` query param persisting the active tab across reloads. */
const TAB_PARAM = 'relation';

const tabFromUrl = (): string | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const requested = new URLSearchParams(window.location.search).get(
        TAB_PARAM,
    );

    return props.managers.some((m) => m.relationship === requested)
        ? requested
        : null;
};

const active = ref(tabFromUrl() ?? props.managers[0]?.relationship ?? '');

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
                    @click="selectTab(manager.relationship)"
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
                <!-- Full manager (hidden heading — the tab already shows it),
                     so the attach modal + detach listener ride along. -->
                <KinetixRelationManager :manager="activeManager" hide-title />
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
