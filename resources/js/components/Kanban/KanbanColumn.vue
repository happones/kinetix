<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixVirtualRows } from '@/composables/useKinetixVirtualRows';
import type { KinetixKanbanCard } from '@/types';

interface KanbanColumnData {
    key: string;
    label: string;
    color?: string | null;
    cards: KinetixKanbanCard[];
}

const props = defineProps<{ column: KanbanColumnData }>();

const emit = defineEmits<{
    (e: 'card-dragstart', card: KinetixKanbanCard): void;
    (e: 'card-dragend'): void;
    (e: 'drop'): void;
}>();

const { t } = useI18n();

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

const measureRow = (el: Element | null): void => {
    if (virtual.enabled.value) {
        virtual.measureElement(el);
    }
};
</script>

<template>
    <div
        class="w-72 rounded-lg flex shrink-0 flex-col border border-border bg-muted/30"
        @dragover.prevent
        @drop="emit('drop')"
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
            :class="virtual.enabled ? 'max-h-[70vh] overflow-y-auto' : ''"
        >
            <div
                class="gap-2 p-2 flex flex-1 flex-col"
                :class="virtual.enabled ? 'relative block' : ''"
                :style="
                    virtual.enabled
                        ? { height: `${virtual.totalSize.value}px` }
                        : undefined
                "
            >
                <article
                    v-for="{ card, start, index, key } in cardRows"
                    :key="key"
                    :ref="measureRow"
                    :data-index="index"
                    draggable="true"
                    class="p-3 shadow-xs hover:shadow-md cursor-grab rounded-md border border-border bg-card transition-shadow active:cursor-grabbing"
                    :class="
                        virtual.enabled
                            ? 'top-0 left-0 absolute w-[calc(100%-1rem)]'
                            : ''
                    "
                    :style="
                        virtual.enabled
                            ? { transform: `translateY(${start}px)` }
                            : undefined
                    "
                    @dragstart="emit('card-dragstart', card)"
                    @dragend="emit('card-dragend')"
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
                </article>

                <p
                    v-if="column.cards.length === 0"
                    class="px-1 py-4 text-xs text-center text-muted-foreground"
                >
                    {{ t('kinetix.kanban_empty') }}
                </p>
            </div>
        </div>
    </div>
</template>
