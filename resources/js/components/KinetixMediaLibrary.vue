<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { FileText, GripVertical, Loader2, UploadCloud, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFetch } from '@/composables/useKinetixHttp';

interface MediaItem {
    id?: number | string | null;
    path?: string | null;
    url: string;
    name: string;
    size?: number | null;
    mime?: string | null;
    thumb?: string | null;
}

/**
 * A multi-file media grid: drag-drop / click to upload, reorder by dragging,
 * delete, and preview. Value is an ordered array of media items. Pairs with the
 * MediaLibrary form field (and KinetixMedia::sync for spatie persistence).
 */
const props = withDefaults(
    defineProps<{
        value?: MediaItem[] | null;
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
    (e: 'update:value', value: MediaItem[]): void;
}>();

const { t } = useI18n();
const page = usePage();

const prefix = computed(
    () => (page.props.kinetix_config as any)?.route_prefix ?? '_kinetix',
);

const inputRef = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const errorMessage = ref<string | null>(null);
const dragIndex = ref<number | null>(null);

const items = computed<MediaItem[]>(() =>
    Array.isArray(props.value) ? props.value : [],
);

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

function isImageItem(item: MediaItem): boolean {
    if (item.mime) {
        return item.mime.startsWith('image/');
    }

    return /\.(png|jpe?g|gif|webp|avif|svg)$/i.test(item.url ?? '');
}

function thumbUrl(item: MediaItem): string {
    return item.thumb || item.url;
}

function humanSize(bytes?: number | null): string {
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
}

async function uploadOne(file: File): Promise<MediaItem | null> {
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
    if (dragIndex.value !== null) {
        return; // a reorder drag, not a file drop
    }

    void handleFiles(event.dataTransfer?.files ?? null);
}

function remove(index: number): void {
    const next = [...items.value];
    next.splice(index, 1);
    emit('update:value', next);
}

function preview(item: MediaItem): void {
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

// --- Reorder (native drag-and-drop) -----------------------------------------
function onDragStart(index: number): void {
    if (props.reorderable) {
        dragIndex.value = index;
    }
}

function onDragOver(event: DragEvent): void {
    if (dragIndex.value !== null) {
        event.preventDefault();
    }
}

function onDropReorder(targetIndex: number): void {
    if (dragIndex.value === null || dragIndex.value === targetIndex) {
        dragIndex.value = null;

        return;
    }

    const next = [...items.value];
    const [moved] = next.splice(dragIndex.value, 1);
    next.splice(targetIndex, 0, moved);
    dragIndex.value = null;
    emit('update:value', next);
}
</script>

<template>
    <div class="space-y-3">
        <div
            v-if="items.length"
            class="gap-3 sm:grid-cols-3 md:grid-cols-4 grid grid-cols-2"
        >
            <div
                v-for="(item, idx) in items"
                :key="item.id ?? item.path ?? idx"
                class="group rounded-lg relative overflow-hidden border border-border bg-card"
                :draggable="reorderable"
                @dragstart="onDragStart(idx)"
                @dragover="onDragOver"
                @drop="onDropReorder(idx)"
            >
                <button
                    type="button"
                    class="block aspect-square w-full"
                    @click="preview(item)"
                >
                    <img
                        v-if="isImageItem(item)"
                        :src="thumbUrl(item)"
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

                <div
                    class="gap-1 px-2 py-1 text-xs flex items-center justify-between"
                >
                    <span class="truncate text-foreground" :title="item.name">{{
                        item.name
                    }}</span>
                    <span class="shrink-0 text-muted-foreground">{{
                        humanSize(item.size)
                    }}</span>
                </div>

                <GripVertical
                    v-if="reorderable"
                    class="left-1 top-1 size-4 text-white/80 drop-shadow absolute cursor-grab opacity-0 transition-opacity group-hover:opacity-100"
                />
                <button
                    v-if="!disabled"
                    type="button"
                    class="right-1 top-1 size-6 bg-black/50 text-white absolute flex items-center justify-center rounded-full opacity-0 transition-opacity group-hover:opacity-100 hover:bg-destructive"
                    :aria-label="t('kinetix.remove')"
                    @click.stop="remove(idx)"
                >
                    <X class="size-3.5" />
                </button>
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
