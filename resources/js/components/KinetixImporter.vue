<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Upload } from '@lucide/vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import {
    KINETIX_IMPORT_SETTINGS_DEFAULTS,
    useKinetixImporter,
} from '@/composables/useKinetixImporter';
import type { KinetixImporterStep } from '@/composables/useKinetixImporter';
import type {
    KinetixImportPreview,
    KinetixImportSettings,
} from '@/types/kinetix';
import ImporterDropzone from './Importer/ImporterDropzone.vue';
import ImporterMapping from './Importer/ImporterMapping.vue';
import ImporterOptions from './Importer/ImporterOptions.vue';
import ImporterPreview from './Importer/ImporterPreview.vue';
import ImporterSteps from './Importer/ImporterSteps.vue';
import KinetixButton from './KinetixButton.vue';
import ScrollArea from './primitives/ScrollArea.vue';

/**
 * The import wizard: choose a file → confirm the column mapping → review and
 * start.
 *
 * Why three steps: a single scrolling panel had to hold the parse options, one
 * select per target column and a preview table at once, so a file with twenty
 * or more columns grew the dialog past the viewport and stranded its own
 * actions. Each step is now bounded, and the file's width only ever affects the
 * step that is actually showing it.
 *
 * Scale is a server concern and stays one: the preview is a SAMPLE
 * (`settings.previewRows` rows, which is also the reader's ceiling), the row
 * count is counted rather than parsed, and the import itself streams. Nothing
 * here grows with the size of the file — a million-row upload behaves like a
 * ten-row one.
 *
 * `surface` says who owns the scrolling, so the same component works inline on
 * a page, in a modal, in a full-screen modal and in a sheet.
 */
const props = withDefaults(
    defineProps<{
        importer: string;
        routePrefix?: string | null;
        /**
         * Template filename when the importer offers a downloadable template.
         * Superseded by `settings.template`; kept for callers that pass it
         * alone.
         */
        template?: string | null;
        /**
         * The importer's resolved dialog settings (preview limits, layout,
         * upload ceiling). `ImportAction` sends these with the
         * `open-importer` event; until the first upload answers with the real
         * ones, these are what the dialog uses.
         */
        settings?: KinetixImportSettings | null;
        /**
         * Where the wizard is rendered, which decides what scrolls:
         * `inline` — the page scrolls (default, for a wizard placed on a page);
         * `modal` — the step body scrolls, capped so the dialog stays put;
         * `fullscreen` — the step body fills the panel;
         * `sheet` — the host panel already scrolls, so the wizard adds none.
         */
        surface?: 'inline' | 'modal' | 'fullscreen' | 'sheet';
    }>(),
    {
        routePrefix: null,
        template: null,
        settings: null,
        surface: 'inline',
    },
);

const emit = defineEmits<{
    /** The parsed file's source-column count (0 before a file is parsed). */
    (e: 'update:columns', count: number): void;
    /** The import was queued — the dialog can close. */
    (e: 'started', message: string | null): void;
    /** The user backed out. */
    (e: 'cancel'): void;
}>();

const { t } = useI18n();
const page = usePage();
const { announce } = useKinetixAnnounce();

const prefix = computed(
    () =>
        (page.props.kinetix_config as { route_prefix?: string } | undefined)
            ?.route_prefix ??
        props.routePrefix ??
        '_kinetix',
);

/** Caller-supplied settings, with the standalone `template` prop folded in. */
const providedSettings = computed<KinetixImportSettings>(() => ({
    ...KINETIX_IMPORT_SETTINGS_DEFAULTS,
    ...(props.settings ?? {}),
    ...(props.template ? { template: props.template } : {}),
}));

const {
    file,
    preview,
    mapping,
    parseOptions,
    loading,
    starting,
    errorMessage,
    step,
    settings,
    templateUrl,
    headers,
    columnCount,
    mappedCount,
    missingRequired,
    unusedHeaders,
    canStart,
    setFile,
    setMapping,
    columnForHeader,
    resetMapping,
    upload,
    applyParseOptions,
    startImport,
    goTo,
    back,
} = useKinetixImporter({
    importer: () => props.importer,
    prefix: () => prefix.value,
    settings: () => providedSettings.value,
    fallbackError: () => t('kinetix.import_failed'),
    announce,
});

// The shell sizes itself from the file's width, so it needs the count.
watch(columnCount, (count) => emit('update:columns', count), {
    immediate: true,
});

/** Steps the indicator lets the user jump back to. */
const reachableSteps = computed<KinetixImporterStep[]>(() => {
    if (preview.value === null) {
        return ['file'];
    }

    return canStart.value ? ['file', 'mapping', 'review'] : ['file', 'mapping'];
});

const canContinue = computed(() => {
    if (step.value === 'file') {
        return file.value !== null;
    }

    return canStart.value;
});

const stepBodyClass = computed(() => {
    if (props.surface === 'fullscreen') {
        return 'min-h-0 flex-1';
    }

    // Capped rather than unbounded: this is what keeps a fifty-column file from
    // growing the dialog past the viewport.
    return 'max-h-[min(55vh,32rem)]';
});

