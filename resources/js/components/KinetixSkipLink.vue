<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * "Skip to content" link for keyboard / screen-reader users — visually hidden
 * until focused, then the first thing in the tab order. Place it as the first
 * element in your layout and give your main region a matching id:
 *
 *   <KinetixSkipLink target="#main" />
 *   <main id="main">…</main>
 */
withDefaults(defineProps<{ target?: string }>(), { target: '#main' });

const { t } = useI18n();
</script>

<template>
    <a
        :href="target"
        class="focus:left-3 focus:top-3 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:shadow-lg sr-only focus:not-sr-only focus:fixed focus:z-[200] focus:rounded-md focus:bg-primary focus:text-primary-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
    >
        {{ t('kinetix.skip_to_content') }}
    </a>
</template>
