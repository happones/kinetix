<script setup lang="ts">
import { FileText } from '@lucide/vue';
import { nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveLightboxItemType } from '@/composables/useKinetixLightbox';
import { useKinetixReducedMotion } from '@/composables/useKinetixReducedMotion';
import type { KinetixLightboxItem } from '@/types/kinetix';

/**
 * KinetixLightbox's thumbnail strip: one button per item, the current one
 * marked (`aria-current` plus a border, not color alone) and kept scrolled
 * into view. The strip scrolls sideways on its own, so a long gallery never
 * widens the page.
 */
const props = defineProps<{
    items: KinetixLightboxItem[];
    index: number;
}>();

const emit = defineEmits<{
    (e: 'select', index: number): void;
}>();

const { t } = useI18n();
const reduced = useKinetixReducedMotion();
const strip = ref<HTMLElement | null>(null);

function reveal(): void {
    const current = strip.value?.querySelector<HTMLElement>(
        '[aria-current="true"]',
    );

    current?.scrollIntoView?.({
        block: 'nearest',
        inline: 'center',
        behavior: reduced.value ? 'auto' : 'smooth',
    });
}

watch(
    () => props.index,
    () => nextTick(reveal),
);
onMounted(reveal);
</script>

<template>
    <div ref="strip" class="relative shrink-0 overflow-x-auto">
        <ul
            class="gap-2 px-4 pt-2 mx-auto flex w-max pb-[max(0.75rem,env(safe-area-inset-bottom))]"
        >
            <li v-for="(item, position) in items" :key="position">
                <button
                    type="button"
                    class="size-14 flex shrink-0 items-center justify-center overflow-hidden rounded-md border-2 bg-muted transition-[opacity,border-color] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :class="
                        position === index
                            ? 'border-primary opacity-100'
                            : 'border-transparent opacity-50 hover:opacity-100'
                    "
                    :aria-label="
                        t('kinetix.lightbox_go_to', { number: position + 1 })
                    "
                    :aria-current="position === index ? 'true' : undefined"
                    @click="emit('select', position)"
                >
                    <img
                        v-if="resolveLightboxItemType(item) === 'image'"
                        :src="item.thumbnail ?? item.url"
                        alt=""
                        loading="lazy"
                        decoding="async"
                        draggable="false"
                        class="size-full object-cover"
                    />
                    <FileText
                        v-else
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                </button>
            </li>
        </ul>
    </div>
</template>
