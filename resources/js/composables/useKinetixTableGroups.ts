import { computed, ref } from 'vue';
import type { ComputedRef } from 'vue';
import type {
    KinetixTableData,
    KinetixTableGroup,
    KinetixTableRecord,
} from '@/types/kinetix';

/**
 * One contiguous run of rows sharing a group key, ready to render under a single
 * collapsible header. The server already ordered rows so same-group rows are
 * adjacent (see Table::applyGroupOrder); this composable only slices those runs
 * out of the current page — it never recomputes membership.
 */
export interface KinetixGroupSection {
    key: string;
    /** Header title (from the first row of the run). */
    label: string;
    /** Rows in this group, paired with their absolute index in the page (for striping/v-memo parity). */
    rows: Array<{ record: KinetixTableRecord; index: number }>;
    collapsed: boolean;
}

export interface UseKinetixTableGroups {
    /** The group active for this request (null = ungrouped: render a flat table). */
    activeGroup: ComputedRef<KinetixTableGroup | null>;
    isGrouped: ComputedRef<boolean>;
    /** Contiguous group runs over the current rows. Empty when ungrouped. */
    sections: ComputedRef<KinetixGroupSection[]>;
    /**
     * Flat render sequence for a single `v-for`: a header item then the visible
     * rows of each group (hidden when the group is collapsed). Lets the table
     * render groups without duplicating its row markup. Empty when ungrouped.
     */
    renderItems: ComputedRef<KinetixGroupRenderItem[]>;
    /** Whether a given group key is collapsed (only meaningful for collapsible groups). */
    isCollapsed: (key: string) => boolean;
    toggleGroup: (key: string) => void;
}

/** A header row, or a data row carrying its absolute page index. */
export type KinetixGroupRenderItem =
    | {
          type: 'header';
          key: string;
          label: string;
          count: number;
          collapsible: boolean;
          collapsed: boolean;
      }
    | {
          type: 'row';
          key: string;
          record: KinetixTableRecord;
          index: number;
      };

/**
 * Derives collapsible group sections for the server-driven KinetixTable.
 *
 * Collapse state is LOCAL (the client remembers which headers the user folded,
 * keyed by group key) — it survives polling refreshes but resets on a full
 * reload, matching how column visibility behaves. A null group value is bucketed
 * under a stable sentinel key so "no value" rows still group together.
 */
export function useKinetixTableGroups(
    table: () => KinetixTableData,
    rows: () => KinetixTableRecord[],
): UseKinetixTableGroups {
    const SENTINEL = '__kinetix_null_group__';

    const collapsedKeys = ref<Set<string>>(new Set());

    const activeGroup = computed<KinetixTableGroup | null>(() => {
        const t = table();
        const column = t.defaultGroup;

        if (!column) {
            return null;
        }

        return (t.groups ?? []).find((g) => g.column === column) ?? null;
    });

    const isGrouped = computed(() => activeGroup.value !== null);

    const isCollapsed = (key: string): boolean => collapsedKeys.value.has(key);

    const toggleGroup = (key: string): void => {
        const next = new Set(collapsedKeys.value);

        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }

        collapsedKeys.value = next;
    };

    const sections = computed<KinetixGroupSection[]>(() => {
        if (!isGrouped.value) {
            return [];
        }

        const result: KinetixGroupSection[] = [];
        const records = rows();

        records.forEach((record, index) => {
            const rawKey = record.groupKey ?? null;
            const key = rawKey === null ? SENTINEL : String(rawKey);
            const last = result[result.length - 1];

            if (last && last.key === key) {
                last.rows.push({ record, index });

                return;
            }

            result.push({
                key,
                label: record.groupLabel ?? '',
                rows: [{ record, index }],
                collapsed: isCollapsed(key),
            });
        });

        // Re-read collapsed flag so the computed re-runs when it changes.
        return result.map((section) => ({
            ...section,
            collapsed: isCollapsed(section.key),
        }));
    });

    const renderItems = computed<KinetixGroupRenderItem[]>(() => {
        // Ungrouped: a flat row list (no headers) so the table can render both
        // modes through one loop.
        if (!isGrouped.value) {
            return rows().map((record, index) => ({
                type: 'row' as const,
                key: '',
                record,
                index,
            }));
        }

        const items: KinetixGroupRenderItem[] = [];
        const collapsible = activeGroup.value?.collapsible ?? false;

        sections.value.forEach((section) => {
            items.push({
                type: 'header',
                key: section.key,
                label: section.label,
                count: section.rows.length,
                collapsible,
                collapsed: section.collapsed,
            });

            if (section.collapsed) {
                return;
            }

            section.rows.forEach(({ record, index }) => {
                items.push({ type: 'row', key: section.key, record, index });
            });
        });

        return items;
    });

    return {
        activeGroup,
        isGrouped,
        sections,
        renderItems,
        isCollapsed,
        toggleGroup,
    };
}
