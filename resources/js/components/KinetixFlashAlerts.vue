<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { onKinetixFlash } from '@/composables/useKinetixFlash';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import type { KinetixTransitionPreset } from '@/composables/useKinetixTransition';
import type { KinetixFlashAlert, KinetixSharedProps } from '@/types/kinetix';
import KinetixAlert from './KinetixAlert.vue';
import { cn } from './primitives/cn';

/**
 * Where flashed alerts appear. Mount it once where page-level messages belong
 * (under the page header is typical):
 *
 *     <KinetixFlashAlerts />
 *
 * It shows two kinds, both sent with `KinetixFlash::alert()`:
 *
 * - One-shot alerts (the default) arrive over Inertia's flash, which the
 *   browser history never stores. They are collected from the `flash` event,
 *   not read off `page.flash` — a poll or a partial reload replaces that with
 *   an empty object — and they leave when the user moves to another page.
 * - Session alerts (`->keep()` / `->untilDismissed()`) ride the
 *   `kinetix_alerts` prop. Closing one reports to the server, and the tab
 *   remembers it so a page restored from history doesn't show it again.
 *
 * New one-shot alerts are announced to screen readers (a `danger` one is an
 * assertive `alert` already, so it isn't announced twice).
 */
const props = withDefaults(
    defineProps<{
        /** How each alert enters and leaves. */
        transition?: KinetixTransitionPreset;
        /** Render the titles as `h2`…`h6`; unset = paragraphs. */
        headingLevel?: 2 | 3 | 4 | 5 | 6 | null;
        class?: string;
    }>(),
    {
        transition: 'slide-down',
        headingLevel: null,
    },
);

const page = usePage<KinetixSharedProps>();
const { announce } = useKinetixAnnounce();

const flashed = ref<KinetixFlashAlert[]>([]);

function pathOf(url: string | undefined): string {
    try {
        return new URL(url ?? '', 'http://kinetix.local').pathname;
    } catch {
        return url ?? '';
    }
}

let path = pathOf(page.url);

function receive(alerts: KinetixFlashAlert[] | undefined): void {
    for (const alert of alerts ?? []) {
        if (flashed.value.some((existing) => existing.id === alert.id)) {
            continue;
        }

        flashed.value = [...flashed.value, alert];

        if (alert.color !== 'danger') {
            announce(alert.title);
        }
    }
}

// A one-shot alert belongs to the page it arrived on. `navigate` fires before
// the visit's own `flash`, so a redirect clears the old page's alerts and then
// receives its own. Same path (a poll, a filter) = same page: they stay.
let stopNavigate: () => void = () => {};

try {
    stopNavigate = router.on('navigate', (event) => {
        const next = pathOf(event.detail.page.url);

        if (next !== path) {
            path = next;
            flashed.value = [];
        }
    });
} catch {
    // Outside a full Inertia app there is no navigation to follow.
}

// Registered AFTER the path watcher: on a visit to another page the old alerts
// are cleared first, then the new page's (from a `kinetix_flash` prop, which
// arrives with `navigate` too) are received.
const stopFlash = onKinetixFlash((payload) => receive(payload.alerts));

onBeforeUnmount(() => {
    stopFlash();
    stopNavigate();
});

const alerts = computed<KinetixFlashAlert[]>(() => {
    const stored = page.props.kinetix_alerts ?? [];
    const ids = new Set(stored.map((alert) => alert.id));

    return [...stored, ...flashed.value.filter((alert) => !ids.has(alert.id))];
});

function persistFor(
    alert: KinetixFlashAlert,
): ((key: string) => Promise<unknown>) | null {
    const url = alert.dismissUrl;

    return alert.persistent && url
        ? () => kinetixFetch(url, { method: 'POST' })
        : null;
}
</script>

<template>
    <div
        v-if="alerts.length"
        data-slot="kinetix-flash-alerts"
        :class="cn('gap-3 grid', props.class)"
    >
        <KinetixAlert
            v-for="alert in alerts"
            :key="alert.id"
            :color="alert.color"
            :variant="alert.variant ?? 'soft'"
            :icon="alert.icon ?? undefined"
            :title="alert.title"
            :description="alert.description ?? null"
            :heading-level="headingLevel"
            :dismissible="alert.dismissible"
            :dismiss-mode="alert.persistent ? 'permanent' : 'hide'"
            :dismiss-key="alert.persistent ? `flash:${alert.id}` : null"
            :persist="persistFor(alert)"
            :transition="transition"
            :appear="!alert.persistent"
        />
    </div>
</template>
