<script setup lang="ts">
/**
 * A live-component demo frame for the docs. Wraps a real, interactive Kinetix
 * component (passed in the default slot) in the same card chrome the gallery
 * uses, so a reader can TOUCH the component instead of looking at a screenshot.
 *
 *     <Demo title="Password generator">
 *       <KinetixGenerator :config="{ kind: 'password', length: 16 }" copyable />
 *     </Demo>
 *
 * Only mounts client-side (SSR produces a placeholder), since the components
 * assume a browser (clipboard, crypto, etc.).
 */
withDefaults(
    defineProps<{
        title?: string;
        /** Max width of the demo surface (px). */
        width?: number | string;
    }>(),
    { title: "", width: 420 },
);
</script>

<template>
    <ClientOnly>
        <div class="kinetix-demo my-4 overflow-hidden rounded-xl border border-border">
            <div
                v-if="title"
                class="border-b border-border bg-muted/40 px-4 py-2 text-xs font-semibold text-muted-foreground"
            >
                {{ title }}
            </div>
            <div class="flex justify-center bg-background p-6">
                <div
                    class="w-full"
                    :style="{ maxWidth: typeof width === 'number' ? `${width}px` : width }"
                >
                    <slot />
                </div>
            </div>
        </div>
        <template #fallback>
            <div class="my-4 rounded-xl border border-border bg-muted/20 p-6 text-center text-sm text-muted-foreground">
                Loading demo…
            </div>
        </template>
    </ClientOnly>
</template>
