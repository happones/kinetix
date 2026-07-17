<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixKanbanCard,
    KinetixKanbanData,
    KinetixSharedProps,
} from '@/types';
import KanbanColumn from './Kanban/KanbanColumn.vue';

/**
 * A drag-and-drop board. Cards are grouped into columns by status; dragging a
 * card to another column persists the new status (optimistic, reverting on
 * error). Uses native HTML5 drag-and-drop — no extra dependency.
 */
const props = defineProps<{ kanban: KinetixKanbanData }>();

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();

// Local, mutable copy of the columns so drags update the UI immediately.
const columns = reactive(
    props.kanban.columns.map((c) => ({ ...c, cards: [...c.cards] })),
);

const dragging = ref<{ card: KinetixKanbanCard; from: string } | null>(null);

function onDragStart(card: KinetixKanbanCard, from: string): void {
    dragging.value = { card, from };
}

function onDragEnd(): void {
    dragging.value = null;
}

async function onDrop(toKey: string): Promise<void> {
    const drag = dragging.value;
    dragging.value = null;

    if (!drag || drag.from === toKey) {
        return;
    }

    const fromCol = columns.find((c) => c.key === drag.from);
    const toCol = columns.find((c) => c.key === toKey);

    if (!fromCol || !toCol) {
        return;
    }

    // Optimistic move.
    fromCol.cards = fromCol.cards.filter((c) => c.id !== drag.card.id);
    toCol.cards = [...toCol.cards, drag.card];

    try {
        await kinetixFetch(`/${kinetixRoutePrefix(page)}/tables/kanban-move`, {
            method: 'POST',
            body: {
                model: props.kanban.model,
                recordId: drag.card.id,
                status: toKey,
            },
        });
        router.reload();
    } catch {
        // Revert on failure.
        toCol.cards = toCol.cards.filter((c) => c.id !== drag.card.id);
        fromCol.cards = [...fromCol.cards, drag.card];
        toast.error(t('kinetix.kanban_move_failed'));
    }
}
</script>

<template>
    <div class="space-y-4">
        <h2 v-if="kanban.heading" class="text-lg font-semibold text-foreground">
            {{ kanban.heading }}
        </h2>

        <div class="gap-4 pb-2 flex overflow-x-auto">
            <KanbanColumn
                v-for="column in columns"
                :key="column.key"
                :column="column"
                @card-dragstart="(card) => onDragStart(card, column.key)"
                @card-dragend="onDragEnd"
                @drop="onDrop(column.key)"
            />
        </div>
    </div>
</template>
