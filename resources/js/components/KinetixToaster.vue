<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { toast, Toaster } from 'vue-sonner';
import type { ToasterProps } from 'vue-sonner';

/**
 * Toaster pre-styled with shadcn semantic tokens, so Kinetix toasts (export /
 * import notifications, etc.) read correctly in both light and dark mode.
 *
 * It does NOT fight vue-sonner over CSS specificity (class overrides lose when
 * `vue-sonner/style.css` is loaded after Tailwind). Instead it redefines the
 * very CSS variables vue-sonner reads — `--normal-bg`/`--normal-text`/
 * `--normal-border` — pointing them at shadcn tokens (`--popover`, etc.) that
 * already flip with `.dark`, so the toast follows the host theme automatically.
 *
 * It also turns the `kinetix_toast` flash prop into a toast: any controller
 * (the Kinetix record endpoints, your scaffolded controllers, your own code)
 * can `->with('kinetix_toast', __('kinetix.record_created'))` — or
 * `['type' => 'error', 'message' => …]` — and the message shows here. The
 * server stamps a uuid per flash, so the same text twice in a row still fires.
 *
 * Mount once in your layout: <KinetixToaster />. Forwards all vue-sonner
 * Toaster props (position, richColors, duration, …).
 */
defineProps<ToasterProps>();

type FlashToast = {
    type: 'success' | 'error' | 'info' | 'warning';
    message: string;
    id: string;
};

// Defensive access: the component may mount outside a full Inertia app (tests).
let page: { props?: Record<string, unknown> } | null = null;

try {
    page = usePage();
} catch {
    page = null;
}

if (page) {
    watch(
        () => page?.props?.kinetix_toast as FlashToast | null | undefined,
        (flash, previous) => {
            if (!flash?.message || flash.id === previous?.id) {
                return;
            }

            const show = toast[flash.type] ?? toast.success;
            show(flash.message);
        },
        { immediate: true },
    );
}
</script>

<template>
    <Toaster
        class="toaster group"
        style="
            --normal-bg: var(--popover);
            --normal-text: var(--popover-foreground);
            --normal-border: var(--border);
        "
        v-bind="$props"
    />
</template>
