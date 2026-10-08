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
import { kanbanDropIndex, placeCard } from './Kanban/kanbanDropIndex';

let kanbanUid = 0;

/**
 * A drag-and-drop board. Cards are grouped into columns by status; dragging a
 * card to another column persists the new status (optimistic, reverting on
 * error). On a reorderable board a card drops at the slot under the pointer and
 * can be reordered within its column; the column's new order is persisted with
 * the move. Uses native HTML5 drag-and-drop on pointer devices and a long-press
 * touch drag on mobile — no extra dependency.
 */
const props = defineProps<{
    kanban: KinetixKanbanData;
    /**
     * Inertia prop names to refresh after a move. When set, the post-move
     * resync is a PARTIAL reload (`router.reload({ only })`) instead of a full
     * one — on a dashboard with many props this avoids re-serializing the whole
     * page. Must name the prop(s) the host page feeds this board (and anything
     * derived from the same data). Omit it to keep the safe full reload.
     */
    reloadOnly?: string[];
}>();

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

// The slot under the pointer on a reorderable board: which column, and where
// in it. Native drags report it from the column's dragover, touch drags from
// the finger's position.
const dropSlot = ref<{ key: string; index: number } | null>(null);

function setDropSlot(key: string, index: number): void {
    if (dropSlot.value?.key !== key || dropSlot.value.index !== index) {
        dropSlot.value = { key, index };
    }
}

const dropIndexFor = (key: string): number | null =>
    dropSlot.value?.key === key ? dropSlot.value.index : null;

function onDragStart(card: KinetixKanbanCard, from: string): void {
    dragging.value = { card, from };
}

function onDragEnd(): void {
    dragging.value = null;
    dropSlot.value = null;
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
    onMove: (key, _x, y) => {
        const columnEl =
            key !== null && props.kanban.reorderable
                ? boardEl.value?.querySelector(
                      `[data-kanban-column="${CSS.escape(key)}"]`,
                  )
                : null;

        if (key !== null && columnEl) {
            setDropSlot(key, kanbanDropIndex(columnEl, y));
        }
    },
    onDrop: (drag, key) => {
        const index = key !== null ? dropIndexFor(key) : null;
        dragging.value = null;
        dropSlot.value = null;

        if (key !== null) {
            moveCard(drag.card, drag.from, key, index);
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
 * Move a card (optimistic, reverting on error). Shared by the pointer drop and
 * the keyboard alternative; resolves to whether it stuck. `index` is the slot
 * in the destination column, counted before the card leaves its place; it
 * only applies on a reorderable board, where it may also be the card's own
 * column. Elsewhere the card appends to another column.
 */
async function moveCard(
    card: KinetixKanbanCard,
    fromKey: string,
    toKey: string,
    index: number | null = null,
): Promise<boolean> {
    const reorderable = !!props.kanban.reorderable;

    if (fromKey === toKey && !reorderable) {
        return false;
    }

    const fromCol = columns.find((c) => c.key === fromKey);
    const toCol = columns.find((c) => c.key === toKey);

    if (!fromCol || !toCol) {
        return false;
    }

    const placed = placeCard(
        toCol.cards,
        card,
        reorderable ? (index ?? toCol.cards.length) : toCol.cards.length,
    );

    if (placed === null) {
        return false;
    }

    // Optimistic move; the previous lists put it back exactly on failure.
    const previousFrom = fromCol.cards;
    const previousTo = toCol.cards;

    if (fromCol !== toCol) {
        fromCol.cards = fromCol.cards.filter((c) => c.id !== card.id);
    }

    toCol.cards = placed;

    try {
        await kinetixFetch(`/${kinetixRoutePrefix(page)}/tables/kanban-move`, {
            method: 'POST',
            body: {
                model: props.kanban.model,
                recordId: card.id,
                status: toKey,
                ...(reorderable ? { order: placed.map((c) => c.id) } : {}),
            },
        });
        router.reload(
            props.reloadOnly?.length ? { only: props.reloadOnly } : {},
        );

        return true;
    } catch {
        fromCol.cards = previousFrom;
        toCol.cards = previousTo;
        toast.error(t('kinetix.kanban_move_failed'));

        return false;
    }
}

async function onDrop(toKey: string): Promise<void> {
    const drag = dragging.value;
    const index = dropIndexFor(toKey);
    dragging.value = null;
    dropSlot.value = null;

    if (!drag) {
        return;
    }

    await moveCard(drag.card, drag.from, toKey, index);
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
    await refocusCard(card);
}

/** Up/down on a reorderable board: one place earlier or later in the column. */
async function onCardKeyboardReorder(
    card: KinetixKanbanCard,
    columnKey: string,
    delta: -1 | 1,
): Promise<void> {
    const column = columns.find((c) => c.key === columnKey);
    const from = column?.cards.findIndex((c) => c.id === card.id) ?? -1;

    if (!column || from === -1) {
        return;
    }

    // Slots count before the card leaves its place: one down is two ahead.
    const moved = await moveCard(
        card,
        columnKey,
        columnKey,
        delta === 1 ? from + 2 : from - 1,
    );

    if (!moved) {
        return;
    }

    announce(
        t('kinetix.kanban_moved_to_position', {
            position: column.cards.findIndex((c) => c.id === card.id) + 1,
            total: column.cards.length,
        }),
    );
    await refocusCard(card);
}

/** The card re-renders in its new place; put focus back on it. */
async function refocusCard(card: KinetixKanbanCard): Promise<void> {
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
            {{
                kanban.reorderable
                    ? t('kinetix.kanban_keyboard_hint_reorder')
                    : t('kinetix.kanban_keyboard_hint')
            }}
        </p>

        <!-- `relative`: absolutely positioned descendants (a card's sr-only
             text) take the scroller as their containing block, so it clips
             them — otherwise an off-screen column widens the whole page. -->
        <div ref="boardEl" class="gap-4 pb-2 relative flex overflow-x-auto">
            <KanbanColumn
                v-for="column in columns"
                :key="column.key"
                :column="column"
                :hint-id="hintId"
                :dragging-card="dragging?.card ?? null"
                :dragging-from-key="dragging?.from ?? null"
                :touch-drop-target="touchDropKey === column.key"
                :reorderable="!!kanban.reorderable"
                :drop-index="dropIndexFor(column.key)"
                @drop-index="(index) => setDropSlot(column.key, index)"
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
                @card-reorder="
                    (card, delta) =>
                        onCardKeyboardReorder(card, column.key, delta)
                "
                @drop="onDrop(column.key)"
            />
        </div>
    </div>
</template>
