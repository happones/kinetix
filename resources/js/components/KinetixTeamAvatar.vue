<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { resolveIcon } from '@/composables/useKinetixIcons';
import type { KinetixTeamIdentity } from '@/types/kinetix';
import { cn } from './primitives/cn';

/**
 * A team's mark, the left of a team switcher row: its logo — or, with none
 * or one that fails to load, the default logo's icon, else its initials.
 * Its color lives in the indicator on the right (KinetixTeamIndicator).
 */
const props = withDefaults(
    defineProps<{
        team: KinetixTeamIdentity | null;
        size?: 'sm' | 'md';
    }>(),
    { size: 'md' },
);

const failed = ref(false);

// A new logo (a switch, an upload) gets its own chance to load.
watch(
    () => props.team?.logo,
    () => {
        failed.value = false;
    },
);

const showLogo = computed(() => !!props.team?.logo && !failed.value);
const icon = computed(() => resolveIcon(props.team?.logoIcon ?? null));
</script>

<template>
    <span
        class="inline-flex shrink-0"
        :class="size === 'sm' ? 'size-6' : 'size-8'"
        aria-hidden="true"
    >
        <img
            v-if="showLogo"
            :src="team!.logo!"
            alt=""
            class="size-full rounded-md object-cover"
            @error="failed = true"
        />
        <span
            v-else
            :class="
                cn(
                    'font-semibold inline-flex size-full items-center justify-center rounded-md border border-border bg-muted text-muted-foreground',
                    size === 'sm' ? 'text-[10px]' : 'text-xs',
                )
            "
        >
            <component
                :is="icon"
                v-if="icon"
                :class="size === 'sm' ? 'size-3.5' : 'size-4'"
            />
            <template v-else>{{ team?.initials ?? '?' }}</template>
        </span>
    </span>
</template>
