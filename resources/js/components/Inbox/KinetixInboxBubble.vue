<script setup lang="ts">
import {
    AlertCircle,
    Bot,
    Check,
    CheckCheck,
    Clock,
    CornerUpLeft,
    Download,
    FileText,
    Lock,
    RotateCw,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixConversationMessage } from '@/types/kinetix';

/**
 * One message in a thread: the contact's on the left, the team's on the right
 * (with its delivery ticks), an internal note set apart. Shows the message it
 * answers, its file — an image, video, voice note or document — and, when it
 * didn't go out, why, with a retry.
 */
const props = defineProps<{
    message: KinetixConversationMessage;
    /** Where its file is served (and downloaded). */
    mediaUrl: string;
    downloadUrl: string;
}>();

const emit = defineEmits<{
    (e: 'reply' | 'retry'): void;
}>();

const { t, locale } = useI18n();

const incoming = computed(() => props.message.direction === 'in');
const note = computed(() => props.message.direction === 'note');
const failed = computed(() => props.message.status === 'failed');

const time = computed(() =>
    props.message.sentAt
        ? new Intl.DateTimeFormat(locale.value, {
              hour: 'numeric',
              minute: '2-digit',
          }).format(new Date(props.message.sentAt))
        : '',
);

const size = computed(() => {
    const bytes = props.message.mediaSize ?? 0;

    if (bytes <= 0) {
        return null;
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );

    return `${(bytes / 1024 ** exponent).toFixed(exponent === 0 ? 0 : 1)} ${units[exponent]}`;
});

/** A quoted message's one-line summary: its text, else what it carried. */
function summary(body: string | null, type: string): string {
    return body ?? t(`kinetix.inbox_type_${type}`);
}

/** Open an image in the app's lightbox when it's mounted, else in a new tab. */
function preview(event: MouseEvent): void {
    const handled = !window.dispatchEvent(
        new CustomEvent('kinetix:preview', {
            cancelable: true,
            detail: {
                url: props.mediaUrl,
                type: 'image',
                downloadUrl: props.downloadUrl,
                label: props.message.mediaName,
            },
        }),
    );

    if (handled) {
        event.preventDefault();
    }
}

const statusLabel = computed(() =>
    t(`kinetix.inbox_status_${props.message.status}`),
);
</script>

