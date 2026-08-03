<script setup lang="ts">
import { computed } from 'vue';
import { useKinetixLocale } from '@/composables/useKinetixLocale';
import LanguageDropdown from './LanguageSwitcher/LanguageDropdown.vue';
import LanguageSelect from './LanguageSwitcher/LanguageSelect.vue';

/**
 * A self-service language switcher in two shapes:
 *
 * - `dropdown` (default) — a Languages icon opening a menu of locales. Compact,
 *   for a header or toolbar.
 * - `select` — a labelled Select field, for a settings or profile form.
 *
 * Both drive the same state, so several switchers can coexist on one page and
 * stay in agreement. Switching flips the app language instantly (vue-i18n) and
 * persists the choice server-side. Works for guests too, so it can sit on the
 * login screen.
 */
const props = withDefaults(
    defineProps<{
        /** Which shape to render. */
        variant?: 'dropdown' | 'select';
        /**
         * Dropdown: show the active locale's code beside the icon ("EN").
         * Select: render the visible field label. Defaults per variant —
         * off for the dropdown, on for the select.
         */
        showLabel?: boolean;
        /** Select only: override the field label text. */
        label?: string | null;
    }>(),
    { variant: 'dropdown', showLabel: undefined, label: null },
);

const { locales, current, saving, setLocale } = useKinetixLocale();

const isSelect = computed(() => props.variant === 'select');

const showLabel = computed(() => props.showLabel ?? isSelect.value);
</script>

<template>
    <LanguageSelect
        v-if="isSelect"
        :locales="locales"
        :current="current"
        :saving="saving"
        :show-label="showLabel"
        :label="props.label"
        @select="setLocale"
    />

    <LanguageDropdown
        v-else
        :locales="locales"
        :current="current"
        :saving="saving"
        :show-label="showLabel"
        @select="setLocale"
    />
</template>
