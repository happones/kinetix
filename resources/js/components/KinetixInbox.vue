<script setup lang="ts">
import { MessagesSquare } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import type { KinetixInboxDraft } from '@/composables/useKinetixInbox';
import { useKinetixInbox } from '@/composables/useKinetixInbox';
import type {
    KinetixConversation,
    KinetixConversationMessage,
} from '@/types/kinetix';
import KinetixInboxList from './Inbox/KinetixInboxList.vue';
import KinetixInboxQuickReplies from './Inbox/KinetixInboxQuickReplies.vue';
import KinetixInboxThread from './Inbox/KinetixInboxThread.vue';

/**
 * The team inbox for the lines of Kinetix Channels: every conversation the
 * user answers, with their own unread counts, and the open thread — reply
 * with text and files, answer a specific message, leave internal notes,
 * assign and archive. One pane at a time on a phone.
 *
 * Give it a height (it fills its container). `conversation` opens one on
 * load (a deep link); `@open` reports the open one, to keep it in the URL.
 * The `aside` slot adds a side panel for the open conversation (≥ xl).
 */
const props = withDefaults(
    defineProps<{
        conversation?: number | string | null;
    }>(),
    { conversation: null },
);

const emit = defineEmits<{
    (e: 'open', conversation: KinetixConversation | null): void;
}>();

const { t } = useI18n();
const inbox = useKinetixInbox();

let searchTimer: ReturnType<typeof setTimeout> | null = null;
const managingReplies = ref(false);

onMounted(async () => {
    void inbox.loadReplies();
    await inbox.loadList();

    if (props.conversation !== null) {
        const found =
            inbox.conversations.value.find(
                (c) => String(c.id) === String(props.conversation),
            ) ?? (await inbox.refreshConversation(props.conversation));

        if (found) {
            await select(found);
        }
    }
});

watch(inbox.search, () => {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => void inbox.loadList(), 300);
});

watch([inbox.filter, inbox.channelId], () => void inbox.loadList());

async function select(conversation: KinetixConversation): Promise<void> {
    emit('open', conversation);
    await inbox.open(conversation);
}

function back(): void {
    inbox.close();
    emit('open', null);
}

async function run(work: () => Promise<void>): Promise<void> {
    try {
        await work();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : String(error));
    }
}

function send(draft: KinetixInboxDraft): void {
    void inbox.send(draft);
}

function retry(message: KinetixConversationMessage): void {
    void inbox.retry(message);
}
</script>

<template>
    <div
        class="rounded-xl flex h-full min-h-[32rem] overflow-hidden border border-border bg-card"
        data-test="kinetix-inbox"
    >
        <KinetixInboxList
            v-model:search="inbox.search.value"
            v-model:filter="inbox.filter.value"
            v-model:channel-id="inbox.channelId.value"
            class="md:w-80 lg:w-96 md:border-r md:border-border w-full shrink-0"
            :class="{ 'md:flex hidden': inbox.active.value }"
            :conversations="inbox.conversations.value"
            :channels="inbox.channels.value"
            :active-id="inbox.active.value?.id ?? null"
            :has-more="inbox.hasMore.value"
            :loading="inbox.loading.value"
            :loaded="inbox.loaded.value"
            @select="select"
            @load-more="inbox.loadList(true)"
        />

        <KinetixInboxThread
            v-if="inbox.active.value"
            :conversation="inbox.active.value"
            :messages="inbox.messages.value"
            :older-available="inbox.olderAvailable.value"
            :loading="inbox.loadingMessages.value"
            :loading-older="inbox.loadingOlder.value"
            :composer="inbox.composer.value"
            :media-url="inbox.mediaUrl"
            :fetch-assignees="() => inbox.assignees(inbox.active.value!)"
            :fetch-bots="
                inbox.flowsEnabled.value
                    ? () => inbox.bots(inbox.active.value!)
                    : null
            "
            :start-bot="(flowId) => inbox.startBot(inbox.active.value!, flowId)"
            :stop-bot="() => inbox.stopBot(inbox.active.value!)"
            :replies="inbox.quickReplies.value"
            :agent-name="inbox.me.value?.name ?? null"
            :can-manage-replies="inbox.canManageReplies.value"
            @manage-replies="managingReplies = true"
            @back="back"
            @load-older="inbox.loadOlder()"
            @send="send"
            @retry="retry"
            @assign="
                (userId) => run(() => inbox.assign(inbox.active.value!, userId))
            "
            @archive="run(() => inbox.archive(inbox.active.value!))"
            @unarchive="run(() => inbox.unarchive(inbox.active.value!))"
        />

        <div
            v-else
            class="p-8 md:flex hidden flex-1 flex-col items-center justify-center bg-muted/20 text-center"
        >
            <slot name="empty">
                <span
                    class="mb-4 size-14 flex items-center justify-center rounded-full bg-muted"
                >
                    <MessagesSquare class="size-7 text-muted-foreground" />
                </span>
                <h3 class="text-sm font-semibold text-foreground">
                    {{ t('kinetix.inbox_pick_title') }}
                </h3>
                <p class="mt-1 text-sm max-w-xs text-muted-foreground">
                    {{
                        inbox.channels.value.length === 0 && inbox.loaded.value
                            ? t('kinetix.inbox_no_lines')
                            : t('kinetix.inbox_pick_description')
                    }}
                </p>
            </slot>
        </div>

        <KinetixInboxQuickReplies
            v-model:open="managingReplies"
            :replies="inbox.quickReplies.value"
            :save="inbox.saveReply"
            :remove="inbox.removeReply"
        />

        <aside
            v-if="inbox.active.value && $slots.aside"
            class="w-80 xl:block hidden shrink-0 overflow-y-auto border-l border-border"
        >
            <slot name="aside" :conversation="inbox.active.value" />
        </aside>
    </div>
</template>
