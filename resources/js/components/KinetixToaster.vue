<script lang="ts">
/**
 * Uuids already toasted. Module-level (a plain `<script>`, not `setup`), so it
 * outlives a layout that re-creates the toaster on every page; bounded, since
 * a long session flashes a lot.
 */
const SHOWN_LIMIT = 50;
const shown = new Set<string>();

function remember(id: string): void {
    shown.add(id);

    if (shown.size > SHOWN_LIMIT) {
        shown.delete(shown.values().next().value as string);
    }
}
</script>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, watch } from 'vue';
import { toast, Toaster } from 'vue-sonner';
import type { ToasterProps } from 'vue-sonner';
import { onKinetixFlash } from '@/composables/useKinetixFlash';
import type { KinetixFlashToast } from '@/types/kinetix';

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
 * The prop rides the page props, which Inertia keeps in the browser history:
 * Back/Forward restores the page WITH its old toast. Every uuid already shown
 * is remembered for the life of the tab, so a restored page stays silent.
 *
 * It shows the toasts of Inertia's own flash channel too — `KinetixFlash::
 * success()` and friends on the server, `useKinetixFlash()` on the client —
 * which the history never stores in the first place.
 *
 * Mount once in your layout: <KinetixToaster />. Forwards all vue-sonner
 * Toaster props (position, richColors, duration, …).
 */
defineProps<ToasterProps>();

type FlashToast = Pick<KinetixFlashToast, 'id' | 'type' | 'message'> &
    Partial<Pick<KinetixFlashToast, 'description' | 'duration'>>;

function show(flash: FlashToast | null | undefined): void {
    if (!flash?.message || shown.has(flash.id)) {
        return;
    }

    remember(flash.id);

    const fire = toast[flash.type] ?? toast.success;
    const options = {
        ...(flash.description ? { description: flash.description } : {}),
        ...(flash.duration ? { duration: flash.duration } : {}),
    };

    if (Object.keys(options).length > 0) {
        fire(flash.message, options);
    } else {
        fire(flash.message);
    }
}

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
        show,
        { immediate: true },
    );
}

const stopFlash = onKinetixFlash((payload) => payload.toasts?.forEach(show));
onBeforeUnmount(stopFlash);
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
