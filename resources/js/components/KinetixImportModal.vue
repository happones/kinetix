<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { KINETIX_IMPORT_SETTINGS_DEFAULTS } from '@/composables/useKinetixImporter';
import type { KinetixImportSettings } from '@/types/kinetix';
import KinetixImporter from './KinetixImporter.vue';
import KinetixSheet from './KinetixSheet.vue';
import KinetixModal from './primitives/KinetixModal.vue';

/**
 * Global import dialog. Mount once in your layout:
 *   <KinetixImportModal />
 *
 * Opens on the `kinetix:open-importer` window event (dispatched by
 * `ImportAction::make()->importer(...)`), showing `KinetixImporter` for the
 * importer carried in the event detail.
 *
 * The SHELL is chosen from the importer's settings, because one size does not
 * fit every file: a narrow file is a dialog, a wide one needs the room. With
 * `layout: 'auto'` (the default) the dialog promotes ITSELF to full screen once
 * the parsed file exceeds `fullscreenThreshold` columns — the same
 * `KinetixModal`, just bigger, so the wizard is resized rather than remounted
 * and nothing the user has already done is lost. `'sheet'` is an explicit
 * opt-in only, since swapping the shell mid-flow WOULD lose that state.
 */

const { t } = useI18n();
const open = ref(false);
const token = ref<string | null>(null);
const settings = ref<KinetixImportSettings>({
    ...KINETIX_IMPORT_SETTINGS_DEFAULTS,
});

/** Source columns of the parsed file (0 until one is parsed). */
const columnCount = ref(0);

const isSheet = computed(() => settings.value.layout === 'sheet');

const isFullscreen = computed(() => {
    if (settings.value.layout === 'fullscreen') {
        return true;
    }

    return (
        settings.value.layout === 'auto' &&
        columnCount.value > settings.value.fullscreenThreshold
    );
});

const surface = computed<'modal' | 'fullscreen' | 'sheet'>(() => {
    if (isSheet.value) {
        return 'sheet';
    }

    return isFullscreen.value ? 'fullscreen' : 'modal';
});

function onOpen(event: Event): void {
    const detail = (
        event as CustomEvent<{
            importer?: string;
            template?: string | null;
            settings?: Partial<KinetixImportSettings> | null;
        }>
    ).detail;

    if (!detail?.importer) {
        return;
    }

    token.value = detail.importer;
    columnCount.value = 0;
    settings.value = {
        ...KINETIX_IMPORT_SETTINGS_DEFAULTS,
        ...(detail.settings ?? {}),
        // `template` also arrives on its own, for callers that only send it.
        ...(detail.template ? { template: detail.template } : {}),
    };
    open.value = true;
}

function close(): void {
    open.value = false;
}

onMounted(() => window.addEventListener('kinetix:open-importer', onOpen));
onBeforeUnmount(() =>
    window.removeEventListener('kinetix:open-importer', onOpen),
);
</script>

<template>
    <KinetixSheet
        v-if="isSheet"
        :open="open"
        :title="t('kinetix.import')"
        size="w-full sm:max-w-3xl"
        @update:open="open = $event"
    >
        <KinetixImporter
            v-if="token"
            :importer="token"
            :settings="settings"
            surface="sheet"
            @update:columns="columnCount = $event"
            @started="close"
            @cancel="close"
        />
    </KinetixSheet>

    <KinetixModal
        v-else
        :open="open"
        :title="t('kinetix.import')"
        :max-width="isFullscreen ? 'sm:max-w-[95vw]' : 'sm:max-w-3xl'"
        :fullscreen="isFullscreen"
        @update:open="open = $event"
    >
        <KinetixImporter
            v-if="token"
            :importer="token"
            :settings="settings"
            :surface="surface"
            @update:columns="columnCount = $event"
            @started="close"
            @cancel="close"
        />
    </KinetixModal>
</template>
