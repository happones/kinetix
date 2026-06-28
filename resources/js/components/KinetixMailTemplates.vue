<script setup lang="ts">
import { Plus, Send, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixMailTemplates } from '@/composables/useKinetixMailTemplates';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import type { KinetixMailTemplate } from '@/types';

/**
 * Manager for editable mail templates: a list, an editor (subject + Markdown/HTML
 * body with `{{ variables }}`), a live preview and a "send test" action.
 */
const { t } = useI18n();
const { templates, load, save, remove, preview, sendTest } =
    useKinetixMailTemplates();

const blank = (): KinetixMailTemplate => ({
    id: null,
    key: '',
    name: '',
    subject: '',
    body: '',
    format: 'markdown',
    variables: [],
    enabled: true,
});

const draft = ref<KinetixMailTemplate>(blank());
const previewHtml = ref('');
const previewSubject = ref('');
const testEmail = ref('');
const saving = ref(false);

onMounted(async () => {
    await load();

    // Open the first template on first load so the editor isn't empty.
    if (templates.value.length && draft.value.id == null) {
        edit(templates.value[0]);
    }
});

function edit(template: KinetixMailTemplate): void {
    draft.value = {
        ...template,
        variables: template.variables ? [...template.variables] : [],
    };
}

function create(): void {
    draft.value = blank();
}

const sampleData = computed<Record<string, string>>(() => {
    const data: Record<string, string> = {};

    for (const v of draft.value.variables) {
        if (v.key) {
            data[v.key] = v.sample ?? v.key;
        }
    }

    return data;
});

let timer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => [
        draft.value.subject,
        draft.value.body,
        draft.value.format,
        sampleData.value,
    ],
    () => {
        if (timer) {
            clearTimeout(timer);
        }

        timer = setTimeout(refreshPreview, 400);
    },
    { deep: true, immediate: true },
);

async function refreshPreview(): Promise<void> {
    const res = await preview({
        subject: draft.value.subject,
        body: draft.value.body,
        format: draft.value.format,
        data: sampleData.value,
    });
    previewSubject.value = res?.subject ?? '';
    previewHtml.value = res?.html ?? '';
}

async function persist(): Promise<void> {
    saving.value = true;

    try {
        const saved = await save(draft.value);

        if (saved) {
            draft.value = { ...saved, variables: saved.variables ?? [] };
        }
    } finally {
        saving.value = false;
    }
}

async function destroy(template: KinetixMailTemplate): Promise<void> {
    if (template.id != null) {
        await remove(template.id);

        if (draft.value.id === template.id) {
            create();
        }
    }
}

function addVariable(): void {
    draft.value.variables.push({ key: '', label: '', sample: '' });
}

function removeVariable(index: number): void {
    draft.value.variables.splice(index, 1);
}

async function test(): Promise<void> {
    if (draft.value.id != null && testEmail.value) {
        await sendTest(draft.value.id, testEmail.value);
    }
}
</script>

