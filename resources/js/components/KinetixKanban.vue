<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { nextTick, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixKanbanCard,
    KinetixKanbanData,
    KinetixSharedProps,
} from '@/types/kinetix';
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

/**
 * Move a card between columns (optimistic, reverting on error). Shared by the
 * pointer drop and the keyboard alternative; resolves to whether it stuck.
 */
async function moveCard(
    card: KinetixKanbanCard,
    fromKey: string,
    toKey: string,
): Promise<boolean> {
    if (fromKey === toKey) {
        return false;
    }

    const fromCol = columns.find((c) => c.key === fromKey);
    const toCol = columns.find((c) => c.key === toKey);

    if (!fromCol || !toCol) {
        return false;
    }

    // Optimistic move.
    fromCol.cards = fromCol.cards.filter((c) => c.id !== card.id);
    toCol.cards = [...toCol.cards, card];

    try {
        await kinetixFetch(`/${kinetixRoutePrefix(page)}/tables/kanban-move`, {
            method: 'POST',
            body: {
                model: props.kanban.model,
                recordId: card.id,
                status: toKey,
            },
        });
        router.reload();

        return true;
    } catch {
        // Revert on failure.
        toCol.cards = toCol.cards.filter((c) => c.id !== card.id);
        fromCol.cards = [...fromCol.cards, card];
        toast.error(t('kinetix.kanban_move_failed'));

        return false;
    }
}

async function onDrop(toKey: string): Promise<void> {
    const drag = dragging.value;
    dragging.value = null;

    if (!drag) {
        return;
    }

    await moveCard(drag.card, drag.from, toKey);
}

// --- Keyboard alternative to dragging ------------------------------------------
// Left/right arrows on a focused card move it to the adjacent column; the move
// is announced and focus follows the card into its new column.
const { announce } = useKinetixAnnounce();

async function onCardKeyboardMove(
    card: KinetixKanbanCard,
    fromKey: string,
    direction: -1 | 1,
): Promise<void> {
    const fromIndex = columns.findIndex((c) => c.key === fromKey);
    const toCol = columns[fromIndex + direction];

    if (fromIndex === -1 || !toCol) {
        return;
    }

    const moved = await moveCard(card, fromKey, toCol.key);

    if (!moved) {
        return;
    }

    announce(t('kinetix.kanban_moved_to', { column: toCol.label }));

    // The card re-renders inside its new column; put focus back on it.
    await nextTick();
    document
        .querySelector<HTMLElement>(`[data-kanban-card="${card.id}"]`)
        ?.focus();
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
                @card-move="
                    (card, direction) =>
                        onCardKeyboardMove(card, column.key, direction)
                "
                @drop="onDrop(column.key)"
            />
        </div>
    </div>
</template>
