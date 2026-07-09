<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Download, RotateCcw } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import type {
    KinetixPdfField,
    KinetixPdfTemplateData,
    KinetixSharedProps,
} from '@/types';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixLabel from './KinetixLabel.vue';
import KinetixSelect from './KinetixSelect.vue';
import { cn } from './primitives/cn';

/**
 * Configurator + live preview for a registered PdfTemplate: renders one
 * control per declared field (color swatches, text, select, toggle, number),
 * previews the document in an iframe as you type (unsaved settings travel as
 * query overrides), and offers save / reset-to-defaults / PDF download.
 *
 *     <KinetixPdfTemplate template="quote" />
 */
const props = withDefaults(
    defineProps<{
        /** The PdfTemplate registry key. */
        template: string;
        /** Preview iframe height. */
        previewHeight?: number | string;
    }>(),
    { previewHeight: 860 },
);

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();
const base = computed(
    () => `/${kinetixRoutePrefix(page)}/pdf-templates/${props.template}`,
);

const descriptor = ref<KinetixPdfTemplateData | null>(null);
const settings = reactive<Record<string, unknown>>({});
const saving = ref(false);
const previewUrl = ref('');

const dirty = computed(() => {
    if (!descriptor.value) {
        return false;
    }

    return Object.keys(settings).some(
        (key) => settings[key] !== descriptor.value!.settings[key],
    );
});

function settingsQuery(): string {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(settings)) {
        if (value === null || value === undefined) {
            continue;
        }

        query.set(
            key,
            typeof value === 'boolean' ? (value ? '1' : '0') : String(value),
        );
    }

    return query.toString();
}

const downloadUrl = computed(() => `${base.value}/download?${settingsQuery()}`);

let debounce: ReturnType<typeof setTimeout> | undefined;

function refreshPreview(immediate = false): void {
    clearTimeout(debounce);
    debounce = setTimeout(
        () => {
            previewUrl.value = `${base.value}/preview?${settingsQuery()}`;
        },
        immediate ? 0 : 450,
    );
}

function set(name: string, value: unknown): void {
    settings[name] = value;
    refreshPreview();
}

async function load(): Promise<void> {
    const data = await kinetixFetch<KinetixPdfTemplateData>(base.value);

    if (!data) {
        return;
    }

    descriptor.value = data;
    Object.assign(settings, data.settings);
    refreshPreview(true);
}

async function save(): Promise<void> {
    saving.value = true;

    try {
        const data = await kinetixFetch<KinetixPdfTemplateData>(base.value, {
            method: 'PATCH',
            body: { ...settings },
        });

        if (data) {
            descriptor.value = data;
        }

        toast.success(t('kinetix.saved'));
    } catch {
        toast.error(t('kinetix.save_failed'));
    } finally {
        saving.value = false;
    }
}

function resetDefaults(): void {
    if (!descriptor.value) {
        return;
    }

    Object.assign(settings, descriptor.value.defaults);
    refreshPreview(true);
}

const asString = (value: unknown): string =>
    value === null || value === undefined ? '' : String(value);

const fieldValue = (field: KinetixPdfField): unknown => settings[field.name];

onMounted(load);
onBeforeUnmount(() => clearTimeout(debounce));
</script>

