<script setup lang="ts">
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixQuickReply } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

/**
 * The team's quick replies: add, edit and delete them. A shortcut is one
 * word, typed after `/` in the composer; the text can carry the contact's
 * name, number, the agent's name and the line's name.
 */
const props = defineProps<{
    open: boolean;
    replies: KinetixQuickReply[];
    save: (
        reply: Partial<KinetixQuickReply> & { shortcut: string; body: string },
    ) => Promise<unknown>;
    remove: (reply: KinetixQuickReply) => Promise<unknown>;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const { t } = useI18n();

const editing = ref<{
    id?: number | string;
    shortcut: string;
    body: string;
} | null>(null);
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const search = ref('');
const bodyField = ref<HTMLTextAreaElement | null>(null);

const VARIABLES = ['contact.name', 'contact.key', 'agent.name', 'line.name'];

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    return q
        ? props.replies.filter(
              (r) => r.shortcut.includes(q) || r.body.toLowerCase().includes(q),
          )
        : props.replies;
});

watch(
    () => props.open,
    (open) => {
        if (open) {
            editing.value = props.replies.length
                ? null
                : { shortcut: '', body: '' };
            errors.value = {};
            search.value = '';
        }
    },
);

function edit(reply?: KinetixQuickReply): void {
    editing.value = reply ? { ...reply } : { shortcut: '', body: '' };
    errors.value = {};
}

function insertVariable(variable: string): void {
    const el = bodyField.value;

    if (!editing.value || !el) {
        return;
    }

    const token = `{{${variable}}}`;
    const start = el.selectionStart ?? editing.value.body.length;
    const end = el.selectionEnd ?? start;

    editing.value.body =
        editing.value.body.slice(0, start) +
        token +
        editing.value.body.slice(end);
    requestAnimationFrame(() => {
        el.focus();
        el.setSelectionRange(start + token.length, start + token.length);
    });
}

async function submit(): Promise<void> {
    if (!editing.value) {
        return;
    }

    processing.value = true;
    errors.value = {};

    try {
        await props.save(editing.value);
        toast.success(t('kinetix.saved'));
        editing.value = null;
    } catch (e) {
        const bag = (e as { errors?: Record<string, string[]> }).errors;

        errors.value = bag
            ? Object.fromEntries(
                  Object.entries(bag).map(([key, value]) => [key, value[0]]),
              )
            : { body: e instanceof Error ? e.message : String(e) };
    } finally {
        processing.value = false;
    }
}

async function destroy(reply: KinetixQuickReply): Promise<void> {
    try {
        await props.remove(reply);
        toast.success(t('kinetix.deleted'));
    } catch (e) {
        toast.error(e instanceof Error ? e.message : String(e));
    }
}
</script>

<template>
    <KinetixModal
        :open="open"
        :title="t('kinetix.inbox_quick_replies')"
        :description="t('kinetix.inbox_quick_replies_description')"
        :processing="processing"
        max-width="sm:max-w-xl"
        scroll-body
        @update:open="emit('update:open', $event)"
    >
        <!-- Adding or editing one -->
        <form
            v-if="editing"
            id="kinetix-quick-reply"
            class="space-y-4"
            data-test="quick-reply-form"
            @submit.prevent="submit"
        >
            <div class="space-y-2">
                <KinetixLabel for="kinetix-quick-reply-shortcut">{{
                    t('kinetix.inbox_quick_reply_shortcut')
                }}</KinetixLabel>
                <div class="relative">
                    <span
                        class="left-3 font-mono text-sm pointer-events-none absolute top-1/2 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                        >/</span
                    >
                    <input
                        id="kinetix-quick-reply-shortcut"
                        v-model="editing.shortcut"
                        required
                        maxlength="40"
                        autocomplete="off"
                        :class="[inputClass, 'pl-6 font-mono']"
                        placeholder="precios"
                        :aria-invalid="!!errors.shortcut"
                        aria-describedby="kinetix-quick-reply-shortcut-help"
                        data-test="quick-reply-shortcut"
                    />
                </div>
                <p
                    v-if="errors.shortcut"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.shortcut }}
                </p>
                <p
                    id="kinetix-quick-reply-shortcut-help"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('kinetix.inbox_quick_reply_shortcut_help') }}
                </p>
            </div>
            <div class="space-y-2">
                <KinetixLabel for="kinetix-quick-reply-body">{{
                    t('kinetix.inbox_quick_reply_body')
                }}</KinetixLabel>
                <textarea
                    id="kinetix-quick-reply-body"
                    ref="bodyField"
                    v-model="editing.body"
                    required
                    rows="5"
                    maxlength="4096"
                    :class="[inputClass, 'py-2 h-auto resize-y']"
                    :aria-invalid="!!errors.body"
                    data-test="quick-reply-body"
                />
                <p
                    v-if="errors.body"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.body }}
                </p>
                <div class="gap-1 flex flex-wrap">
                    <button
                        v-for="variable in VARIABLES"
                        :key="variable"
                        type="button"
                        class="px-2 py-0.5 font-mono rounded-md border border-border bg-muted/50 text-[11px] text-foreground transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        @mousedown.prevent
                        @click="insertVariable(variable)"
                    >
                        {{ variable }}
                    </button>
                </div>
            </div>
        </form>

        <!-- The list -->
        <div v-else class="space-y-3">
            <input
                v-if="replies.length > 5"
                v-model="search"
                type="search"
                :class="inputClass"
                :placeholder="t('kinetix.inbox_quick_replies_search')"
                :aria-label="t('kinetix.inbox_quick_replies_search')"
            />
            <ul
                class="rounded-lg divide-y divide-border border border-border"
                data-test="quick-reply-list"
            >
                <li
                    v-for="reply in filtered"
                    :key="reply.id"
                    class="gap-3 p-3 flex items-start"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            class="font-mono text-sm font-medium text-foreground"
                        >
                            /{{ reply.shortcut }}
                        </p>
                        <p
                            class="text-sm line-clamp-2 whitespace-pre-line text-muted-foreground"
                        >
                            {{ reply.body }}
                        </p>
                    </div>
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :aria-label="t('kinetix.edit')"
                        @click="edit(reply)"
                    >
                        <Pencil class="size-4" />
                    </button>
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :aria-label="t('kinetix.delete')"
                        @click="destroy(reply)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </li>
            </ul>
        </div>

        <template #footer>
            <template v-if="editing">
                <KinetixButton
                    variant="outline"
                    :disabled="processing"
                    @click="
                        replies.length
                            ? (editing = null)
                            : emit('update:open', false)
                    "
                >
                    {{ t('kinetix.cancel') }}
                </KinetixButton>
                <KinetixButton
                    type="submit"
                    form="kinetix-quick-reply"
                    :loading="processing"
                    data-test="quick-reply-save"
                >
                    {{ t('kinetix.save') }}
                </KinetixButton>
            </template>
            <KinetixButton v-else data-test="quick-reply-new" @click="edit()">
                <Plus class="size-4" />
                {{ t('kinetix.inbox_quick_reply_new') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
