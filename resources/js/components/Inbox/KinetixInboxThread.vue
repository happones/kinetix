<script setup lang="ts">
import {
    Archive,
    ArchiveRestore,
    ArrowLeft,
    Bot,
    Check,
    CircleStop,
    UserRound,
} from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type {
    KinetixInboxBots,
    KinetixInboxDraft,
} from '@/composables/useKinetixInbox';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixConversation,
    KinetixConversationMessage,
    KinetixQuickReply,
} from '@/types/kinetix';
import KinetixChannelProviderIcon from '../Channels/KinetixChannelProviderIcon.vue';
import KinetixInboxBubble from './KinetixInboxBubble.vue';
import KinetixInboxComposer from './KinetixInboxComposer.vue';

/**
 * An open conversation: who it's with and on which line, its assignee and
 * archive, the messages (older ones load as you scroll up, separated by
 * day) and the composer. Follows new messages while you're at the bottom.
 */
const props = defineProps<{
    conversation: KinetixConversation;
    messages: KinetixConversationMessage[];
    olderAvailable: boolean;
    loading: boolean;
    loadingOlder: boolean;
    composer: { maxUploadKb: number; accept: string };
    mediaUrl: (
        message: KinetixConversationMessage,
        download?: boolean,
    ) => string;
    fetchAssignees: () => Promise<
        { id: number | string; name: string | null }[]
    >;
    /** Bots in this conversation (absent when flows are off). */
    fetchBots?: (() => Promise<KinetixInboxBots | null>) | null;
    startBot?:
        | ((flowId: number | string) => Promise<KinetixInboxBots['run']>)
        | null;
    stopBot?: (() => Promise<void>) | null;
    replies?: KinetixQuickReply[];
    /** What quick replies' {{variables}} become (agent.name comes from the inbox). */
    agentName?: string | null;
    canManageReplies?: boolean;
}>();

const emit = defineEmits<{
    (e: 'back' | 'load-older' | 'archive' | 'unarchive'): void;
    (e: 'send', draft: KinetixInboxDraft): void;
    (e: 'retry', message: KinetixConversationMessage): void;
    (e: 'assign', userId: number | string | null): void;
    (e: 'manage-replies'): void;
}>();

const { t, locale } = useI18n();

const scroller = ref<HTMLElement | null>(null);
const top = ref<HTMLElement | null>(null);
const replyTo = ref<KinetixConversationMessage | null>(null);
const assignees = ref<{ id: number | string; name: string | null }[] | null>(
    null,
);
const bots = ref<KinetixInboxBots | null>(null);
const botBusy = ref(false);

async function loadBots(): Promise<void> {
    bots.value = props.fetchBots
        ? await props.fetchBots().catch(() => null)
        : null;
}

async function launchBot(flowId: number | string): Promise<void> {
    if (!props.startBot || !bots.value) {
        return;
    }

    botBusy.value = true;

    try {
        bots.value = { ...bots.value, run: await props.startBot(flowId) };
    } finally {
        botBusy.value = false;
    }
}

async function haltBot(): Promise<void> {
    if (!props.stopBot || !bots.value) {
        return;
    }

    botBusy.value = true;

    try {
        await props.stopBot();
        bots.value = { ...bots.value, run: null };
    } finally {
        botBusy.value = false;
    }
}

/** Sending pauses a bot on the server: check again shortly after. */
function send(draft: KinetixInboxDraft): void {
    emit('send', draft);

    if (bots.value?.run && !draft.note) {
        setTimeout(() => void loadBots(), 800);
    }
}

const replyVariables = computed<Record<string, string>>(() => ({
    'contact.name': props.conversation.contactName ?? '',
    'contact.key': props.conversation.contactKey,
    'agent.name': props.agentName ?? '',
    'line.name': props.conversation.channel.name,
}));

const title = computed(
    () => props.conversation.contactName ?? props.conversation.contactKey,
);

/** The messages with a separator wherever the day changes. */
const rows = computed(() => {
    const result: (
        | { kind: 'day'; key: string; label: string }
        | { kind: 'message'; key: string; message: KinetixConversationMessage }
    )[] = [];
    let previous = '';

    for (const message of props.messages) {
        const date = message.sentAt ? new Date(message.sentAt) : new Date();
        const day = date.toDateString();

        if (day !== previous) {
            result.push({
                kind: 'day',
                key: `day-${day}`,
                label: dayLabel(date),
            });
            previous = day;
        }

        result.push({
            kind: 'message',
            key: String(message.localId ?? message.id),
            message,
        });
    }

    return result;
});

function dayLabel(date: Date): string {
    const today = new Date().toDateString();
    const yesterday = new Date(Date.now() - 86_400_000).toDateString();

    if (date.toDateString() === today) {
        return t('kinetix.inbox_today');
    }

    if (date.toDateString() === yesterday) {
        return t('kinetix.inbox_yesterday');
    }

    return new Intl.DateTimeFormat(locale.value, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year:
            date.getFullYear() === new Date().getFullYear()
                ? undefined
                : 'numeric',
    }).format(date);
}

