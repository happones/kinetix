<script setup lang="ts">
import { computed } from 'vue';
import { resolveIcon } from '@/composables/useKinetixIcons';
import type { KinetixTeamIdentity } from '@/types/kinetix';

/**
 * The right of a team switcher row: a small dot in the color of the team's
 * type (an industry, a plan, an environment) — or the icon configured in its
 * place, in that color. Nothing when the team has neither.
 */
const props = defineProps<{
    team: KinetixTeamIdentity | null;
}>();

const icon = computed(() => resolveIcon(props.team?.icon ?? null));
const accent = computed(() => props.team?.accent ?? null);
</script>

<template>
    <!-- A 16px box either way, so a column of dots and icons lines up. -->
    <span
        v-if="icon || accent"
        class="size-4 inline-flex shrink-0 items-center justify-center"
        aria-hidden="true"
        data-kinetix-team-indicator
    >
        <component
            :is="icon"
            v-if="icon"
            class="size-4"
            :class="{ 'text-muted-foreground': !accent }"
            :style="accent ? { color: accent } : undefined"
        />
        <span
            v-else
            class="size-2 rounded-full"
            :style="{ backgroundColor: accent! }"
        />
    </span>
</template>
