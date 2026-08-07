<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixVirtualRows } from '@/composables/useKinetixVirtualRows';
import type { KinetixKanbanCard } from '@/types/kinetix';

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
    /** Id of the card currently in flight (dims the source card). */
    draggingCardId?: string | number | null;
    /** True when the board's touch drag hovers this column (highlight). */
    touchDropTarget?: boolean;
}>();

const emit = defineEmits<{
    (e: 'card-dragstart', card: KinetixKanbanCard): void;
    (e: 'card-dragend'): void;
    (e: 'card-move', card: KinetixKanbanCard, direction: -1 | 1): void;
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
</script>

<template>
    <div
        role="group"
        :aria-label="`${column.label} (${column.cards.length})`"
        :data-kanban-column="column.key"
        class="w-72 rounded-lg flex shrink-0 flex-col border transition-colors"
        :class="
            isDragOver
                ? 'border-primary/50 bg-accent/50 ring-2 ring-primary/30'
                : 'border-border bg-muted/30'
        "
        @dragover.prevent
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
                <article
                    v-for="{ card, start, index, key } in cardRows"
                    :key="key"
                    :ref="measureRow"
                    :data-index="index"
                    :data-kanban-card="card.id"
                    draggable="true"
                    tabindex="0"
                    :aria-roledescription="t('kinetix.kanban_card')"
                    :aria-describedby="hintId"
                    class="p-3 shadow-xs hover:shadow-md cursor-grab rounded-md border border-border bg-card transition-shadow outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 active:cursor-grabbing"
                    :class="[
                        virtual.enabled.value
                            ? 'top-0 left-0 absolute w-[calc(100%-1rem)]'
                            : '',
                        draggingCardId != null && draggingCardId === card.id
                            ? 'opacity-40'
                            : '',
                    ]"
                    :style="
                        virtual.enabled.value
                            ? { transform: `translateY(${start}px)` }
                            : undefined
                    "
                    @dragstart="emit('card-dragstart', card)"
                    @dragend="emit('card-dragend')"
                    @pointerdown="(e) => emit('card-pointerdown', card, e)"
                    @click="emit('card-click', card)"
                    @keydown.enter.prevent="emit('card-click', card)"
                    @keydown.left.prevent="emit('card-move', card, -1)"
                    @keydown.right.prevent="emit('card-move', card, 1)"
                >
                    <p class="text-sm font-medium text-foreground">
                        {{ card.title }}
                    </p>
                    <p
                        v-if="card.description"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ card.description }}
                    </p>
                    <!-- Keyboard alternative to dragging, for screen readers. -->
                    <span class="sr-only">{{
                        t('kinetix.kanban_keyboard_hint')
                    }}</span>
                </article>

                <p
                    v-if="column.cards.length === 0"
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