const onContinue = async (): Promise<void> => {
    if (step.value === 'file') {
        // A file that was already parsed doesn't need re-uploading to move on.
        const alreadyParsed = preview.value;

        if (alreadyParsed !== null) {
            goTo(alreadyParsed.isExactMatch ? 'review' : 'mapping');

            return;
        }

        await upload();
        const parsed = preview.value as KinetixImportPreview | null;

        if (parsed !== null) {
            announce(
                t('kinetix.import_parsed_announce', {
                    rows: parsed.totalRows,
                    columns: parsed.headers.length,
                }),
            );
        }

        return;
    }

    if (step.value === 'mapping') {
        goTo('review');

        return;
    }

    const message = await startImport();

    if (message === null && errorMessage.value !== null) {
        return;
    }

    // The message comes from Importer::getStartedNotificationBody(), so a
    // customized importer message reaches the toast too.
    const body = message ?? t('kinetix.import_started');
    toast.success(body);
    announce(body);
    emit('started', message);
};

const summaryRows = computed(() => {
    if (!preview.value) {
        return [];
    }

    return [
        {
            label: t('kinetix.import_summary_file'),
            value: file.value?.name ?? '—',
        },
        {
            label: t('kinetix.import_summary_rows'),
            value: preview.value.totalRows.toLocaleString(),
        },
        {
            label: t('kinetix.import_summary_mapped'),
            value: `${mappedCount.value} / ${preview.value.columns.length}`,
        },
        {
            label: t('kinetix.import_summary_ignored'),
            value: String(unusedHeaders.value.length),
        },
    ];
});
</script>

<template>
    <div
        class="gap-4 flex flex-col"
        :class="surface === 'fullscreen' ? 'min-h-0 h-full' : ''"
    >
        <ImporterSteps
            :current="step"
            :reachable="reachableSteps"
            @select="goTo"
        />

        <!-- Errors are announced and sit above the step they belong to, never
             only painted on a field. -->
        <p
            v-if="errorMessage"
            class="px-3 py-2 text-sm rounded-md bg-destructive/10 text-destructive"
            role="alert"
        >
            {{ errorMessage }}
        </p>

        <component
            :is="
                surface === 'sheet' || surface === 'inline' ? 'div' : ScrollArea
            "
            v-bind="
                surface === 'sheet' || surface === 'inline'
                    ? {}
                    : { type: 'auto', class: `-mr-3 pr-3 ${stepBodyClass}` }
            "
            :class="surface === 'fullscreen' ? 'min-h-0 flex-1' : ''"
        >
            <div class="gap-4 flex flex-col">
                <!-- Step 1: the file, and how to read it. -->
                <template v-if="step === 'file'">
                    <ImporterDropzone
                        :file="file"
                        :max-upload-size="settings.maxUploadSize"
                        :template-url="templateUrl"
                        :template-name="settings.template ?? null"
                        :disabled="loading"
                        @update:file="setFile"
                    />

                    <ImporterOptions
                        :options="parseOptions"
                        :can-apply="preview !== null"
                        :loading="loading"
                        @update="Object.assign(parseOptions, $event)"
                        @apply="applyParseOptions"
                    />
                </template>

                <!-- Step 2: the mapping. -->
                <ImporterMapping
                    v-else-if="step === 'mapping' && preview"
                    :columns="preview.columns"
                    :headers="headers"
                    :mapping="mapping"
                    :is-exact-match="preview.isExactMatch"
                    :unused-headers="unusedHeaders"
                    :missing-required="missingRequired"
                    @update="setMapping"
                    @reset="resetMapping"
                />

                <!-- Step 3: review, then start. -->
                <template v-else-if="step === 'review' && preview">
                    <dl
                        class="p-4 gap-3 sm:grid-cols-4 rounded-xl grid grid-cols-2 border border-border"
                    >
                        <div v-for="row in summaryRows" :key="row.label">
                            <dt class="text-xs text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd
                                class="text-sm font-medium truncate text-foreground"
                                :title="row.value"
                            >
                                {{ row.value }}
                            </dd>
                        </div>
                    </dl>

                    <ImporterPreview
                        v-if="settings.hasPreview"
                        :preview="preview"
                        :max-columns="settings.previewColumns"
                        :column-for-header="columnForHeader"
                    />

                    <p class="text-xs text-muted-foreground">
                        {{ t('kinetix.import_queued_help') }}
                    </p>
                </template>
            </div>
        </component>

        <!-- Actions: one primary per step, back always available. In a sheet
             the panel's own body is the scroller, so the row sticks to the
             bottom of it rather than sitting past a long mapping list. -->
        <div
            class="gap-2 sm:flex-row sm:justify-end flex shrink-0 flex-col-reverse"
            :class="
                surface === 'sheet'
                    ? 'bottom-0 pt-3 sticky border-t border-border bg-background'
                    : ''
            "
        >
            <KinetixButton
                v-if="step !== 'file'"
                variant="outline"
                :disabled="loading || starting"
                @click="back"
            >
                <template #icon>
                    <ArrowLeft class="size-4" />
                </template>
                {{ t('kinetix.import_back') }}
            </KinetixButton>

            <KinetixButton
                v-else-if="surface !== 'inline'"
                variant="outline"
                :disabled="loading || starting"
                @click="emit('cancel')"
            >
                {{ t('kinetix.cancel') }}
            </KinetixButton>

            <KinetixButton
                :loading="loading || starting"
                :disabled="!canContinue"
                @click="onContinue"
            >
                <template #icon>
                    <Upload v-if="step === 'review'" class="size-4" />
                    <ArrowRight v-else class="size-4" />
                </template>
                {{
                    step === 'review'
                        ? starting
                            ? t('kinetix.importing')
                            : t('kinetix.start_import')
                        : t('kinetix.import_continue')
                }}
            </KinetixButton>
        </div>
    </div>
</template>
