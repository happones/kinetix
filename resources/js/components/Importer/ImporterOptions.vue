<script setup lang="ts">
import { ChevronDown, RefreshCw } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixButton from '../KinetixButton.vue';
import KinetixCheckbox from '../KinetixCheckbox.vue';
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
 */
const props = withDefaults(
    defineProps<{
        options: {
            delimiter: string;
            enclosure: string;
            skipLines: number;
            hasHeader: boolean;
        };
        /** Show the "apply" button — only meaningful once a file is parsed. */
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

const isOpen = ref(props.expanded);
const contentId = `kinetix-import-options-${useId()}`;
const skipLinesId = `kinetix-import-skip-${useId()}`;
const delimiterId = `kinetix-import-delimiter-${useId()}`;
const enclosureId = `kinetix-import-enclosure-${useId()}`;

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
</script>

<template>
    <div class="rounded-xl border border-border">
        <button
            type="button"
            class="px-4 py-3 gap-3 rounded-xl flex w-full items-center text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            :aria-expanded="isOpen"
            :aria-controls="contentId"
            @click="isOpen = !isOpen"
        >
            <ChevronDown
                class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 motion-reduce:transition-none"
                :class="isOpen ? 'rotate-0' : '-rotate-90'"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1">
                <span class="text-sm font-medium block text-foreground">
                    {{ t('kinetix.import_parse_options') }}
                </span>
                <span class="text-xs block truncate text-muted-foreground">
                    {{ summary }}
                </span>
            </span>
        </button>

        <div
            v-show="isOpen"
            :id="contentId"
            class="px-4 pb-4 gap-4 flex flex-col"
        >
            <div class="gap-4 sm:grid-cols-2 lg:grid-cols-4 grid grid-cols-1">
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
                        @input="
                            emit('update', {
                                skipLines: Math.max(
                                    0,
                                    Number(
                                        ($event.target as HTMLInputElement)
                                            .value,
                                    ) || 0,
                                ),
                            })
                        "
                    />
                </div>

                <div class="gap-2 flex items-end">
                    <label
                        class="h-9 gap-2 text-sm flex cursor-pointer items-center text-foreground"
                    >
                        <KinetixCheckbox
                            :checked="options.hasHeader"
                            @change="emit('update', { hasHeader: $event })"
                        />
                        {{ t('kinetix.has_header') }}
                    </label>
                </div>
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
    </div>
</template>
