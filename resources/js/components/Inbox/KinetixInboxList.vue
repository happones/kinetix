<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixConversation,
    KinetixInboxChannel,
    KinetixInboxFilter,
    KinetixSelectOption,
} from '@/types/kinetix';
import KinetixChannelProviderIcon from '../Channels/KinetixChannelProviderIcon.vue';
import KinetixSelect from '../KinetixSelect.vue';
import { cn } from '../primitives/cn';

/**
 * The inbox's conversation list: search (contact, number or message),
 * filters (unread, mine, unassigned, archived), the line, and each
 * conversation with its last message, time and the current user's unread
 * count. Loads more as it scrolls.
 */
const props = defineProps<{
    conversations: KinetixConversation[];
    channels: KinetixInboxChannel[];
    activeId: number | string | null;
    hasMore: boolean;
    loading: boolean;
    loaded: boolean;
}>();

const emit = defineEmits<{
    (e: 'select', conversation: KinetixConversation): void;
    (e: 'load-more'): void;
}>();

const search = defineModel<string>('search', { default: '' });
const filter = defineModel<KinetixInboxFilter>('filter', { default: 'all' });
const channelId = defineModel<number | string | null>('channelId', {
    default: null,
});

const { t, locale } = useI18n();

const FILTERS: KinetixInboxFilter[] = [
    'all',
    'unread',
    'mine',
    'unassigned',
    'archived',
];

const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

watch(sentinel, (element) => {
    observer?.disconnect();

    if (element && typeof IntersectionObserver !== 'undefined') {
        observer = new IntersectionObserver((entries) => {
            if (entries[0]?.isIntersecting && props.hasMore && !props.loading) {
                emit('load-more');
            }
        });
        observer.observe(element);
    }
});

onBeforeUnmount(() => observer?.disconnect());

function initials(conversation: KinetixConversation): string {
    const name = conversation.contactName ?? conversation.contactKey;
    const words = name
        .replace(/[^\p{L}\p{N}\s]/gu, '')
        .trim()
        .split(/\s+/);

    return (
        words
            .slice(0, 2)
            .map((word) => word[0] ?? '')
            .join('')
            .toUpperCase() || '#'
    );
}

