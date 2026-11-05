<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useKinetixLazyOnDemand } from '@/composables/useKinetixLazyHost';
import type { KinetixSharedProps } from '@/types/kinetix';
import { KINETIX_SPOTLIGHT_BODY } from './kinetixLayoutBodyLoaders';

/**
 * Global Cmd/Ctrl+K command palette. Mount once in your layout. Searches the
 * spotlight endpoint; server results are already authorization-filtered.
 *
 * This is the light host: it owns the shortcut and the `kinetix:spotlight`
 * event (dispatched by `<KinetixSpotlightTrigger>` in a header),
 * claims the keystroke at once, and downloads the palette on the first
 * opening. With `kinetix.spotlight.enabled` off it listens to nothing and
 * loads nothing.
 */
defineOptions({ inheritAttrs: false });

const page = usePage<KinetixSharedProps>();
const enabled = computed(
    () => page.props.kinetix_config?.spotlight?.enabled !== false,
);

const {
    body,
    open,
    request: requestOpen,
} = useKinetixLazyOnDemand(KINETIX_SPOTLIGHT_BODY, 'The command palette');

function onKeydown(event: KeyboardEvent): void {
    if (
        enabled.value &&
        (event.metaKey || event.ctrlKey) &&
        event.key.toLowerCase() === 'k'
    ) {
        event.preventDefault();
        requestOpen();
    }
}

// Allow an external trigger (e.g. <KinetixSpotlightTrigger> in the header) to
// open the palette without coupling components.
function onExternalOpen(): void {
    if (enabled.value) {
        requestOpen();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('kinetix:spotlight', onExternalOpen);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('kinetix:spotlight', onExternalOpen);
});
</script>

<template>
    <component :is="body" v-if="body" v-model:open="open" v-bind="$attrs" />
</template>
