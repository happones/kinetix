<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Loader2, UploadCloud } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ComponentPublicInstance } from 'vue';
import { useI18n } from 'vue-i18n';
import { KINETIX_DROP_PREVIEW_CLASS } from '@/composables/kinetixDragStyles';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import { useKinetixListReorder } from '@/composables/useKinetixListReorder';
import { useKinetixVirtualRows } from '@/composables/useKinetixVirtualRows';
import type { KinetixMediaItem, KinetixSharedProps } from '@/types/kinetix';
import MediaLibraryTile from './Media/MediaLibraryTile.vue';

/**
 * A multi-file media grid: drag-drop / click to upload, reorder by dragging,
 * delete, and preview. Value is an ordered array of media items. Pairs with the
 * MediaLibrary form field (and KinetixMedia::sync for spatie persistence).
 */
const props = withDefaults(
    defineProps<{
        value?: KinetixMediaItem[] | null;
        uploadToken: string;
        acceptedFileTypes?: string[] | null;
        isImage?: boolean;
        maxFiles?: number | null;
        reorderable?: boolean;
        disabled?: boolean;
    }>(),
    {
        value: null,
        acceptedFileTypes: null,
        isImage: false,
        maxFiles: null,
        reorderable: true,
        disabled: false,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: KinetixMediaItem[]): void;
}>();

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();

const prefix = computed(
    () => page.props.kinetix_config?.route_prefix ?? '_kinetix',
);

const inputRef = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const errorMessage = ref<string | null>(null);

const items = computed<KinetixMediaItem[]>(() =>
    Array.isArray(props.value) ? props.value : [],
);

// --- Reorder (native drag-and-drop) -----------------------------------------
// Tiles render from the composable's local copy so the dragged tile travels
// through the grid as a live translucent preview; the new order is emitted
// once, on drop, and reverted when the drag is cancelled.
const {
    localItems: orderedItems,
    draggingIndex,
    onDragStart: onReorderStart,
    onDragOver: onReorderOver,
    onDrop: onReorderDrop,
    onDragEnd: onReorderEnd,
} = useKinetixListReorder<KinetixMediaItem>({
    items: () => items.value,
    enabled: () => props.reorderable && !props.disabled,
    onCommit: (next) => emit('update:value', next),
});

const acceptAttr = computed(() => {
    if (props.acceptedFileTypes && props.acceptedFileTypes.length > 0) {
        return props.acceptedFileTypes.join(',');
    }

    return props.isImage ? 'image/*' : undefined;
});

const canAddMore = computed(
    () =>
        !props.disabled &&
        (!props.maxFiles || items.value.length < props.maxFiles),
);

// --- Virtualization (large libraries only) ----------------------------------
// The plain grid is `grid-cols-2 sm:grid-cols-3 md:grid-cols-4`. Windowing means
// grouping items into rows, which needs that column count in JS — hence the
// viewport-width mirror of those Tailwind breakpoints.
const GRID_COLUMNS = [
    { minViewportWidth: 768, columns: 4 },
    { minViewportWidth: 640, columns: 3 },
    { minViewportWidth: 0, columns: 2 },
];

const viewportWidth = ref(
    typeof window === 'undefined' ? 1024 : window.innerWidth,
);

const onViewportResize = (): void => {
    viewportWidth.value = window.innerWidth;
};

onMounted(() => {
    window.addEventListener('resize', onViewportResize, { passive: true });
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', onViewportResize);
});

const columnsPerRow = computed<number>(
    () =>
        GRID_COLUMNS.find((bp) => viewportWidth.value >= bp.minViewportWidth)
            ?.columns ?? 2,
);

const gridColumnsClass = computed<string>(
    () =>
        ({ 2: 'grid-cols-2', 3: 'grid-cols-3', 4: 'grid-cols-4' })[
            columnsPerRow.value
        ] ?? 'grid-cols-2',
);

const scrollEl = ref<HTMLElement | null>(null);
const virtual = useKinetixVirtualRows({
    count: () => Math.ceil(items.value.length / columnsPerRow.value),
    getScrollElement: () => scrollEl.value,
    estimateSize: 180,
    overscan: 3,
    threshold: 12,
});

interface MediaRow {
    tiles: { item: KinetixMediaItem; index: number }[];
    start: number;
    index: number;
    key: string | number;
}

const mediaRows = computed<MediaRow[]>(() =>
    virtual.virtualRows.value.map((row) => {
        const from = row.index * columnsPerRow.value;

        return {
            tiles: orderedItems.value
                .slice(from, from + columnsPerRow.value)
                .map((item, offset) => ({ item, index: from + offset })),
            start: row.start,
            index: row.index,
            key: row.key,
        };
    }),
);

// Measure real row heights only while virtualized (tile height follows column width).
const measureRow = (el: Element | ComponentPublicInstance | null): void => {
    if (virtual.enabled.value && el instanceof Element) {
        virtual.measureElement(el);
    }
};

