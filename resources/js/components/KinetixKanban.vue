<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { nextTick, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { useKinetixTouchDrag } from '@/composables/useKinetixTouchDrag';
import type {
    KinetixKanbanCard,
    KinetixKanbanData,
    KinetixSharedProps,
} from '@/types/kinetix';
import KanbanColumn from './Kanban/KanbanColumn.vue';

let kanbanUid = 0;

/**
 * A drag-and-drop board. Cards are grouped into columns by status; dragging a
 * card to another column persists the new status (optimistic, reverting on
 * error). Uses native HTML5 drag-and-drop on pointer devices and a long-press
 * touch drag on mobile — no extra dependency.
 */
const props = defineProps<{ kanban: KinetixKanbanData }>();

const emit = defineEmits<{
    /** A card was clicked (or Enter-pressed) — e.g. open its record. */
    (e: 'card-click', card: KinetixKanbanCard, columnKey: string): void;
}>();

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();

// Unique per board instance, so several boards on a page keep their own
// sr-only instructions element as each card's aria-describedby target.
const hintId = `kinetix-kanban-hint-${++kanbanUid}`;

// Local, mutable copy of the columns so drags update the UI immediately.
const snapshotColumns = () =>
    props.kanban.columns.map((c) => ({ ...c, cards: [...c.cards] }));

const columns = reactive(snapshotColumns());

// Inertia replaces `kanban` wholesale on every visit/reload (modal CRUD, the
// post-move reload), so a reference watch is enough to resync the local copy —
// no deep watch needed, which would traverse every card on large boards.
watch(
    () => props.kanban.columns,
    () => {
        columns.splice(0, columns.length, ...snapshotColumns());
    },
);

const dragging = ref<{ card: KinetixKanbanCard; from: string } | null>(null);

function onDragStart(card: KinetixKanbanCard, from: string): void {
    dragging.value = { card, from };
}

function onDragEnd(): void {
    dragging.value = null;
}

// --- Touch drag (long-press) -----------------------------------------------
// Native HTML5 drag never fires on touch devices; a long-press lifts the card
// into a floating clone instead. The hovered column highlights via
// `touchDropKey`, and the horizontal board auto-scrolls near its edges.
const boardEl = ref<HTMLElement | null>(null);
const touchDropKey = ref<string | null>(null);

const touchDrag = useKinetixTouchDrag<{
    card: KinetixKanbanCard;
    from: string;
}>({
    targetAttr: 'data-kanban-column',
    scrollContainer: () => boardEl.value,
    onStart: (drag) => {
        dragging.value = drag;
    },
    onHover: (key) => {
        touchDropKey.value = key;
    },
    onDrop: (drag, key) => {
        dragging.value = null;

        if (key !== null) {
            moveCard(drag.card, drag.from, key);
        }
    },
});

function onCardPointerDown(
    card: KinetixKanbanCard,
    from: string,
    event: PointerEvent,
): void {
    touchDrag.startFromPointerDown(event, event.currentTarget as HTMLElement, {
        card,
        from,
    });
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

        <!-- Screen-reader instructions every card points at (aria-describedby). -->
        <p :id="hintId" class="sr-only">
            {{ t('kinetix.kanban_keyboard_hint') }}
        </p>

        <div ref="boardEl" class="gap-4 pb-2 flex overflow-x-auto">
            <KanbanColumn
                v-for="column in columns"
                :key="column.key"
                :column="column"
                :hint-id="hintId"
                :dragging-card="dragging?.card ?? null"
                :dragging-from-key="dragging?.from ?? null"
                :touch-drop-target="touchDropKey === column.key"
                @card-dragstart="(card) => onDragStart(card, column.key)"
                @card-dragend="onDragEnd"
                @card-click="(card) => emit('card-click', card, column.key)"
                @card-pointerdown="
                    (card, event) => onCardPointerDown(card, column.key, event)
                "
                @card-move="
                    (card, direction) =>
                        onCardKeyboardMove(card, column.key, direction)
                "
                @drop="onDrop(column.key)"
            />
        </div>
    </div>
</template>
