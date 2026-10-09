<script setup lang="ts">
import { FileText, GripVertical, X } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixMediaItem } from '@/types/kinetix';

/**
 * A single media grid tile. Extracted so `KinetixMediaLibrary` can render the
 * same markup on both its plain-grid and its virtualized path.
 *
 * On a reorderable library the grip is a real control: touch and pen drag the
 * tile from it (it's `touch-action: none`, so the page doesn't scroll), and
 * the arrow keys move the tile one place earlier or later. The grip and the
 * remove button show on hover and focus, and always on touch screens.
 */
const props = defineProps<{
    item: KinetixMediaItem;
    reorderable: boolean;
    disabled: boolean;
}>();

const emit = defineEmits<{
    (e: 'preview'): void;
    (e: 'remove'): void;
    /** Pointer down on the grip — touch and pen start a drag from here. */
    (e: 'grip-pointerdown', event: PointerEvent): void;
    /** Arrow keys on the grip: one place earlier (-1) or later (1). */
    (e: 'move', delta: -1 | 1): void;
}>();

const { t } = useI18n();

const isImage = computed<boolean>(() => {
    if (props.item.mime) {
        return props.item.mime.startsWith('image/');
    }

    return /\.(png|jpe?g|gif|webp|avif|svg)$/i.test(props.item.url ?? '');
});

const thumbUrl = computed<string>(() => props.item.thumb || props.item.url);

const humanSize = computed<string>(() => {
    const bytes = props.item.size;

    if (!bytes) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    return `${Math.round(size * 10) / 10} ${units[unit]}`;
});
</script>

<template>
    <div
        class="group rounded-lg relative overflow-hidden border border-border bg-card"
    >
        <button
            type="button"
            class="block aspect-square w-full"
            @click="emit('preview')"
        >
            <img
                v-if="isImage"
                :src="thumbUrl"
                :alt="item.name"
                class="size-full object-cover"
            />
            <span
                v-else
                class="flex size-full items-center justify-center bg-muted text-muted-foreground"
            >
                <FileText class="size-8" />
            </span>
        </button>

        <div class="gap-1 px-2 py-1 text-xs flex items-center justify-between">
            <span class="truncate text-foreground" :title="item.name">{{
                item.name
            }}</span>
            <span class="shrink-0 text-muted-foreground">{{ humanSize }}</span>
        </div>

        <button
            v-if="reorderable"
            type="button"
            data-reorder-grip
            class="left-1 top-1 size-6 bg-black/50 text-white absolute flex cursor-grab touch-none items-center justify-center rounded-full opacity-0 transition-opacity outline-none group-focus-within:opacity-100 group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 active:cursor-grabbing pointer-coarse:opacity-100"
            :aria-label="t('kinetix.reorder')"
            @click.stop
            @pointerdown="emit('grip-pointerdown', $event)"
            @keydown.left.prevent="emit('move', -1)"
            @keydown.up.prevent="emit('move', -1)"
            @keydown.right.prevent="emit('move', 1)"
            @keydown.down.prevent="emit('move', 1)"
        >
            <GripVertical class="size-3.5" aria-hidden="true" />
        </button>
        <button
            v-if="!disabled"
            type="button"
            class="right-1 top-1 size-6 bg-black/50 text-white absolute flex items-center justify-center rounded-full opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 hover:bg-destructive focus-visible:opacity-100 dark:hover:bg-destructive/60 pointer-coarse:opacity-100"
            :aria-label="t('kinetix.remove')"
            @click.stop="emit('remove')"
        >
            <X class="size-3.5" />
        </button>
    </div>
</template>
