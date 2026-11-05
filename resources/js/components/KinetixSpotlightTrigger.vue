<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { isMac } from '@/composables/useKinetixHotkeys';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * Header trigger for the Spotlight command palette — pair it with
 * <KinetixSpotlight> mounted once in your layout. On click it opens the palette
 * (the ⌘K / Ctrl+K shortcut still works independently). On small screens it
 * collapses to an icon button (notification-trigger style); from `sm` up it
 * shows a search-box style hint with the keyboard shortcut. With
 * `kinetix.spotlight.enabled` off there is no palette to open, so it renders
 * nothing.
 */
const { t } = useI18n();
const shortcut = computed(() => (isMac() ? '⌘K' : 'Ctrl K'));
const page = usePage<KinetixSharedProps>();
const enabled = computed(
    () => page.props.kinetix_config?.spotlight?.enabled !== false,
);

function open(): void {
    window.dispatchEvent(new CustomEvent('kinetix:spotlight'));
}
</script>

<template>
    <!-- Collapsed, this is a header trigger like any other: the SAME `outline`
         + `icon-sm` button as announcements, notifications, accessibility, dark
         mode and language — it used to be a borderless 36px square, so it read
         as a different control and sat 4px taller than the row. From `sm` up
         the same surface just grows into a search box. -->
    <button
        v-if="enabled"
        type="button"
        :aria-label="t('kinetix.spotlight_placeholder')"
        :title="`${t('kinetix.spotlight_placeholder')} (${shortcut})`"
        :class="[
            buttonVariants({ variant: 'outline', size: 'icon-sm' }),
            'font-normal sm:w-auto sm:min-w-[12rem] sm:justify-start sm:px-3 text-muted-foreground',
        ]"
        @click="open"
    >
        <Search class="h-4 w-4 shrink-0" />
        <span class="text-sm sm:inline hidden flex-1 text-left">
            {{ t('kinetix.spotlight_placeholder') }}
        </span>
        <kbd
            class="gap-0.5 rounded px-1.5 font-mono font-medium sm:inline-flex hidden items-center border border-border bg-muted text-[10px] text-muted-foreground"
        >
            {{ shortcut }}
        </kbd>
    </button>
</template>
