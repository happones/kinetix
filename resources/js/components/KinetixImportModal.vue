<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import KinetixImporter from './KinetixImporter.vue';
import KinetixModal from './primitives/KinetixModal.vue';

/**
 * Global import dialog. Mount once in your layout:
 *   <KinetixImportModal />
 *
 * Opens on the `kinetix:open-importer` window event (dispatched by
 * `ImportAction::make()->importer(...)`), showing `KinetixImporter` for the
 * importer carried in the event detail.
 */

const { t } = useI18n();
const open = ref(false);
const token = ref<string | null>(null);
const template = ref<string | null>(null);

function onOpen(event: Event): void {
    const detail = (
        event as CustomEvent<{ importer?: string; template?: string | null }>
    ).detail;

    if (!detail?.importer) {
        return;
    }

    token.value = detail.importer;
    template.value = detail.template ?? null;
    open.value = true;
}

onMounted(() => window.addEventListener('kinetix:open-importer', onOpen));
onBeforeUnmount(() =>
    window.removeEventListener('kinetix:open-importer', onOpen),
);
</script>

<template>
    <KinetixModal
        :open="open"
        :title="t('kinetix.import')"
        max-width="sm:max-w-3xl"
        scroll-body
        @update:open="open = $event"
    >
        <KinetixImporter v-if="token" :importer="token" :template="template" />
    </KinetixModal>
</template>
