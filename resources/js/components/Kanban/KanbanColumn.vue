<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import { useI18n } from 'vue-i18n';
import { KINETIX_DRAG_SOURCE_CLASS } from '@/composables/kinetixDragStyles';
import { useKinetixVirtualRows } from '@/composables/useKinetixVirtualRows';
import type { KinetixKanbanCard } from '@/types/kinetix';
import KinetixDropGhost from '../KinetixDropGhost.vue';
import { kanbanDropIndex, placeCard } from './kanbanDropIndex';

interface KanbanColumnData {
    key: string;
    label: string;
    color?: string | null;
    cards: KinetixKanbanCard[];
}

const props = defineProps<{
    column: KanbanColumnData;
    /** Id of the board's sr-only keyboard instructions (aria-describedby). */
    hintId?: string;
    /** The card currently in flight (dims its source, labels the ghost). */
    draggingCard?: KinetixKanbanCard | null;
    /** Key of the column the in-flight card is dragged from. */
    draggingFromKey?: string | null;
    /** True when the board's touch drag hovers this column (highlight). */
    touchDropTarget?: boolean;
    /** Cards drop at a place in the column (and reorder within it). */
    reorderable?: boolean;
    /** Where the in-flight card would land here; null while not hovered. */
    dropIndex?: number | null;
}>();

const emit = defineEmits<{
    (e: 'card-dragstart', card: KinetixKanbanCard): void;
    (e: 'card-dragend'): void;
    (e: 'card-move', card: KinetixKanbanCard, direction: -1 | 1): void;
    (e: 'card-reorder', card: KinetixKanbanCard, delta: -1 | 1): void;
    (e: 'drop-index', index: number): void;
    (e: 'card-click', card: KinetixKanbanCard): void;
    (e: 'card-pointerdown', card: KinetixKanbanCard, event: PointerEvent): void;
    (e: 'drop'): void;
}>();

const { t } = useI18n();

// Highlight while a native drag hovers the column. dragenter/dragleave fire
// for every child crossed, so a depth counter tracks the real boundary.
const dragDepth = ref(0);
const isDragOver = computed(() => dragDepth.value > 0 || props.touchDropTarget);

const onDragEnter = (): void => {
    dragDepth.value++;
};

const onDragLeave = (): void => {
    dragDepth.value = Math.max(0, dragDepth.value - 1);
};

const onDrop = (): void => {
    dragDepth.value = 0;
    emit('drop');
};

// On a reorderable board the pointer picks the slot: report it on every
// dragover (the board ignores repeats).
const columnEl = ref<HTMLElement | null>(null);

const onDragOver = (event: DragEvent): void => {
    if (props.reorderable && columnEl.value) {
        emit('drop-index', kanbanDropIndex(columnEl.value, event.clientY));
    }
};

// Up/down reorder within the column on a reorderable board; elsewhere the
// keys keep scrolling the page.
const onVerticalKey = (
    event: KeyboardEvent,
    card: KinetixKanbanCard,
    delta: -1 | 1,
): void => {
    if (!props.reorderable) {
        return;
    }

    event.preventDefault();
    emit('card-reorder', card, delta);
};

const keyboardHint = computed(() =>
    props.reorderable
        ? t('kinetix.kanban_keyboard_hint_reorder')
        : t('kinetix.kanban_keyboard_hint'),
);

// A cancelled drag (Escape, released off-board) fires no dragleave/drop on the
// hovered column, which would leave the depth counter — and thus the highlight
// and ghost — stuck for the next drag.
watch(
    () => props.draggingCard,
    (card) => {
        if (card == null) {
            dragDepth.value = 0;
        }
    },
);

// Each column virtualizes its own card list once it grows past the threshold;
// the drop target is the column (not a card slot), so windowing the cards never
// interferes with drag-and-drop.
const scrollEl = ref<HTMLElement | null>(null);
const virtual = useKinetixVirtualRows({
    count: () => props.column.cards.length,
    getScrollElement: () => scrollEl.value,
    estimateSize: 76,
    overscan: 6,
});

interface CardRow {
    card: KinetixKanbanCard;
    start: number;
    index: number;
    key: string | number;
}

const cardRows = computed<CardRow[]>(() =>
    virtual.enabled.value
        ? virtual.virtualRows.value.map((row) => ({
              card: props.column.cards[row.index],
              start: row.start,
              index: row.index,
              key: row.key,
          }))
        : props.column.cards.map((card, index) => ({
              card,
              start: 0,
              index,
              key: String(card.id),
          })),
);

const measureRow = (el: Element | ComponentPublicInstance | null): void => {
    if (virtual.enabled.value && el instanceof Element) {
        virtual.measureElement(el);
    }
};

// Preview where the in-flight card will land: a ghost placeholder at the
// pointer's slot on a reorderable board, at the end of the column otherwise
// (cards append). None for a drop that would leave the card where it is (on a
// plain board, anywhere in its own column) or in a virtualized column, where
// rows are positioned absolutely.
const ghostIndex = computed<number | null>(() => {
    const card = props.draggingCard;

    if (!isDragOver.value || card == null || virtual.enabled.value) {
        return null;
    }

    const sameColumn = props.draggingFromKey === props.column.key;

    if (!props.reorderable) {
        return sameColumn ? null : props.column.cards.length;
    }

    const index = props.dropIndex ?? props.column.cards.length;

    return sameColumn && placeCard(props.column.cards, card, index) === null
        ? null
        : index;
});

const showDropGhost = computed(() => ghostIndex.value !== null);

