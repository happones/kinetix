<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useKinetixLazyWhenEnabled } from '@/composables/useKinetixLazyHost';
import type { KinetixSharedProps } from '@/types/kinetix';
import { KINETIX_CONFIDENTIAL_UNLOCK_BODY } from './kinetixLayoutBodyLoaders';

/**
 * Header-mountable reveal-gate widget: shows locked/unlocked state and a
 * live countdown. Mount once anywhere in the layout — a masked Table/Infolist
 * cell's lock affordance opens the same dialog via `requestConfidentialUnlock()`.
 *
 * The widget downloads only when confidential fields are on
 * (`kinetix_confidential.enabled`); off, this renders nothing and loads
 * nothing.
 */
defineOptions({ inheritAttrs: false });

const page = usePage<KinetixSharedProps>();
const { active, component: Body } = useKinetixLazyWhenEnabled(
    KINETIX_CONFIDENTIAL_UNLOCK_BODY,
    () => page.props.kinetix_confidential?.enabled === true,
);
</script>

<template>
    <component :is="Body" v-if="active" v-bind="$attrs" />
</template>