<template>
    <div v-if="descriptor" class="gap-6 xl:grid-cols-5 grid">
        <!-- Controls -->
        <div class="gap-5 xl:col-span-2 flex flex-col">
            <template v-for="field in descriptor.fields" :key="field.name">
                <!-- Color -->
                <div v-if="field.type === 'color'" class="gap-2 flex flex-col">
                    <KinetixLabel>{{ field.label }}</KinetixLabel>
                    <div class="gap-2 flex flex-wrap items-center">
                        <button
                            v-for="color in field.palette"
                            :key="color"
                            type="button"
                            class="size-7 rounded-full ring-2 ring-offset-2 ring-offset-background transition-transform hover:scale-110"
                            :class="
                                fieldValue(field) === color
                                    ? 'ring-primary'
                                    : 'ring-transparent'
                            "
                            :style="{ backgroundColor: color }"
                            @click="set(field.name, color)"
                        />
                        <span
                            v-if="field.palette.length === 0"
                            class="size-7 shrink-0 rounded-full border border-border"
                            :style="{
                                backgroundColor: asString(fieldValue(field)),
                            }"
                        />
                        <input
                            :value="asString(fieldValue(field))"
                            :class="
                                cn(inputClass, 'h-8 w-24 font-mono text-xs')
                            "
                            maxlength="7"
                            @input="
                                set(
                                    field.name,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </div>
                </div>

                <!-- Select -->
                <div
                    v-else-if="field.type === 'select'"
                    class="gap-2 flex flex-col"
                >
                    <KinetixLabel :for="`kx-pdf-${field.name}`">{{
                        field.label
                    }}</KinetixLabel>
                    <KinetixSelect
                        :id="`kx-pdf-${field.name}`"
                        :value="asString(fieldValue(field))"
                        :options="field.options"
                        @update:value="set(field.name, $event)"
                    />
                </div>

                <!-- Toggle -->
                <label
                    v-else-if="field.type === 'toggle'"
                    class="gap-3 flex items-center justify-between"
                >
                    <span class="min-w-0">
                        <span class="text-sm font-medium leading-none">{{
                            field.label
                        }}</span>
                        <span
                            v-if="field.help"
                            class="text-xs block text-muted-foreground"
                        >
                            {{ field.help }}
                        </span>
                    </span>
                    <KinetixCheckbox
                        :checked="fieldValue(field) === true"
                        @change="set(field.name, $event)"
                    />
                </label>

                <!-- Number -->
                <div
                    v-else-if="field.type === 'number'"
                    class="gap-2 flex flex-col"
                >
                    <KinetixLabel :for="`kx-pdf-${field.name}`">{{
                        field.label
                    }}</KinetixLabel>
                    <input
                        :id="`kx-pdf-${field.name}`"
                        type="number"
                        :value="asString(fieldValue(field))"
                        :class="inputClass"
                        @input="
                            set(
                                field.name,
                                Number(
                                    ($event.target as HTMLInputElement).value,
                                ),
                            )
                        "
                    />
                </div>

                <!-- Text (default) -->
                <div v-else class="gap-2 flex flex-col">
                    <KinetixLabel :for="`kx-pdf-${field.name}`">{{
                        field.label
                    }}</KinetixLabel>
                    <input
                        :id="`kx-pdf-${field.name}`"
                        type="text"
                        :value="asString(fieldValue(field))"
                        :maxlength="field.maxLength ?? undefined"
                        :class="inputClass"
                        :placeholder="field.help ?? ''"
                        @input="
                            set(
                                field.name,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </div>
            </template>

            <div class="gap-2 flex items-center">
                <button
                    type="button"
                    :class="buttonVariants()"
                    :disabled="saving || !dirty"
                    @click="save"
                >
                    {{ t('kinetix.save') }}
                </button>
                <button
                    type="button"
                    :class="cn(buttonVariants({ variant: 'outline' }), 'gap-2')"
                    @click="resetDefaults"
                >
                    <RotateCcw class="size-4" />
                    {{ t('kinetix.pdf_reset') }}
                </button>
                <a
                    :href="downloadUrl"
                    :class="cn(buttonVariants({ variant: 'outline' }), 'gap-2')"
                >
                    <Download class="size-4" />
                    PDF
                </a>
            </div>
        </div>

        <!-- Live preview -->
        <div class="xl:col-span-3">
            <p class="mb-2 text-xs text-muted-foreground">
                {{ t('kinetix.pdf_preview_hint') }}
            </p>
            <div
                class="rounded-xl shadow-sm bg-white overflow-hidden border border-border"
            >
                <iframe
                    v-if="previewUrl"
                    :src="previewUrl"
                    class="w-full"
                    :style="{ height: `${previewHeight}px` }"
                    :title="descriptor.label"
                />
            </div>
        </div>
    </div>
</template>
