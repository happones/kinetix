import type { KinetixImportSettings } from '@/types/kinetix';

/**
 * Fallback settings used before the first upload answers with the importer's
 * real ones (and for a manually mounted `<KinetixImporter>` given none). They
 * mirror the `kinetix.imports` config defaults.
 *
 * Kept in their own module (re-exported from `useKinetixImporter`) so the
 * layout's import dialog can read them without the wizard's state machine.
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