type RenderRow = (CardRow & { ghost: false }) | { ghost: true; key: string };

const renderRows = computed<RenderRow[]>(() => {
    const rows: RenderRow[] = cardRows.value.map((row) => ({
        ...row,
        ghost: false,
    }));

    if (ghostIndex.value !== null) {
        rows.splice(ghostIndex.value, 0, {
            ghost: true,
            key: '__kanban-drop-ghost',
        });
    }

    return rows;
});
</script>

<template>
    <!-- 18rem, or 85% of a phone's width so the next column peeks in and
         says the board scrolls. -->
    <div
        ref="columnEl"
        role="group"
        :aria-label="`${column.label} (${column.cards.length})`"
        :data-kanban-column="column.key"
        class="rounded-lg flex w-[min(18rem,85vw)] shrink-0 flex-col border transition-colors"
        :class="
            isDragOver
                ? 'border-primary/50 bg-accent/50 ring-2 ring-primary/30'
                : 'border-border bg-muted/30'
        "
        @dragover.prevent="onDragOver"
        @dragenter.prevent="onDragEnter"
        @dragleave="onDragLeave"
        @drop="onDrop"
    >
        <div class="gap-2 px-3 py-2 flex items-center border-b border-border">
            <span
                class="size-2 shrink-0 rounded-full"
                :style="{
                    backgroundColor:
                        column.color ?? 'var(--color-muted-foreground, #888)',
                }"
            />
            <span class="text-sm font-medium text-foreground">{{
                column.label
            }}</span>
            <span class="text-xs ml-auto text-muted-foreground">{{
                column.cards.length
            }}</span>
        </div>

        <div
            ref="scrollEl"
            class="min-h-16 flex flex-1 flex-col"
            :class="virtual.enabled.value ? 'max-h-[70vh] overflow-y-auto' : ''"
        >
            <!-- Cards FLIP-move into place on drops/reorders; the virtualized
                 branch positions rows itself, so transitions turn off there. -->
            <TransitionGroup
                tag="div"
                class="gap-2 p-2 flex flex-1 flex-col"
                :class="virtual.enabled.value ? 'relative block' : ''"
                :style="
                    virtual.enabled.value
                        ? { height: `${virtual.totalSize.value}px` }
                        : undefined
                "
                :move-class="virtual.enabled.value ? '' : 'kx-card-move'"
                :enter-active-class="
                    virtual.enabled.value ? '' : 'kx-card-enter-active'
                "
                :enter-from-class="
                    virtual.enabled.value ? '' : 'kx-card-enter-from'
                "
            >
                <!-- One keyed loop: the ghost sits among the cards at the
                     slot the card would land in. -->
                <template v-for="row in renderRows" :key="row.key">
                    <KinetixDropGhost
                        v-if="row.ghost"
                        :label="draggingCard?.title"
                    />
                    <article
                        v-else
                        :ref="measureRow"
                        :data-index="row.index"
                        :data-kanban-card="row.card.id"
                        draggable="true"
                        tabindex="0"
                        :aria-roledescription="t('kinetix.kanban_card')"
                        :aria-describedby="hintId"
                        class="p-3 shadow-xs hover:shadow-md cursor-grab rounded-md border border-border bg-card transition-shadow outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:cursor-grabbing"
                        :class="[
                            virtual.enabled.value
                                ? 'top-0 left-0 absolute w-[calc(100%-1rem)]'
                                : '',
                            draggingCard != null &&
                            draggingCard.id === row.card.id
                                ? KINETIX_DRAG_SOURCE_CLASS
                                : '',
                        ]"
                        :style="
                            virtual.enabled.value
                                ? { transform: `translateY(${row.start}px)` }
                                : undefined
                        "
                        @dragstart="emit('card-dragstart', row.card)"
                        @dragend="emit('card-dragend')"
                        @pointerdown="
                            (e) => emit('card-pointerdown', row.card, e)
                        "
                        @click="emit('card-click', row.card)"
                        @keydown.enter.prevent="emit('card-click', row.card)"
                        @keydown.left.prevent="emit('card-move', row.card, -1)"
                        @keydown.right.prevent="emit('card-move', row.card, 1)"
                        @keydown.up="(e) => onVerticalKey(e, row.card, -1)"
                        @keydown.down="(e) => onVerticalKey(e, row.card, 1)"
                    >
                        <p class="text-sm font-medium text-foreground">
                            {{ row.card.title }}
                        </p>
                        <p
                            v-if="row.card.description"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ row.card.description }}
                        </p>
                        <!-- Keyboard alternative to dragging, for screen readers. -->
                        <span class="sr-only">{{ keyboardHint }}</span>
                    </article>
                </template>

                <p
                    v-if="column.cards.length === 0 && !showDropGhost"
                    key="__kanban-empty"
                    class="px-1 py-4 text-xs text-center text-muted-foreground"
                >
                    {{ t('kinetix.kanban_empty') }}
                </p>
            </TransitionGroup>
        </div>
    </div>
</template>

<style scoped>
.kx-card-move {
    transition: transform 200ms cubic-bezier(0.16, 1, 0.3, 1);
}

.kx-card-enter-active {
    transition:
        opacity 150ms ease-out,
        transform 150ms ease-out;
}

.kx-card-enter-from {
    opacity: 0;
    transform: scale(0.95);
}

@media (prefers-reduced-motion: reduce) {
    .kx-card-move,
    .kx-card-enter-active {
        transition: none;
    }
}
</style>
