<script setup lang="ts">
import { FileText, Lock, Paperclip, SendHorizontal, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixInboxDraft } from '@/composables/useKinetixInbox';
import type { KinetixConversationMessage } from '@/types/kinetix';
import { cn } from '../primitives/cn';

/**
 * Writing in a thread: a reply to the contact, or an internal note the
 * contact never sees. Enter sends, Shift+Enter breaks the line; a file goes
 * with the text (shown before sending), and a quoted message rides on top.
 */
const props = withDefaults(
    defineProps<{
        /** The message being answered (cleared with the X). */
        replyTo?: KinetixConversationMessage | null;
        maxUploadKb?: number;
        accept?: string;
        disabled?: boolean;
    }>(),
    { replyTo: null, maxUploadKb: 16384, accept: '', disabled: false },
);

const emit = defineEmits<{
    (e: 'send', draft: KinetixInboxDraft): void;
    (e: 'cancel-reply'): void;
}>();

const { t } = useI18n();

const mode = ref<'reply' | 'note'>('reply');
const body = ref('');
const file = ref<File | null>(null);
const fileError = ref<string | null>(null);
const textarea = ref<HTMLTextAreaElement | null>(null);
const picker = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);

const note = computed(() => mode.value === 'note');
const canSend = computed(
    () =>
        !props.disabled &&
        (body.value.trim() !== '' || (!!file.value && !note.value)),
);

function resize(): void {
    const el = textarea.value;

    if (!el) {
        return;
    }

    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

watch(body, () => nextTick(resize));

watch(
    () => props.replyTo,
    (reply) => {
        if (reply) {
            textarea.value?.focus();
        }
    },
);

watch(file, (next, previous) => {
    if (preview.value && previous) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value =
        next && next.type.startsWith('image/')
            ? URL.createObjectURL(next)
            : null;
});

onBeforeUnmount(() => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }
});

function pick(event: Event): void {
    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0] ?? null;

    input.value = '';
    fileError.value = null;

    if (chosen && chosen.size > props.maxUploadKb * 1024) {
        fileError.value = t('kinetix.inbox_file_too_large', {
            size: Math.round(props.maxUploadKb / 1024),
        });

        return;
    }

    file.value = chosen;
}

function submit(): void {
    if (!canSend.value) {
        return;
    }

    emit('send', {
        body: body.value,
        file: note.value ? null : file.value,
        replyTo: props.replyTo,
        note: note.value,
    });

    body.value = '';
    file.value = null;
    emit('cancel-reply');
    nextTick(() => textarea.value?.focus());
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
}

/** Focus the field (after picking a conversation). */
defineExpose({ focus: () => textarea.value?.focus() });
</script>