// ── Scrolling ──────────────────────────────────────────────────────────────

function atBottom(): boolean {
    const el = scroller.value;

    return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120;
}

function toBottom(): void {
    const el = scroller.value;

    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

let heightBeforeOlder = 0;

watch(
    () => props.loadingOlder,
    (loading) => {
        if (loading) {
            heightBeforeOlder = scroller.value?.scrollHeight ?? 0;
        }
    },
);

watch(
    () => props.messages,
    async (next, previous) => {
        const prepended =
            previous.length > 0 &&
            next.length > previous.length &&
            next[next.length - 1] === previous[previous.length - 1];
        const followed = atBottom();
        const mine = next[next.length - 1]?.localId !== undefined;

        await nextTick();

        if (prepended && scroller.value) {
            // Keep the message you were reading where it was.
            scroller.value.scrollTop +=
                scroller.value.scrollHeight - heightBeforeOlder;
        } else if (previous.length === 0 || followed || mine) {
            toBottom();
        }
    },
);

// The thread renders once loading ends: that's when the bottom is known.
watch(
    () => props.loading,
    (loading) => {
        if (!loading) {
            nextTick(toBottom);
        }
    },
);

watch(
    () => props.conversation.id,
    () => {
        replyTo.value = null;
        assignees.value = null;
        void loadBots();
        nextTick(toBottom);
    },
    { immediate: true },
);

let observer: IntersectionObserver | null = null;

watch(top, (element) => {
    observer?.disconnect();

    if (element && typeof IntersectionObserver !== 'undefined') {
        observer = new IntersectionObserver(
            (entries) => {
                if (
                    entries[0]?.isIntersecting &&
                    props.olderAvailable &&
                    !props.loadingOlder &&
                    !props.loading
                ) {
                    emit('load-older');
                }
            },
            { root: scroller.value },
        );
        observer.observe(element);
    }
});

onBeforeUnmount(() => observer?.disconnect());

async function loadAssignees(open: boolean): Promise<void> {
    if (open && assignees.value === null) {
        assignees.value = await props.fetchAssignees().catch(() => []);
    }
}

const itemClass =
    'gap-2 px-2 py-1.5 text-sm flex w-full cursor-default items-center rounded-md outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground';
</script>

<template>
    <section
        class="min-h-0 flex flex-1 flex-col"
        :aria-label="title"
        data-test="inbox-thread"
    >
        <header
            class="gap-2 px-3 py-2.5 flex items-center border-b border-border"
        >
            <button
                type="button"
                :class="buttonVariants({ variant: 'ghost', size: 'icon-sm' })"
                class="md:hidden"
                :aria-label="t('kinetix.inbox_back')"
                data-test="inbox-back"
                @click="emit('back')"
            >
                <ArrowLeft class="size-4" />
            </button>

            <KinetixChannelProviderIcon
                :provider="conversation.channel.provider"
                size="md"
            />
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold truncate text-foreground">
                    {{ title }}
                </h2>
                <p class="text-xs truncate text-muted-foreground">
                    <template v-if="conversation.contactName"
                        >{{ conversation.contactKey }} ·
                    </template>
                    {{ conversation.channel.name }}
                </p>
            </div>

            <DropdownMenuRoot @update:open="loadAssignees">
                <DropdownMenuTrigger
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    class="max-w-40 relative"
                    data-test="inbox-assign"
                >
                    <UserRound class="size-4" />
                    <span class="sm:inline hidden truncate">{{
                        conversation.assignee?.name ??
                        t('kinetix.inbox_unassigned')
                    }}</span>
                    <span class="sm:hidden sr-only">{{
                        t('kinetix.inbox_assign')
                    }}</span>
                </DropdownMenuTrigger>
                <DropdownMenuPortal>
                    <DropdownMenuContent
                        align="end"
                        :side-offset="4"
                        :collision-padding="8"
                        class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] max-h-(--reka-dropdown-menu-content-available-height) min-w-[14rem] overflow-y-auto border border-border bg-popover text-popover-foreground"
                    >
                        <DropdownMenuLabel
                            class="px-2 py-1.5 text-xs font-medium text-muted-foreground"
                        >
                            {{ t('kinetix.inbox_assign') }}
                        </DropdownMenuLabel>
                        <p
                            v-if="assignees === null"
                            class="px-2 py-1.5 text-sm text-muted-foreground"
                        >
                            {{ t('kinetix.inbox_loading') }}
                        </p>
                        <DropdownMenuItem
                            v-for="person in assignees ?? []"
                            :key="person.id"
                            :class="itemClass"
                            @select="emit('assign', person.id)"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                person.name
                            }}</span>
                            <Check
                                class="size-4 text-primary"
                                :class="{
                                    invisible:
                                        String(conversation.assignee?.id) !==
                                        String(person.id),
                                }"
                            />
                        </DropdownMenuItem>
                        <template v-if="conversation.assignee">
                            <DropdownMenuSeparator
                                class="my-1 h-px bg-border"
                            />
                            <DropdownMenuItem
                                :class="itemClass"
                                @select="emit('assign', null)"
                            >
                                {{ t('kinetix.inbox_unassign') }}
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenuPortal>
            </DropdownMenuRoot>

            <DropdownMenuRoot v-if="bots">
                <DropdownMenuTrigger
                    :class="
                        buttonVariants({
                            variant: bots.run ? 'secondary' : 'ghost',
                            size: 'icon-sm',
                        })
                    "
                    :aria-label="t('kinetix.inbox_bots')"
                    data-test="inbox-bots"
                >
                    <Bot class="size-4" />
                </DropdownMenuTrigger>
                <DropdownMenuPortal>
                    <DropdownMenuContent
                        align="end"
                        :side-offset="4"
                        :collision-padding="8"
                        class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] min-w-[14rem] border border-border bg-popover text-popover-foreground"
                    >
                        <DropdownMenuLabel
                            class="px-2 py-1.5 text-xs font-medium text-muted-foreground"
                        >
                            {{ t('kinetix.inbox_start_bot') }}
                        </DropdownMenuLabel>
                        <p
                            v-if="bots.flows.length === 0"
                            class="px-2 py-1.5 text-sm text-muted-foreground"
                        >
                            {{ t('kinetix.inbox_no_bots') }}
                        </p>
                        <DropdownMenuItem
                            v-for="flow in bots.flows"
                            :key="flow.id"
                            :class="itemClass"
                            :disabled="botBusy"
                            :data-test="`inbox-start-bot-${flow.id}`"
                            @select="launchBot(flow.id)"
                        >
                            {{ flow.name }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenuPortal>
            </DropdownMenuRoot>

            <button
                type="button"
                :class="buttonVariants({ variant: 'ghost', size: 'icon-sm' })"
                :aria-label="
                    conversation.archived
                        ? t('kinetix.inbox_unarchive')
                        : t('kinetix.inbox_archive')
                "
                :title="
                    conversation.archived
                        ? t('kinetix.inbox_unarchive')
                        : t('kinetix.inbox_archive')
                "
                data-test="inbox-archive"
                @click="emit(conversation.archived ? 'unarchive' : 'archive')"
            >
                <ArchiveRestore v-if="conversation.archived" class="size-4" />
                <Archive v-else class="size-4" />
            </button>
        </header>

        <!-- A bot is answering: say so, with a way to take over. -->
        <div
            v-if="bots?.run"
            class="gap-2 px-3 py-2 text-xs flex items-center border-b border-border bg-primary/5 text-foreground"
            role="status"
            data-test="inbox-bot-running"
        >
            <Bot class="size-4 shrink-0 text-primary" />
            <span class="min-w-0 flex-1">
                {{ t('kinetix.inbox_bot_active', { name: bots.run.flow }) }}
                <span class="text-muted-foreground"
                    >· {{ t(`kinetix.inbox_bot_${bots.run.status}`) }}</span
                >
            </span>
            <button
                type="button"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                class="h-7"
                :disabled="botBusy"
                data-test="inbox-stop-bot"
                @click="haltBot"
            >
                <CircleStop class="size-3.5" />
                {{ t('kinetix.inbox_stop_bot') }}
            </button>
        </div>

        <div
            ref="scroller"
            class="min-h-0 px-3 py-4 sm:px-5 flex-1 overflow-y-auto overscroll-contain bg-muted/20"
            role="log"
            aria-live="polite"
            :aria-busy="loading || loadingOlder"
        >
            <div ref="top" class="h-px" aria-hidden="true" />
            <p
                v-if="loadingOlder"
                class="pb-3 text-xs text-center text-muted-foreground"
            >
                {{ t('kinetix.inbox_loading') }}
            </p>

            <div v-if="loading" class="space-y-3" aria-hidden="true">
                <div
                    v-for="n in 5"
                    :key="n"
                    class="h-10 rounded-2xl motion-safe:animate-pulse bg-muted"
                    :class="n % 2 ? 'w-2/3' : 'ml-auto w-1/2'"
                />
            </div>

            <div v-else class="space-y-2">
                <template v-for="row in rows" :key="row.key">
                    <div
                        v-if="row.kind === 'day'"
                        class="py-2 flex justify-center"
                    >
                        <span
                            class="px-2.5 py-0.5 text-xs font-medium shadow-xs rounded-full bg-background text-muted-foreground"
                            >{{ row.label }}</span
                        >
                    </div>
                    <KinetixInboxBubble
                        v-else
                        :message="row.message"
                        :media-url="mediaUrl(row.message)"
                        :download-url="mediaUrl(row.message, true)"
                        @reply="replyTo = row.message"
                        @retry="emit('retry', row.message)"
                    />
                </template>
            </div>
        </div>

        <KinetixInboxComposer
            :replies="replies ?? []"
            :reply-variables="replyVariables"
            :can-manage-replies="canManageReplies"
            @manage-replies="emit('manage-replies')"
            :reply-to="replyTo"
            :max-upload-kb="composer.maxUploadKb"
            :accept="composer.accept"
            @send="send"
            @cancel-reply="replyTo = null"
        />
    </section>
</template>
