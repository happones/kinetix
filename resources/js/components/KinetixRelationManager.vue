<script setup lang="ts">
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type { KinetixRelationManagerData } from '@/types/kinetix';
import KinetixTable from './KinetixTable.vue';

/**
 * One relation manager: heading (+ optional badge, e.g. a record count) and
 * the related-records table. For SEVERAL managers prefer
 * `<KinetixRelationManagers :managers="relations" />`, which auto-groups them
 * into tabs — this component renders a single, always-visible section.
 */
defineProps<{
    manager: KinetixRelationManagerData;
}>();

const badgeClass = (color?: string | null): string =>
    statusBadgeClass((color ?? 'gray') as KinetixStatusColor);
</script>

<template>
    <section class="space-y-3">
        <h2
            class="gap-2 text-lg font-semibold tracking-tight flex items-center text-foreground"
        >
            {{ manager.title }}
            <span
                v-if="manager.badge !== null && manager.badge !== undefined"
                class="px-2 py-0.5 text-xs font-semibold inline-flex items-center rounded-full"
                :class="badgeClass(manager.badgeColor)"
            >
                {{ manager.badge }}
            </span>
        </h2>

        <KinetixTable :table="manager.table" />
    </section>
</template>
