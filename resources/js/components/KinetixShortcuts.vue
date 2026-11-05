<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixHotkeys } from '@/composables/useKinetixHotkeys';
import { useKinetixLazyOnDemand } from '@/composables/useKinetixLazyHost';
import { KINETIX_SHORTCUTS_BODY } from './kinetixLayoutBodyLoaders';

/**
 * Press `?` to open a cheat-sheet of the active keyboard shortcuts (the labelled
 * ones). Mount once in your authenticated layout. Renders nothing until opened:
 * the `?` hotkey is registered here at once, and the cheat-sheet downloads the
 * first time it is pressed.
 */
defineOptions({ inheritAttrs: false });

const { t } = useI18n();
const { register } = useKinetixHotkeys();

const {
    body,
    open,
    request: requestOpen,
} = useKinetixLazyOnDemand(KINETIX_SHORTCUTS_BODY, 'The shortcuts sheet');

onMounted(() => {
    register({
        keys: '?',
        label: t('kinetix.shortcuts_title'),
        handler: requestOpen,
    });
});
</script>

<template>
    <component :is="body" v-if="body" v-model:open="open" v-bind="$attrs" />
</template>
