<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import KinetixButton from '../KinetixButton.vue';

/**
 * The upgrade CTA shared by every `<KinetixPlanLock>` presentation (card,
 * overlay, banner) so the upsell button can't drift between them. Renders a
 * button that opens the upgrade modal (`modal`) or a direct link to the
 * upgrade URL — and nothing at all without a URL, so a lock never ships a
 * dead-end CTA.
 */
defineProps<{
    /** Resolved upgrade URL (`kinetix.billing.upgrade_url` or an override). */
    href: string | null;
    label: string;
    /** Open the upgrade modal instead of navigating. */
    modal: boolean;
}>();

const emit = defineEmits<{ (e: 'upgrade'): void }>();
</script>

<template>
    <KinetixButton v-if="href && modal" size="sm" @click="emit('upgrade')">
        {{ label }}
    </KinetixButton>
    <Link v-else-if="href" :href="href" :class="buttonVariants({ size: 'sm' })">
        {{ label }}
    </Link>
</template>