/** Today: the time; this week: the weekday; before: the date. */
function when(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const now = new Date();
    const days = Math.floor(
        (new Date(now.toDateString()).getTime() -
            new Date(date.toDateString()).getTime()) /
            86_400_000,
    );

    if (days <= 0) {
        return new Intl.DateTimeFormat(locale.value, {
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    }

    if (days === 1) {
        return t('kinetix.inbox_yesterday');
    }

    return new Intl.DateTimeFormat(
        locale.value,
        days < 7 ? { weekday: 'short' } : { day: 'numeric', month: 'short' },
    ).format(date);
}

function snippet(conversation: KinetixConversation): string {
    const last = conversation.lastMessage;

    if (!last) {
        return '';
    }

    const text = last.body ?? t(`kinetix.inbox_type_${last.type}`);

    if (last.direction === 'note') {
        return `${t('kinetix.inbox_note')}: ${text}`;
    }

    return last.direction === 'out'
        ? `${t('kinetix.inbox_you')}: ${text}`
        : text;
}

const showLines = computed(() => props.channels.length > 1);
const lineOptions = computed<KinetixSelectOption[]>(() => [
    { value: '', label: t('kinetix.inbox_all_lines') },
    ...props.channels.map((line) => ({ value: line.id, label: line.name })),
]);
</script>

<template>
    <div class="min-h-0 flex flex-col" data-test="inbox-list">
        <div class="p-3 space-y-2 border-b border-border">
            <div class="relative">
                <Search
                    class="left-2.5 size-4 pointer-events-none absolute top-1/2 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('kinetix.inbox_search')"
                    :aria-label="t('kinetix.inbox_search')"
                    :class="[inputClass, 'pl-8']"
                    data-test="inbox-search"
                />
            </div>

            <div
                class="gap-1 -mx-1 px-1 flex [scrollbar-width:none] overflow-x-auto"
                role="tablist"
                :aria-label="t('kinetix.inbox_filters')"
            >
                <button
                    v-for="option in FILTERS"
                    :key="option"
                    type="button"
                    role="tab"
                    :aria-selected="filter === option"
                    class="px-2.5 py-1 text-xs font-medium shrink-0 rounded-full border transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :class="
                        filter === option
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground'
                    "
                    :data-test="`inbox-filter-${option}`"
                    @click="filter = option"
                >
                    {{ t(`kinetix.inbox_filter_${option}`) }}
                </button>
            </div>

            <KinetixSelect
                v-if="showLines"
                :value="channelId === null ? '' : String(channelId)"
                :options="lineOptions"
                size="sm"
                :aria-label="t('kinetix.inbox_line')"
                data-test="inbox-line"
                @update:value="
                    channelId =
                        channels.find((line) => String(line.id) === $event)
                            ?.id ?? null
                "
            />
        </div>

        <ul class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            <template v-if="!loaded">
                <li
                    v-for="n in 6"
                    :key="n"
                    class="gap-3 px-3 py-3 flex items-center"
                    aria-hidden="true"
                >
                    <span
                        class="size-10 motion-safe:animate-pulse shrink-0 rounded-full bg-muted"
                    />
                    <span class="space-y-2 flex-1">
                        <span
                            class="h-3 rounded motion-safe:animate-pulse block w-1/2 bg-muted"
                        />
                        <span
                            class="h-3 rounded motion-safe:animate-pulse block w-3/4 bg-muted"
                        />
                    </span>
                </li>
            </template>

            <li
                v-else-if="conversations.length === 0"
                class="px-6 py-12 text-sm text-center text-muted-foreground"
            >
                {{
                    search
                        ? t('kinetix.inbox_no_results')
                        : t(`kinetix.inbox_empty_${filter}`)
                }}
            </li>

            <li v-for="conversation in conversations" :key="conversation.id">
                <button
                    type="button"
                    :class="
                        cn(
                            'gap-3 px-3 py-2.5 flex w-full items-center text-left transition-colors outline-none hover:bg-accent/60 focus-visible:bg-accent focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-inset',
                            activeId === conversation.id && 'bg-accent',
                        )
                    "
                    :aria-current="
                        activeId === conversation.id ? 'true' : undefined
                    "
                    :data-test="`inbox-conversation-${conversation.id}`"
                    @click="emit('select', conversation)"
                >
                    <span class="relative shrink-0">
                        <span
                            class="size-10 text-sm font-semibold inline-flex items-center justify-center rounded-full bg-muted text-muted-foreground"
                            aria-hidden="true"
                            >{{ initials(conversation) }}</span
                        >
                        <KinetixChannelProviderIcon
                            :provider="conversation.channel.provider"
                            size="sm"
                            class="-right-1 -bottom-1 !size-5 absolute rounded-full ring-2 ring-background"
                        />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="gap-2 flex items-baseline justify-between">
                            <span
                                class="truncate"
                                :class="
                                    conversation.unread > 0
                                        ? 'font-semibold text-foreground'
                                        : 'font-medium text-foreground'
                                "
                                >{{
                                    conversation.contactName ??
                                    conversation.contactKey
                                }}</span
                            >
                            <span
                                class="text-xs shrink-0 tabular-nums"
                                :class="
                                    conversation.unread > 0
                                        ? 'font-medium text-primary'
                                        : 'text-muted-foreground'
                                "
                                >{{ when(conversation.lastMessageAt) }}</span
                            >
                        </span>
                        <span class="gap-2 mt-0.5 flex items-center">
                            <span
                                class="text-sm min-w-0 flex-1 truncate"
                                :class="
                                    conversation.unread > 0
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                                >{{ snippet(conversation) }}</span
                            >
                            <span
                                v-if="conversation.unread > 0"
                                class="min-w-5 h-5 px-1.5 font-semibold inline-flex shrink-0 items-center justify-center rounded-full bg-primary text-[11px] text-primary-foreground tabular-nums"
                                :aria-label="
                                    t('kinetix.inbox_unread', {
                                        count: conversation.unread,
                                    })
                                "
                                >{{ conversation.unread }}</span
                            >
                        </span>
                        <span
                            v-if="showLines || conversation.assignee"
                            class="mt-0.5 text-xs block truncate text-muted-foreground"
                        >
                            <template v-if="showLines">{{
                                conversation.channel.name
                            }}</template>
                            <template v-if="showLines && conversation.assignee">
                                ·
                            </template>
                            <template v-if="conversation.assignee">{{
                                conversation.assignee.name
                            }}</template>
                        </span>
                    </span>
                </button>
            </li>

            <li
                v-if="hasMore"
                ref="sentinel"
                class="py-4 text-xs text-center text-muted-foreground"
            >
                {{ t('kinetix.inbox_loading') }}
            </li>
        </ul>
    </div>
</template>
