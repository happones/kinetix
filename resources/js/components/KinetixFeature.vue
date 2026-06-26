<script setup lang="ts">
import { computed } from "vue";
import { useKinetixFeature } from "@/composables/useKinetixFeature";

/**
 * Renders its slot only when a feature flag is active (mirrors <KinetixCan>).
 * Provide a `#denied` slot for the off state.
 *
 *   <KinetixFeature flag="beta-search">
 *     <BetaSearch />
 *     <template #denied><LegacySearch /></template>
 *   </KinetixFeature>
 */
const props = defineProps<{
  flag: string;
}>();

const { active } = useKinetixFeature();

const isActive = computed(() => active(props.flag));
</script>

<template>
  <slot v-if="isActive" />
  <slot v-else name="denied" />
</template>