<template>
    <div class="gap-4 md:grid-cols-[16rem_1fr] grid">
        <!-- Template list -->
        <div class="space-y-1">
            <button
                type="button"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                class="w-full justify-start"
                @click="create"
            >
                <Plus class="size-4" />
                {{ t('kinetix.mail_new') }}
            </button>
            <button
                v-for="tpl in templates"
                :key="tpl.id ?? tpl.key"
                type="button"
                class="px-3 py-2 text-sm flex w-full items-center justify-between rounded-md text-left transition-colors"
                :class="
                    draft.id === tpl.id
                        ? 'bg-accent text-accent-foreground'
                        : 'hover:bg-accent/50'
                "
                @click="edit(tpl)"
            >
                <span class="min-w-0">
                    <span class="font-medium block truncate">{{
                        tpl.name
                    }}</span>
                    <span
                        class="text-xs block truncate text-muted-foreground"
                        >{{ tpl.key }}</span
                    >
                </span>
                <span
                    v-if="!tpl.enabled"
                    class="text-xs shrink-0 text-muted-foreground"
                    >{{ t('kinetix.mail_disabled') }}</span
                >
            </button>
        </div>

        <!-- Editor + preview -->
        <div class="space-y-4 rounded-xl p-4 border border-border bg-card">
            <div class="gap-3 sm:grid-cols-2 grid">
                <label class="text-sm block">
                    <span class="mb-1 font-medium block text-foreground">{{
                        t('kinetix.mail_name')
                    }}</span>
                    <input v-model="draft.name" :class="inputClass" />
                </label>
                <label class="text-sm block">
                    <span class="mb-1 font-medium block text-foreground">{{
                        t('kinetix.mail_key')
                    }}</span>
                    <input v-model="draft.key" :class="inputClass" />
                </label>
            </div>

            <label class="text-sm block">
                <span class="mb-1 font-medium block text-foreground">{{
                    t('kinetix.mail_subject')
                }}</span>
                <input v-model="draft.subject" :class="inputClass" />
            </label>

            <div class="gap-2 flex items-center">
                <span class="text-sm font-medium text-foreground">{{
                    t('kinetix.mail_format')
                }}</span>
                <div
                    class="rounded-lg p-1 inline-flex border border-border bg-muted/40"
                >
                    <button
                        v-for="fmt in ['markdown', 'html']"
                        :key="fmt"
                        type="button"
                        class="px-3 py-1 text-xs font-medium rounded-md transition-colors"
                        :class="
                            draft.format === fmt
                                ? 'shadow-sm bg-background text-foreground'
                                : 'text-muted-foreground'
                        "
                        @click="draft.format = fmt as 'markdown' | 'html'"
                    >
                        {{ fmt }}
                    </button>
                </div>
            </div>

            <label class="text-sm block">
                <span class="mb-1 font-medium block text-foreground">{{
                    t('kinetix.mail_body')
                }}</span>
                <textarea
                    v-model="draft.body"
                    rows="8"
                    :class="[inputClass, 'font-mono text-xs']"
                    :placeholder="t('kinetix.mail_body_hint')"
                />
            </label>

            <!-- Variables -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-foreground">{{
                        t('kinetix.mail_variables')
                    }}</span>
                    <button
                        type="button"
                        :class="
                            buttonVariants({ variant: 'ghost', size: 'sm' })
                        "
                        @click="addVariable"
                    >
                        <Plus class="size-3.5" />
                    </button>
                </div>
                <div
                    v-for="(variable, idx) in draft.variables"
                    :key="idx"
                    class="gap-2 flex items-center"
                >
                    <input
                        v-model="variable.key"
                        :class="[inputClass, 'text-xs']"
                        :placeholder="t('kinetix.mail_var_key')"
                    />
                    <input
                        v-model="variable.sample"
                        :class="[inputClass, 'text-xs']"
                        :placeholder="t('kinetix.mail_var_sample')"
                    />
                    <button
                        type="button"
                        class="shrink-0 text-muted-foreground hover:text-destructive"
                        @click="removeVariable(idx)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>

            <!-- Preview -->
            <div class="rounded-lg p-3 border border-border bg-background">
                <div class="mb-2 text-xs font-medium text-muted-foreground">
                    {{ t('kinetix.mail_preview') }} —
                    <span class="text-foreground">{{ previewSubject }}</span>
                </div>
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div
                    class="prose prose-sm dark:prose-invert max-w-none"
                    v-html="previewHtml"
                />
            </div>

            <!-- Actions -->
            <div class="gap-2 flex flex-wrap items-center justify-between">
                <div class="gap-2 flex items-center">
                    <input
                        v-model="testEmail"
                        type="email"
                        :class="[inputClass, 'w-48']"
                        :placeholder="t('kinetix.mail_test_email')"
                    />
                    <button
                        type="button"
                        :class="
                            buttonVariants({ variant: 'outline', size: 'sm' })
                        "
                        :disabled="draft.id == null || !testEmail"
                        @click="test"
                    >
                        <Send class="size-4" />
                        {{ t('kinetix.mail_send_test') }}
                    </button>
                </div>
                <div class="gap-2 flex items-center">
                    <button
                        v-if="draft.id != null"
                        type="button"
                        :class="
                            buttonVariants({ variant: 'ghost', size: 'sm' })
                        "
                        @click="destroy(draft)"
                    >
                        <Trash2 class="size-4" />
                        {{ t('kinetix.delete') }}
                    </button>
                    <button
                        type="button"
                        :class="buttonVariants({ size: 'sm' })"
                        :disabled="saving"
                        @click="persist"
                    >
                        {{ t('kinetix.save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
