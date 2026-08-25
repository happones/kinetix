import { computed, reactive, ref } from 'vue';
import type { Ref } from 'vue';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import type {
    KinetixImportPreview,
    KinetixImportSettings,
} from '@/types/kinetix';

/**
 * The import dialog's state machine, kept out of the components so the wizard's
 * steps stay presentational.
 *
 * Three steps: pick the file (and how to parse it) → confirm the mapping →
 * review and start. Splitting them is what keeps a wide file usable: a
 * twenty-four column file no longer stacks twenty-four selects, a preview table
 * and the actions into one dialog that outgrows the viewport.
 *
 * Nothing here reads more of the file than it must — the server samples only
 * `settings.previewRows` rows, so the cost of the whole dialog is independent
 * of whether the file has a hundred rows or a million.
 */

export type KinetixImporterStep = 'file' | 'mapping' | 'review';

export const KINETIX_IMPORTER_STEPS: KinetixImporterStep[] = [
    'file',
    'mapping',
    'review',
];

/**
 * Fallback settings used before the first upload answers with the importer's
 * real ones (and for a manually mounted `<KinetixImporter>` given none). They
 * mirror the `kinetix.imports` config defaults.
 */
export const KINETIX_IMPORT_SETTINGS_DEFAULTS: KinetixImportSettings = {
    hasPreview: true,
    previewRows: 10,
    previewColumns: 8,
    layout: 'auto',
    fullscreenThreshold: 12,
    maxUploadSize: 102400,
    // `template` is left unset: the real filename arrives with the importer's
    // own settings (the `open-importer` event, or the first preview).
};

export interface UseKinetixImporterOptions {
    importer: () => string;
    prefix: () => string;
    settings: () => KinetixImportSettings;
    /** Message for a request that failed without one of its own. */
    fallbackError: () => string;
    /** Announce async outcomes to assistive tech. */
    announce?: (message: string, assertive?: boolean) => void;
}

