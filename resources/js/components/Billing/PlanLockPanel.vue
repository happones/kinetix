<script setup lang="ts">
import { Lock } from '@lucide/vue';
import KinetixBadge from '../primitives/KinetixBadge.vue';
import PlanLockCta from './PlanLockCta.vue';

/**
 * The centred lock stack — padlock, title (+ optional plan pill), body copy
 * and the upgrade CTA. Shared by `<KinetixPlanLock>`'s `card` and `overlay`
 * presentations; the banner lays the same pieces out in a row.
 */
defineProps<{
    title: string;
    description: string;
    /** Plan pill next to the title (e.g. 'Pro'). Null = none. */
    badgeLabel: string | null;
    ctaLabel: string;
    href: string | null;
    modal: boolean;
}>();

const emit = defineEmits<{ (e: 'upgrade'): void }>();
</script>

<template>
    <div class="flex flex-col items-center text-center">
        <div
            class="mb-3 size-10 flex items-center justify-center rounded-full bg-muted"
        >
            <Lock class="size-5 text-muted-foreground" aria-hidden="true" />
        </div>
        <div class="gap-2 flex items-center">
            <h3 class="text-sm font-semibold text-foreground">
                {{ title }}
            </h3>
            <KinetixBadge v-if="badgeLabel" variant="outline" size="sm">
                {{ badgeLabel }}
            </KinetixBadge>
        </div>
        <p class="mt-1 text-sm max-w-sm text-muted-foreground">
            {{ description }}
        </p>
        <PlanLockCta
            class="mt-4"
            :href="href"
            :label="ctaLabel"
            :modal="modal"
            @upgrade="emit('upgrade')"
        />
    </div>
</template>
