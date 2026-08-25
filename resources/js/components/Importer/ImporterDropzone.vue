<script setup lang="ts">
import { Download, FileSpreadsheet, UploadCloud, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import KinetixButton from '../KinetixButton.vue';

/**
 * Step 1's file picker: a drop target that is also a real `<input type="file">`
 * (drag-and-drop is never the only way in — see the keyboard rule), plus the
 * downloadable-template link when the importer offers one.
 */
const props = withDefaults(
    defineProps<{
        file: File | null;
        /** Upload ceiling in kilobytes, shown in the hint. */
        maxUploadSize: number;
        templateUrl?: string | null;
        templateName?: string | null;
        disabled?: boolean;
    }>(),
    { templateUrl: null, templateName: null, disabled: false },
);

const emit = defineEmits<{ (e: 'update:file', file: File | null): void }>();

const { t } = useI18n();

const inputEl = ref<HTMLInputElement | null>(null);
const isDragging = ref(false);

const acceptedTypes = '.csv,.txt,.tsv,.xls,.xlsx';

/** Kilobytes → the largest unit that stays readable. */
const maxSizeLabel = computed(() => {
    const kb = props.maxUploadSize;

    if (kb >= 1048576) {
        return `${Math.round(kb / 1048576)} GB`;
    }

    if (kb >= 1024) {
        return `${Math.round(kb / 1024)} MB`;
    }

    return `${kb} KB`;
});

const fileSizeLabel = computed(() => {
    if (!props.file) {
        return null;
    }

    const mb = props.file.size / 1048576;

    return mb >= 1
        ? `${mb.toFixed(1)} MB`
        : `${Math.max(1, Math.round(props.file.size / 1024))} KB`;
});

const onInput = (event: Event): void => {
    const target = event.target as HTMLInputElement;
    emit('update:file', target.files?.[0] ?? null);
};

const onDrop = (event: DragEvent): void => {
    isDragging.value = false;

    if (props.disabled) {
        return;
    }

    const dropped = event.dataTransfer?.files?.[0] ?? null;

    if (dropped) {
        emit('update:file', dropped);
    }
};

const clear = (): void => {
    emit('update:file', null);

    if (inputEl.value) {
        inputEl.value.value = '';
    }
};
</script>

<template>
    <div class="gap-3 flex flex-col">
        <!-- A label, so clicking or activating anywhere in the zone opens the
             file picker — the drop behaviour is an enhancement on top. -->
        <label
            class="p-6 gap-2 rounded-xl flex cursor-pointer flex-col items-center border border-dashed text-center transition-colors focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
            :class="[
                isDragging
                    ? 'border-primary bg-primary/5'
                    : 'border-input hover:bg-accent/40',
                disabled ? 'pointer-events-none opacity-50' : '',
            ]"
            @dragover.prevent="isDragging = true"
            @dragenter.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
        >
            <UploadCloud
                class="size-8 text-muted-foreground"
                aria-hidden="true"
            />
            <span class="text-sm font-medium text-foreground">
                {{ t('kinetix.import_dropzone_hint') }}
            </span>
            <span class="text-xs text-muted-foreground">
                {{
                    t('kinetix.import_dropzone_formats', { size: maxSizeLabel })
                }}
            </span>
            <input
                ref="inputEl"
                type="file"
                class="sr-only"
                :accept="acceptedTypes"
                :disabled="disabled"
                @change="onInput"
            />
        </label>

        <!-- The chosen file, with its own remove control (never only the
             browser's native "no file chosen" text). -->
        <div
            v-if="file"
            class="px-3 py-2 gap-3 flex items-center rounded-md border border-border bg-muted/40"
        >
            <FileSpreadsheet
                class="size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <span class="text-sm min-w-0 flex-1 truncate text-foreground">
                {{ file.name }}
            </span>
            <span class="text-xs shrink-0 text-muted-foreground tabular-nums">
                {{ fileSizeLabel }}
            </span>
            <KinetixButton
                variant="ghost"
                size="icon-sm"
                :aria-label="t('kinetix.import_remove_file')"
                @click.prevent="clear"
            >
                <template #icon>
                    <X class="size-4" />
                </template>
            </KinetixButton>
        </div>

        <a
            v-if="templateUrl"
            :href="templateUrl"
            :download="templateName ?? undefined"
            class="gap-1.5 text-xs font-medium inline-flex items-center self-start text-primary hover:underline"
        >
            <Download class="size-3.5" aria-hidden="true" />
            {{ t('kinetix.download_template') }}
        </a>
    </div>
</template>