export function useKinetixImporter(options: UseKinetixImporterOptions) {
    const file = ref<File | null>(null);
    const preview = ref<KinetixImportPreview | null>(null);
    const mapping = reactive<Record<string, number | null>>({});
    const loading = ref(false);
    const starting = ref(false);
    const errorMessage = ref<string | null>(null);
    const step: Ref<KinetixImporterStep> = ref('file');

    const parseOptions = reactive({
        delimiter: ',',
        enclosure: '"',
        skipLines: 0,
        hasHeader: true,
    });

    /**
     * The importer's own settings once a preview exists, the caller-supplied
     * ones until then.
     */
    const settings = computed<KinetixImportSettings>(
        () => preview.value?.settings ?? options.settings(),
    );

    const templateUrl = computed(() =>
        settings.value.template
            ? `/${options.prefix()}/imports/template?importer=${encodeURIComponent(options.importer())}`
            : null,
    );

    const headers = computed<string[]>(() => preview.value?.headers ?? []);
    const columnCount = computed(() => headers.value.length);

    const requiredColumns = computed(() =>
        (preview.value?.columns ?? []).filter((column) => column.isRequired),
    );

    const mappedCount = computed(
        () =>
            Object.values(mapping).filter(
                (index) => index !== null && index !== undefined,
            ).length,
    );

    const missingRequired = computed(() =>
        requiredColumns.value.filter(
            (column) =>
                mapping[column.name] === null ||
                mapping[column.name] === undefined,
        ),
    );

    const canStart = computed(
        () => preview.value !== null && missingRequired.value.length === 0,
    );

    /** Source columns no target column claimed — surfaced so data isn't silently dropped. */
    const unusedHeaders = computed(() => {
        const used = new Set(Object.values(mapping));

        return headers.value.filter((_, index) => !used.has(index));
    });

    const applyPreview = (data: KinetixImportPreview): void => {
        preview.value = data;
        parseOptions.delimiter = data.options.delimiter;
        parseOptions.enclosure = data.options.enclosure;
        parseOptions.skipLines = data.options.skipLines;
        parseOptions.hasHeader = data.options.hasHeader;

        // Reset the mapping to the server-computed, collision-free suggestions.
        Object.keys(mapping).forEach((key) => delete mapping[key]);

        for (const column of data.columns) {
            mapping[column.name] = data.autoMapping[column.name] ?? null;
        }
    };

    const resetMapping = (): void => {
        if (!preview.value) {
            return;
        }

        for (const column of preview.value.columns) {
            mapping[column.name] =
                preview.value.autoMapping[column.name] ?? null;
        }
    };

    // Indices already claimed by another target column — used to prevent collisions.
    const usedIndexes = (exceptColumn: string): Set<number> => {
        const used = new Set<number>();

        for (const [name, index] of Object.entries(mapping)) {
            if (
                name !== exceptColumn &&
                index !== null &&
                index !== undefined
            ) {
                used.add(index);
            }
        }

        return used;
    };

    const setMapping = (column: string, value: string): void => {
        mapping[column] = value === '' ? null : Number(value);
    };

    /** Reverse lookup: which target column label (if any) claims a header. */
    const columnForHeader = (index: number): string | null => {
        for (const column of preview.value?.columns ?? []) {
            if (mapping[column.name] === index) {
                return column.label;
            }
        }

        return null;
    };

    const setFile = (next: File | null): void => {
        file.value = next;
        errorMessage.value = null;

        // A new file invalidates the parsed preview built from the old one.
        if (preview.value !== null) {
            preview.value = null;
            step.value = 'file';
        }
    };

    const requestPreview = async (
        endpoint: 'upload' | 'preview',
    ): Promise<KinetixImportPreview | null> => {
        loading.value = true;
        errorMessage.value = null;

        try {
            let body: FormData | Record<string, unknown>;

            if (endpoint === 'upload') {
                const form = new FormData();
                form.append('file', file.value as File);
                form.append('importer', options.importer());
                form.append('delimiter', parseOptions.delimiter);
                form.append('enclosure', parseOptions.enclosure);
                form.append('skipLines', String(parseOptions.skipLines));
                form.append('hasHeader', parseOptions.hasHeader ? '1' : '0');
                body = form;
            } else {
                body = {
                    importer: options.importer(),
                    fileToken: preview.value?.fileToken,
                    ...parseOptions,
                };
            }

            const data = (await kinetixFetch<KinetixImportPreview>(
                `/${options.prefix()}/imports/${endpoint}`,
                { method: 'POST', body },
            )) as KinetixImportPreview;

            applyPreview(data);

            return data;
        } catch (error: unknown) {
            errorMessage.value =
                (error as { message?: string })?.message ??
                options.fallbackError();

            options.announce?.(errorMessage.value, true);

            return null;
        } finally {
            loading.value = false;
        }
    };

    /**
     * Parse the chosen file and move on. A file whose headers line up
     * one-for-one with the importer (anything produced from the downloadable
     * template does) skips the mapping step — there is nothing left to decide.
     */
    const upload = async (): Promise<void> => {
        if (!file.value) {
            return;
        }

        const data = await requestPreview('upload');

        if (data === null) {
            return;
        }

        step.value = data.isExactMatch ? 'review' : 'mapping';
    };

    /** Re-parse the already-uploaded file with changed parse options. */
    const applyParseOptions = async (): Promise<void> => {
        if (!preview.value) {
            return;
        }

        await requestPreview('preview');
    };

    const goTo = (next: KinetixImporterStep): void => {
        step.value = next;
    };

    const back = (): void => {
        step.value = step.value === 'review' ? 'mapping' : 'file';
    };

    const reset = (): void => {
        file.value = null;
        preview.value = null;
        errorMessage.value = null;
        step.value = 'file';
        Object.keys(mapping).forEach((key) => delete mapping[key]);
    };

    /**
     * Queue the import. Resolves with the server's message so the caller can
     * toast it and close the dialog.
     */
    const startImport = async (): Promise<string | null> => {
        if (!preview.value || !canStart.value) {
            return null;
        }

        starting.value = true;
        errorMessage.value = null;

        try {
            const response = await kinetixFetch<{
                status: string;
                message?: string;
            }>(`/${options.prefix()}/imports/start`, {
                method: 'POST',
                body: {
                    importer: options.importer(),
                    fileToken: preview.value.fileToken,
                    mapping,
                    ...parseOptions,
                },
            });

            reset();

            return response?.message ?? null;
        } catch (error: unknown) {
            errorMessage.value =
                (error as { message?: string })?.message ??
                options.fallbackError();

            options.announce?.(errorMessage.value, true);

            return null;
        } finally {
            starting.value = false;
        }
    };

    return {
        // state
        file,
        preview,
        mapping,
        parseOptions,
        loading,
        starting,
        errorMessage,
        step,
        // derived
        settings,
        templateUrl,
        headers,
        columnCount,
        mappedCount,
        missingRequired,
        unusedHeaders,
        canStart,
        // actions
        setFile,
        setMapping,
        usedIndexes,
        columnForHeader,
        resetMapping,
        upload,
        applyParseOptions,
        startImport,
        goTo,
        back,
        reset,
    };
}
