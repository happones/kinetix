<script setup lang="ts">
import { onMounted } from 'vue';
import { useKinetixCookieConsent } from '@/composables/useKinetixCookieConsent';
import { useKinetixLazyWhenEnabled } from '@/composables/useKinetixLazyHost';
import { KINETIX_COOKIE_CONSENT_BODY } from './kinetixLayoutBodyLoaders';

/**
 * A shadcn-styled cookie consent bar. Mount once in your layout — it shows
 * until the visitor accepts or declines, then remembers the choice via a
 * plain browser cookie (no server round-trip) and stays hidden. Configured
 * via `config('kinetix.cookie_consent')`. A simple accept/decline bar, not a
 * granular per-category consent manager.
 *
 * The bar's code downloads only for a visitor who still has to choose (or
 * when `useKinetixCookieConsent().visible` is set to show it again).
 */
defineOptions({ inheritAttrs: false });

const { visible, checkConsent } = useKinetixCookieConsent();

// The cookie is read on mount, as the bar always did: the server never
// renders it.
onMounted(checkConsent);

const { active, component: Body } = useKinetixLazyWhenEnabled(
    KINETIX_COOKIE_CONSENT_BODY,
    () => visible.value,
);
</script>

<template>
    <component :is="Body" v-if="active" v-bind="$attrs" />
</template>