async function uploadOne(file: File): Promise<KinetixMediaItem | null> {
    const body = new FormData();
    body.append('file', file);
    body.append('token', props.uploadToken);

    const res = await kinetixFetch<{ path?: string; url?: string }>(
        `/${prefix.value}/uploads/store`,
        { method: 'POST', body },
    );

    if (!res?.path) {
        return null;
    }

    return {
        path: res.path,
        url: res.url ?? `/storage/${res.path}`,
        name: file.name,
        size: file.size,
        mime: file.type,
    };
}

async function handleFiles(files: FileList | null): Promise<void> {
    if (!files || files.length === 0) {
        return;
    }

    uploading.value = true;
    errorMessage.value = null;
    const next = [...items.value];

    try {
        for (const file of Array.from(files)) {
            if (props.maxFiles && next.length >= props.maxFiles) {
                break;
            }

            const item = await uploadOne(file);

            if (item) {
                next.push(item);
                emit('update:value', [...next]);
            }
        }
    } catch {
        errorMessage.value = t('kinetix.media_upload_failed');
    } finally {
        uploading.value = false;
    }
}

function onInputChange(event: Event): void {
    void handleFiles((event.target as HTMLInputElement).files);

    if (inputRef.value) {
        inputRef.value.value = '';
    }
}

function onDrop(event: DragEvent): void {
    if (draggingIndex.value !== null) {
        return; // a reorder drag, not a file drop
    }

    void handleFiles(event.dataTransfer?.files ?? null);
}

function remove(index: number): void {
    const next = [...orderedItems.value];
    next.splice(index, 1);
    emit('update:value', next);
}

function isImageItem(item: KinetixMediaItem): boolean {
    if (item.mime) {
        return item.mime.startsWith('image/');
    }

    return /\.(png|jpe?g|gif|webp|avif|svg)$/i.test(item.url ?? '');
}

function preview(item: KinetixMediaItem): void {
    if (!isImageItem(item)) {
        window.open(item.url, '_blank');

        return;
    }

    window.dispatchEvent(
        new CustomEvent('kinetix:preview', {
            detail: { url: item.url, type: 'image', label: item.name },
        }),
    );
}
</script>

<template>
    <div class="space-y-3">
        <!-- Small libraries stay a single responsive CSS grid (no virtualization
             overhead, no JS-resolved column count). -->
        <div
            v-if="items.length && !virtual.enabled.value"
            class="gap-3 sm:grid-cols-3 md:grid-cols-4 grid grid-cols-2"
        >
            <MediaLibraryTile
                v-for="(item, idx) in orderedItems"
                :key="item.id ?? item.path ?? idx"
                :item="item"
                :reorderable="reorderable"
                :disabled="disabled"
                :draggable="reorderable"
                :class="draggingIndex === idx ? KINETIX_DROP_PREVIEW_CLASS : ''"
                @dragstart="onReorderStart(idx)"
                @dragover="onReorderOver(idx, $event)"
                @drop="onReorderDrop()"
                @dragend="onReorderEnd()"
                @preview="preview(item)"
                @remove="remove(idx)"
            />
        </div>

        <!-- Large libraries window their grid rows. -->
        <div
            v-else-if="items.length"
            ref="scrollEl"
            class="max-h-[70vh] overflow-y-auto"
        >
            <div
                class="relative"
                :style="{ height: `${virtual.totalSize.value}px` }"
            >
                <div
                    v-for="row in mediaRows"
                    :key="row.key"
                    :ref="measureRow"
                    :data-index="row.index"
                    class="gap-3 pb-3 top-0 left-0 absolute grid w-full"
                    :class="gridColumnsClass"
                    :style="{ transform: `translateY(${row.start}px)` }"
                >
                    <MediaLibraryTile
                        v-for="tile in row.tiles"
                        :key="tile.item.id ?? tile.item.path ?? tile.index"
                        :item="tile.item"
                        :reorderable="reorderable"
                        :disabled="disabled"
                        :draggable="reorderable"
                        :class="
                            draggingIndex === tile.index
                                ? KINETIX_DROP_PREVIEW_CLASS
                                : ''
                        "
                        @dragstart="onReorderStart(tile.index)"
                        @dragover="onReorderOver(tile.index, $event)"
                        @drop="onReorderDrop()"
                        @dragend="onReorderEnd()"
                        @preview="preview(tile.item)"
                        @remove="remove(tile.index)"
                    />
                </div>
            </div>
        </div>

        <button
            v-if="canAddMore"
            type="button"
            class="gap-1.5 rounded-lg px-4 py-6 text-sm flex w-full flex-col items-center justify-center border border-dashed border-input text-muted-foreground transition-colors hover:bg-accent/50"
            @click="inputRef?.click()"
            @dragover.prevent
            @drop.prevent="onDrop"
        >
            <Loader2 v-if="uploading" class="size-5 animate-spin" />
            <UploadCloud v-else class="size-5" />
            {{
                uploading
                    ? t('kinetix.media_uploading')
                    : t('kinetix.media_add')
            }}
        </button>

        <input
            ref="inputRef"
            type="file"
            multiple
            class="hidden"
            :accept="acceptAttr"
            @change="onInputChange"
        />

        <p v-if="errorMessage" class="text-sm text-destructive">
            {{ errorMessage }}
        </p>
    </div>
</template>