<template>
    <div class="space-y-1">
        <div
            class="group gap-1.5 flex items-end"
            :class="
                incoming ? 'justify-start' : 'flex-row-reverse justify-start'
            "
            :data-test="`inbox-message-${message.direction}`"
        >
            <div
                class="sm:max-w-[70%] rounded-2xl px-3 py-2 text-sm shadow-xs min-w-0 max-w-[85%] overflow-hidden"
                :class="[
                    note
                        ? 'rounded-br-md border border-warning/40 bg-warning/10 text-foreground'
                        : incoming
                          ? 'rounded-bl-md bg-muted text-foreground'
                          : 'rounded-br-md bg-primary text-primary-foreground',
                    failed && !note
                        ? 'opacity-80 ring-2 ring-destructive/60'
                        : '',
                ]"
            >
                <p
                    v-if="note"
                    class="gap-1 text-xs font-medium mb-1 flex items-center text-warning"
                >
                    <Lock class="size-3" />
                    {{ t('kinetix.inbox_note') }}
                    <span
                        v-if="message.authorName"
                        class="font-normal opacity-80"
                        >· {{ message.authorName }}</span
                    >
                </p>

                <p
                    v-if="message.bot && message.direction === 'out'"
                    class="gap-1 font-medium mb-0.5 flex items-center text-[11px] opacity-80"
                >
                    <Bot class="size-3" />
                    {{ message.authorName ?? t('kinetix.flow_bot') }}
                </p>

                <!-- The message it answers -->
                <div
                    v-if="message.replyTo"
                    class="mb-1.5 px-2 py-1 text-xs rounded-md border-l-2"
                    :class="
                        incoming || note
                            ? 'border-primary/60 bg-background/60 text-muted-foreground'
                            : 'border-primary-foreground/60 bg-primary-foreground/15 text-primary-foreground/85'
                    "
                >
                    <p class="line-clamp-2 break-words">
                        {{
                            summary(message.replyTo.body, message.replyTo.type)
                        }}
                    </p>
                </div>

                <!-- Its file -->
                <template v-if="message.hasMedia">
                    <div
                        v-if="!message.mediaReady"
                        class="mb-1 h-28 w-56 rounded-lg motion-safe:animate-pulse max-w-full bg-background/40"
                        :aria-label="t('kinetix.inbox_media_loading')"
                        role="img"
                    />
                    <a
                        v-else-if="
                            message.type === 'image' ||
                            message.type === 'sticker'
                        "
                        :href="mediaUrl"
                        target="_blank"
                        rel="noopener"
                        class="-mx-1 -mt-0.5 mb-1 block"
                        @click="preview"
                    >
                        <img
                            :src="mediaUrl"
                            :alt="
                                message.mediaName ??
                                t('kinetix.inbox_type_image')
                            "
                            loading="lazy"
                            class="max-h-72 rounded-lg object-cover"
                            :class="
                                message.type === 'sticker' ? 'w-32' : 'w-full'
                            "
                        />
                    </a>
                    <video
                        v-else-if="message.type === 'video'"
                        :src="mediaUrl"
                        controls
                        preload="metadata"
                        class="-mx-1 mb-1 max-h-72 rounded-lg w-full"
                    />
                    <audio
                        v-else-if="message.type === 'audio'"
                        :src="mediaUrl"
                        controls
                        preload="metadata"
                        class="mb-1 w-60 max-w-full"
                    />
                    <a
                        v-else
                        :href="downloadUrl"
                        class="gap-2 mb-1 rounded-lg p-2 flex items-center transition-colors"
                        :class="
                            incoming || note
                                ? 'bg-background/70 hover:bg-background'
                                : 'bg-primary-foreground/15 hover:bg-primary-foreground/25'
                        "
                    >
                        <FileText class="size-8 shrink-0 opacity-80" />
                        <span class="min-w-0 flex-1">
                            <span class="font-medium block truncate">{{
                                message.mediaName ??
                                t('kinetix.inbox_type_document')
                            }}</span>
                            <span v-if="size" class="text-xs opacity-75">{{
                                size
                            }}</span>
                        </span>
                        <Download class="size-4 shrink-0" />
                        <span class="sr-only">{{ t('kinetix.download') }}</span>
                    </a>
                </template>

                <p
                    v-if="message.body"
                    class="break-words whitespace-pre-wrap"
                    dir="auto"
                >
                    {{ message.body }}
                </p>
                <p
                    v-else-if="!message.hasMedia"
                    class="text-xs italic opacity-75"
                >
                    {{ t(`kinetix.inbox_type_${message.type}`) }}
                </p>

                <div
                    class="mt-0.5 gap-1 flex items-center justify-end text-[11px] tabular-nums"
                    :class="
                        incoming || note
                            ? 'text-muted-foreground'
                            : 'text-primary-foreground/75'
                    "
                >
                    <span>{{ time }}</span>
                    <template v-if="message.direction === 'out'">
                        <Clock
                            v-if="message.status === 'pending'"
                            class="size-3.5"
                            :aria-label="statusLabel"
                            role="img"
                        />
                        <Check
                            v-else-if="message.status === 'sent'"
                            class="size-3.5"
                            :aria-label="statusLabel"
                            role="img"
                        />
                        <CheckCheck
                            v-else-if="
                                message.status === 'delivered' ||
                                message.status === 'read'
                            "
                            class="size-3.5"
                            :class="{
                                'text-sky-300': message.status === 'read',
                            }"
                            :aria-label="statusLabel"
                            role="img"
                        />
                        <AlertCircle
                            v-else-if="failed"
                            class="size-3.5"
                            :aria-label="statusLabel"
                            role="img"
                        />
                    </template>
                </div>
            </div>

            <!-- Answer this message: shown on hover, and to keyboard focus. -->
            <button
                v-if="!note && message.status !== 'pending' && !message.localId"
                type="button"
                class="size-7 mb-1 inline-flex shrink-0 items-center justify-center rounded-full text-muted-foreground opacity-0 transition-opacity outline-none group-hover:opacity-100 hover:bg-accent focus-visible:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 pointer-coarse:opacity-100"
                :aria-label="t('kinetix.inbox_reply')"
                data-test="inbox-reply"
                @click="emit('reply')"
            >
                <CornerUpLeft class="size-4" />
            </button>
        </div>

        <p
            v-if="failed && message.direction === 'out'"
            class="gap-1.5 text-xs -mt-1 flex items-center justify-end text-destructive"
            role="alert"
        >
            {{ message.error ?? t('kinetix.inbox_status_failed') }}
            <button
                v-if="!message.localId || message.id !== message.localId"
                type="button"
                class="gap-1 font-medium inline-flex items-center underline-offset-4 hover:underline focus-visible:underline focus-visible:outline-none"
                data-test="inbox-retry"
                @click="emit('retry')"
            >
                <RotateCw class="size-3" />
                {{ t('kinetix.inbox_retry') }}
            </button>
        </p>
    </div>
</template>
