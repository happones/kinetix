<script setup lang="ts">
import { ZoomIn } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    collectLightboxGroup,
    openKinetixLightbox,
    registerLightboxItem,
    unregisterLightboxItem,
} from '@/composables/useKinetixLightbox';
import type {
    KinetixLightboxItem,
    KinetixLightboxOptions,
} from '@/types/kinetix';
import { cn } from './primitives/cn';

/**
 * A thumbnail that opens in the app's lightbox (`<KinetixFilePreview />`):
 *
 *   <KinetixLightboxImage src="/photos/1.jpg" thumbnail="/photos/1-sm.jpg"
 *       alt="Front door" caption="Front door" group="house" />
 *
 * Thumbnails sharing a `group` browse as one gallery, in page order, inside
 * the closest `[data-kinetix-lightbox-scope]` — or the closest `scope`
 * selector when given (e.g. `scope="table"` keeps two tables apart). The
 * image zooms out of the thumbnail and back into it.
 *
 * The default slot replaces the thumbnail markup; `imgClass` styles the
 * default one. It's a real button: keyboard-reachable, named after the image.
 */
const props = withDefaults(
    defineProps<{
        src: string;
        thumbnail?: string | null;
        alt?: string | null;
        caption?: string | null;
        description?: string | null;
        /** Download target; `false` hides the button. */
        downloadUrl?: string | false | null;
        type?: KinetixLightboxItem['type'];
        group?: string | null;
        /** Selector for the ancestor a group is collected in. */
        scope?: string | null;
        imgClass?: string;
        /** Round trigger (and focus ring) for circular thumbnails. */
        circular?: boolean;
        /** Per-opening viewer options (override the host's defaults). */
        options?: KinetixLightboxOptions;
    }>(),
    {
        thumbnail: null,
        alt: null,
        caption: null,
        description: null,
        downloadUrl: null,
        type: 'image',
        group: null,
        scope: null,
        imgClass: '',
        circular: false,
        options: undefined,
    },
);

const { t } = useI18n();
const trigger = ref<HTMLButtonElement | null>(null);

const name = computed(() => props.caption || props.alt || '');
const label = computed(() =>
    name.value
        ? t('kinetix.lightbox_view', { name: name.value })
        : t('kinetix.lightbox_view_image'),
);

function item(): KinetixLightboxItem {
    return {
        url: props.src,
        type: props.type,
        thumbnail: props.thumbnail ?? props.src,
        alt: props.alt,
        caption: props.caption,
        description: props.description,
        downloadUrl: props.downloadUrl,
        origin: trigger.value?.querySelector('img') ?? trigger.value,
    };
}

function open(event: MouseEvent): void {
    const element = trigger.value;

    if (!element) {
        return;
    }

    event.preventDefault();

    const scope = props.scope ? element.closest(props.scope) : null;
    const gallery = collectLightboxGroup(element, scope);

    openKinetixLightbox(gallery.items, {
        index: gallery.index,
        options: props.options,
    });
}

onMounted(() => {
    if (trigger.value) {
        registerLightboxItem(trigger.value, item);
    }
});

onBeforeUnmount(() => {
    if (trigger.value) {
        unregisterLightboxItem(trigger.value);
    }
});

defineExpose({ el: trigger });
</script>

<template>
    <button
        ref="trigger"
        type="button"
        :data-kinetix-lightbox="group ?? ''"
        :class="circular ? 'rounded-full' : 'rounded-lg'"
        class="group/lightbox relative inline-flex max-w-full cursor-zoom-in overflow-hidden align-middle outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        :aria-label="label"
        aria-haspopup="dialog"
        @click="open"
    >
        <slot>
            <img
                :src="thumbnail ?? src"
                :alt="alt ?? ''"
                loading="lazy"
                decoding="async"
                :class="
                    cn(
                        'ease-out block max-w-full object-cover transition-transform duration-200 group-hover/lightbox:scale-[1.03]',
                        imgClass,
                    )
                "
            />
        </slot>
        <span
            class="inset-0 bg-black/30 absolute flex items-center justify-center opacity-0 transition-opacity duration-150 group-hover/lightbox:opacity-100 group-focus-visible/lightbox:opacity-100"
            aria-hidden="true"
        >
            <ZoomIn class="size-5 text-white drop-shadow" />
        </span>
    </button>
</template>
