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
            <div
                v-for="column in columns"
                :key="column.key"
                class="w-72 rounded-lg flex shrink-0 flex-col border border-border bg-muted/30"
                @dragover.prevent
                @drop="onDrop(column.key)"
            >
                <div
                    class="gap-2 px-3 py-2 flex items-center border-b border-border"
                >
                    <span
                        class="size-2 shrink-0 rounded-full"
                        :style="{
                            backgroundColor:
                                column.color ??
                                'var(--color-muted-foreground, #888)',
                        }"
                    />
                    <span class="text-sm font-medium text-foreground">{{
                        column.label
                    }}</span>
                    <span class="text-xs ml-auto text-muted-foreground">{{
                        column.cards.length
                    }}</span>
                </div>

                <div class="min-h-16 gap-2 p-2 flex flex-1 flex-col">
                    <article
                        v-for="card in column.cards"
                        :key="String(card.id)"
                        draggable="true"
                        class="p-3 shadow-xs hover:shadow-md cursor-grab rounded-md border border-border bg-card transition-shadow active:cursor-grabbing"
                        @dragstart="onDragStart(card, column.key)"
                        @dragend="onDragEnd"
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
    </div>
</template>