<template>
    <div
        class="p-3 border-t border-border bg-background"
        data-test="inbox-composer"
    >
        <div
            class="gap-1 mb-2 flex"
            role="tablist"
            :aria-label="t('kinetix.inbox_compose_mode')"
        >
            <button
                v-for="option in ['reply', 'note'] as const"
                :key="option"
                type="button"
                role="tab"
                :aria-selected="mode === option"
                class="gap-1.5 px-2.5 py-1 text-xs font-medium inline-flex items-center rounded-md transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :class="
                    mode === option
                        ? option === 'note'
                            ? 'bg-warning/15 text-warning'
                            : 'bg-accent text-accent-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :data-test="`inbox-mode-${option}`"
                @click="mode = option"
            >
                <Lock v-if="option === 'note'" class="size-3" />
                {{
                    option === 'note'
                        ? t('kinetix.inbox_note')
                        : t('kinetix.inbox_reply')
                }}
            </button>
        </div>

        <div
            :class="
                cn(
                    'rounded-xl shadow-xs border transition-colors focus-within:ring-[3px] focus-within:ring-ring/50',
                    note
                        ? 'border-warning/40 bg-warning/5'
                        : 'border-input bg-background dark:bg-input/30',
                )
            "
        >
            <!-- The message being answered -->
            <div
                v-if="replyTo"
                class="gap-2 mx-2 mt-2 px-2 py-1.5 text-xs flex items-start rounded-md border-l-2 border-primary bg-muted"
                data-test="inbox-replying"
            >
                <p class="min-w-0 line-clamp-2 flex-1 text-muted-foreground">
                    <span class="font-medium text-foreground">{{
                        t('kinetix.inbox_replying_to')
                    }}</span>
                    {{
                        replyTo.body ?? t(`kinetix.inbox_type_${replyTo.type}`)
                    }}
                </p>
                <button
                    type="button"
                    class="size-6 inline-flex shrink-0 items-center justify-center rounded-md hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-label="t('kinetix.inbox_cancel_reply')"
                    @click="emit('cancel-reply')"
                >
                    <X class="size-3.5" />
                </button>
            </div>

            <!-- The file going with it -->
            <div
                v-if="file && !note"
                class="gap-2 mx-2 mt-2 p-1.5 pr-2 text-xs inline-flex max-w-[calc(100%-1rem)] items-center rounded-md bg-muted"
                data-test="inbox-attachment"
            >
                <img
                    v-if="preview"
                    :src="preview"
                    alt=""
                    class="size-10 rounded object-cover"
                />
                <FileText
                    v-else
                    class="size-6 shrink-0 text-muted-foreground"
                />
                <span class="min-w-0 truncate">{{ file.name }}</span>
                <button
                    type="button"
                    class="size-6 inline-flex shrink-0 items-center justify-center rounded-md hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :aria-label="t('kinetix.inbox_remove_file')"
                    @click="file = null"
                >
                    <X class="size-3.5" />
                </button>
            </div>

            <div class="gap-1 p-1.5 flex items-end">
                <button
                    v-if="!note"
                    type="button"
                    class="size-9 rounded-lg inline-flex shrink-0 items-center justify-center text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50"
                    :aria-label="t('kinetix.inbox_attach')"
                    :disabled="disabled"
                    data-test="inbox-attach"
                    @click="picker?.click()"
                >
                    <Paperclip class="size-4" />
                </button>
                <input
                    ref="picker"
                    type="file"
                    class="hidden"
                    :accept="accept || undefined"
                    data-test="inbox-file"
                    @change="pick"
                />

                <textarea
                    ref="textarea"
                    v-model="body"
                    rows="1"
                    :placeholder="
                        note
                            ? t('kinetix.inbox_note_placeholder')
                            : t('kinetix.inbox_placeholder')
                    "
                    :aria-label="
                        note
                            ? t('kinetix.inbox_note_placeholder')
                            : t('kinetix.inbox_placeholder')
                    "
                    :disabled="disabled"
                    class="min-h-9 px-2 py-2 text-base md:text-sm max-h-40 flex-1 resize-none bg-transparent outline-none placeholder:text-muted-foreground disabled:opacity-50"
                    data-test="inbox-input"
                    @keydown="onKeydown"
                />

                <button
                    type="button"
                    class="size-9 rounded-lg inline-flex shrink-0 items-center justify-center transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-40"
                    :class="
                        note
                            ? 'bg-warning text-warning-foreground hover:bg-warning/90'
                            : 'bg-primary text-primary-foreground hover:bg-primary/90'
                    "
                    :disabled="!canSend"
                    :aria-label="
                        note
                            ? t('kinetix.inbox_add_note')
                            : t('kinetix.inbox_send')
                    "
                    data-test="inbox-send"
                    @click="submit"
                >
                    <SendHorizontal class="size-4" />
                </button>
            </div>
        </div>

        <p
            v-if="fileError"
            class="mt-1.5 text-xs text-destructive"
            role="alert"
        >
            {{ fileError }}
        </p>
        <p
            v-else
            class="mt-1.5 sm:block hidden text-[11px] text-muted-foreground"
        >
            {{
                note
                    ? t('kinetix.inbox_note_hint')
                    : t('kinetix.inbox_enter_hint')
            }}
        </p>
    </div>
</template>
