<script setup lang="ts">
import { RefreshCw } from '@lucide/vue';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixButton from '../KinetixButton.vue';
import KinetixCheckbox from '../KinetixCheckbox.vue';
import KinetixCollapsible from '../KinetixCollapsible.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixSelect from '../KinetixSelect.vue';

/**
 * How the file should be read: delimiter, text enclosure, lines to omit, and
 * whether the first row is a header.
 *
 * Collapsed by default — the defaults are right for almost every file, and
 * putting four controls in front of the user before they have even chosen a
 * file is what made the dialog feel heavy. The summary line keeps the current
 * settings visible while collapsed, so nothing is hidden, only folded.
 *
 * The field grid measures ITS OWN width (a container query), not the viewport's.
 * Viewport breakpoints were wrong here: this form lives in a dialog of a fixed
 * width, so `lg:grid-cols-4` fired on any wide screen and squeezed four columns
 * into ~720px, wrapping the labels and breaking the row's alignment. The
 * container query collapses to two columns in the normal dialog and only opens
 * up in the full-screen one, where the room is real.
 */
const props = withDefaults(
    defineProps<{
        options: {
            delimiter: string;
            enclosure: string;
            skipLines: number;
            hasHeader: boolean;
        };
        /** Show the "re-read" button — only meaningful once a file is parsed. */
        canApply?: boolean;
        loading?: boolean;
        /** Start expanded (e.g. when a parse clearly went wrong). */
        expanded?: boolean;
    }>(),
    { canApply: false, loading: false, expanded: false },
);

/** Parse options are owned by the wizard; this component only asks for changes. */
export interface ImporterOptionsPatch {
    delimiter?: string;
    enclosure?: string;
    skipLines?: number;
    hasHeader?: boolean;
}

const emit = defineEmits<{
    (e: 'update', patch: ImporterOptionsPatch): void;
    (e: 'apply'): void;
}>();

const { t } = useI18n();

const uid = useId();
const skipLinesId = `kinetix-import-skip-${uid}`;
const delimiterId = `kinetix-import-delimiter-${uid}`;
const enclosureId = `kinetix-import-enclosure-${uid}`;
const hasHeaderId = `kinetix-import-header-${uid}`;

const delimiterOptions = computed<Record<string, string>>(() => ({
    ',': t('kinetix.import_delimiter_comma'),
    ';': t('kinetix.import_delimiter_semicolon'),
    '\t': t('kinetix.import_delimiter_tab'),
    '|': t('kinetix.import_delimiter_pipe'),
}));

const enclosureOptions = computed<Record<string, string>>(() => ({
    '"': t('kinetix.import_enclosure_double'),
    "'": t('kinetix.import_enclosure_single'),
    '': t('kinetix.import_enclosure_none'),
}));

/** What the collapsed header shows, so folded never means unknown. */
const summary = computed(() => {
    const parts = [
        delimiterOptions.value[props.options.delimiter] ??
            props.options.delimiter,
        props.options.hasHeader
            ? t('kinetix.import_has_header_short')
            : t('kinetix.import_no_header_short'),
    ];

    if (props.options.skipLines > 0) {
        parts.push(
            t('kinetix.import_skipped_lines_short', {
                count: props.options.skipLines,
            }),
        );
    }

    return parts.join(' · ');
});

const onSkipLines = (event: Event): void => {
    const raw = Number((event.target as HTMLInputElement).value);

    emit('update', { skipLines: Math.max(0, Number.isFinite(raw) ? raw : 0) });
};
</script>

<template>
    <KinetixCollapsible
        :default-open="expanded"
        :title="t('kinetix.import_parse_options')"
        :summary="summary"
    >
        <div class="kx-import-options gap-4 flex flex-col">
            <!-- The three value fields. Each cell is label-over-field, so every
                 field in a row shares one baseline whatever the column count. -->
            <div class="kx-import-options-grid gap-4 grid">
                <div class="gap-1.5 flex flex-col">
                    <KinetixLabel :for="delimiterId">
                        {{ t('kinetix.delimiter') }}
                    </KinetixLabel>
                    <KinetixSelect
                        :id="delimiterId"
                        :value="options.delimiter"
                        :options="delimiterOptions"
                        @update:value="emit('update', { delimiter: $event })"
                    />
                </div>

                <div class="gap-1.5 flex flex-col">
                    <KinetixLabel :for="enclosureId">
                        {{ t('kinetix.enclosure') }}
                    </KinetixLabel>
                    <KinetixSelect
                        :id="enclosureId"
                        :value="options.enclosure"
                        :options="enclosureOptions"
                        @update:value="emit('update', { enclosure: $event })"
                    />
                </div>

                <div class="gap-1.5 flex flex-col">
                    <KinetixLabel :for="skipLinesId">
                        {{ t('kinetix.omit_lines') }}
                    </KinetixLabel>
                    <input
                        :id="skipLinesId"
                        :value="options.skipLines"
                        type="number"
                        min="0"
                        inputmode="numeric"
                        :class="inputClass"
                        @input="onSkipLines"
                    />
                </div>
            </div>

            <!-- The boolean gets its own row rather than a fourth grid cell:
                 a checkbox has no label-above-field stack, so sitting it beside
                 three that do is what made the row look misaligned. -->
            <div class="gap-2 flex items-center">
                <KinetixCheckbox
                    :id="hasHeaderId"
                    :checked="options.hasHeader"
                    @change="emit('update', { hasHeader: $event })"
                />
                <KinetixLabel :for="hasHeaderId" class="cursor-pointer">
                    {{ t('kinetix.has_header') }}
                </KinetixLabel>
            </div>

            <p class="text-xs text-muted-foreground">
                {{ t('kinetix.import_parse_options_help') }}
            </p>

            <div v-if="canApply" class="flex justify-end">
                <KinetixButton
                    variant="outline"
                    size="sm"
                    :loading="loading"
                    @click="emit('apply')"
                >
                    <template #icon>
                        <RefreshCw class="size-4" />
                    </template>
                    {{ t('kinetix.import_reparse') }}
                </KinetixButton>
            </div>
        </div>
    </KinetixCollapsible>
</template>

<style scoped>
/*
 * The grid measures its own width, not the viewport's — the whole point, since
 * this form sits in a dialog whose width does not follow the screen. One column
 * when cramped, two when a label still fits, three — one row, no orphan cell —
 * once all three fields fit side by side.
 */
.kx-import-options {
    container-type: inline-size;
}

.kx-import-options-grid {
    grid-template-columns: minmax(0, 1fr);
}

@container (min-width: 22rem) {
    .kx-import-options-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* Three fields, three columns — one row, every label on one line, no orphan
   cell next to an empty one. Below this the grid steps down instead of
   wrapping a label. */
@container (min-width: 34rem) {
    .kx-import-options-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
</style>
