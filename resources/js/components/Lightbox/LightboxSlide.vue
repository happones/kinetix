<script setup lang="ts">
import { Download, FileText, ImageOff, LoaderCircle } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveLightboxItemType } from '@/composables/useKinetixLightbox';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixLightboxItem } from '@/types/kinetix';
import { cn } from '../primitives/cn';

/**
 * One item of KinetixLightbox: an image (fit to the stage, transformed by the
 * viewer's zoom and pan), a PDF, or a download card for anything else. While
 * an image loads it shows its thumbnail blurred under a spinner; a failed one
 * says so and offers the download.
 */
const props = defineProps<{
    item: KinetixLightboxItem;
    /** CSS transform for the image (zoom + pan), owned by the viewer. */
    mediaTransform: string;
    /** Transform for the whole slide while it's dragged (paging / dismissing). */
    slideTransform: string;
    gesturing: boolean;
    zoomable: boolean;
    zoomed: boolean;
    /** Skip the image's fade-in: the viewer animates it from its thumbnail. */
    flipping: boolean;
}>();

const emit = defineEmits<{
    (e: 'ready', media: HTMLImageElement): void;
}>();

const { t } = useI18n();

const media = ref<HTMLImageElement | null>(null);
const loaded = ref(false);
const failed = ref(false);

const type = computed(() => resolveLightboxItemType(props.item));
const downloadHref = computed(() =>
    props.item.downloadUrl === false
        ? null
        : (props.item.downloadUrl ?? props.item.url),
);
const placeholder = computed(() => {
    const thumbnail = props.item.thumbnail;

    return thumbnail && thumbnail !== props.item.url ? thumbnail : null;
});
const cursor = computed(() => {
    if (!props.zoomable) {
        return '';
    }

    if (props.zoomed) {
        return props.gesturing ? 'cursor-grabbing' : 'cursor-grab';
    }

    return 'cursor-zoom-in';
});

function onLoad(): void {
    loaded.value = true;
    failed.value = false;

    if (media.value) {
        emit('ready', media.value);
    }
}

function onError(): void {
    failed.value = true;
}

watch(
    () => props.item.url,
    () => {
        loaded.value = false;
        failed.value = false;
    },
);

onMounted(() => {
    // A cached image can be complete before Vue attaches the listener.
    if (media.value?.complete && media.value.naturalWidth > 0) {
        onLoad();
    }
});

defineExpose({ media, loaded });
</script>

<template>
    <div
        class="p-2 sm:p-6 inset-0 absolute flex items-center justify-center"
        :class="gesturing ? '' : 'ease-out transition-transform duration-200'"
        :style="{ transform: slideTransform }"
    >
        <template v-if="type === 'image'">
            <img
                v-if="placeholder && !loaded && !failed"
                :src="placeholder"
                alt=""
                aria-hidden="true"
                class="inset-0 blur-md absolute m-auto max-h-full max-w-full opacity-60"
            />
            <LoaderCircle
                v-if="!loaded && !failed"
                class="size-8 animate-spin absolute text-muted-foreground"
                aria-hidden="true"
            />
            <div
                v-if="failed"
                class="gap-3 p-6 text-sm flex flex-col items-center text-center text-muted-foreground"
                role="alert"
                data-lightbox-media
            >
                <ImageOff class="size-8" aria-hidden="true" />
                <span>{{ t('kinetix.lightbox_image_failed') }}</span>
                <a
                    v-if="downloadHref"
                    :href="downloadHref"
                    target="_blank"
                    rel="noopener"
                    :class="
                        cn(buttonVariants({ variant: 'outline', size: 'sm' }))
                    "
                >
                    <Download class="size-4" aria-hidden="true" />
                    {{ item.downloadLabel ?? t('kinetix.download') }}
                </a>
            </div>
            <img
                v-show="!failed"
                ref="media"
                data-lightbox-media
                :src="item.url"
                :alt="item.alt ?? item.caption ?? ''"
                draggable="false"
                class="relative max-h-full max-w-full origin-center select-none"
                :class="[
                    cursor,
                    loaded ? 'opacity-100' : 'opacity-0',
                    gesturing || flipping
                        ? ''
                        : 'ease-out transition-[opacity,transform] duration-200',
                ]"
                :style="{ transform: mediaTransform }"
                @load="onLoad"
                @error="onError"
            />
        </template>

        <object
            v-else-if="type === 'pdf'"
            :data="item.url"
            type="application/pdf"
            class="rounded-lg max-w-5xl h-full w-full border border-border bg-background"
        >
            <iframe
                :src="item.url"
                class="rounded-lg h-full w-full border-0"
                :title="item.caption ?? item.alt ?? t('kinetix.preview')"
            ></iframe>
        </object>

        <div
            v-else
            class="gap-3 p-8 text-sm rounded-xl max-w-sm flex flex-col items-center border border-border bg-card text-center text-muted-foreground"
            data-lightbox-media
        >
            <FileText class="size-8" aria-hidden="true" />
            <span>{{ t('kinetix.preview_unavailable') }}</span>
            <a
                v-if="downloadHref"
                :href="downloadHref"
                target="_blank"
                rel="noopener"
                :class="cn(buttonVariants({ variant: 'outline', size: 'sm' }))"
            >
                <Download class="size-4" aria-hidden="true" />
                {{ item.downloadLabel ?? t('kinetix.download') }}
            </a>
        </div>
    </div>
</template>
