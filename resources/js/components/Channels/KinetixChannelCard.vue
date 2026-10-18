<script setup lang="ts">
import {
    ExternalLink,
    MoreHorizontal,
    Pencil,
    QrCode,
    RefreshCw,
    RotateCw,
    Send,
    Trash2,
    Unlink,
    Users,
} from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixChannel } from '@/types/kinetix';
import KinetixBadge from '../primitives/KinetixBadge.vue';
import KinetixChannelProviderIcon from './KinetixChannelProviderIcon.vue';

/**
 * One line in <KinetixChannelManager>: its provider, name and number or
 * username, where it stands, the next step (link it, reconnect it, open the
 * bot) and the rest of its actions in a menu.
 */
const props = defineProps<{
    channel: KinetixChannel;
    /** Whether the line is linked by scanning a QR (else by a token). */
    scannable: boolean;
    /** Whether lines have agents (the inbox is on). */
    agents?: boolean;
}>();

const emit = defineEmits<{
    (
        e:
            | 'link'
            | 'test'
            | 'restart'
            | 'disconnect'
            | 'rename'
            | 'remove'
            | 'agents',
    ): void;
}>();

const { t } = useI18n();

const connected = computed(() => props.channel.status === 'connected');

const statusColor = computed(
    () =>
        ({
            connected: 'success',
            connecting: 'warning',
            failed: 'danger',
            disconnected: 'gray',
        })[props.channel.status],
);

/** A bot's public page, once its username is known. */
const botUrl = computed(() =>
    props.channel.provider === 'telegram' &&
    props.channel.handle?.startsWith('@')
        ? `https://t.me/${props.channel.handle.slice(1)}`
        : null,
);

const itemClass =
    'gap-2 px-2 py-1.5 text-sm flex w-full cursor-default items-center rounded-md outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50';
</script>

<template>
    <li
        class="gap-4 rounded-xl p-4 shadow-xs flex flex-col border border-border bg-card"
        :data-test="`channel-${channel.id}`"
    >
        <div class="gap-3 flex items-start">
            <KinetixChannelProviderIcon :provider="channel.provider" />
            <div class="min-w-0 flex-1">
                <p class="font-medium truncate text-foreground">
                    {{ channel.name }}
                </p>
                <p class="text-sm truncate text-muted-foreground">
                    {{ channel.handle ?? t('kinetix.channel_not_linked') }}
                </p>
            </div>

            <DropdownMenuRoot>
                <DropdownMenuTrigger
                    :class="
                        buttonVariants({ variant: 'ghost', size: 'icon-sm' })
                    "
                    :aria-label="
                        t('kinetix.channel_actions', { name: channel.name })
                    "
                    data-test="channel-actions"
                >
                    <MoreHorizontal class="size-4" />
                </DropdownMenuTrigger>
                <DropdownMenuPortal>
                    <DropdownMenuContent
                        align="end"
                        :side-offset="4"
                        :collision-padding="8"
                        class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] min-w-[12rem] border border-border bg-popover text-popover-foreground"
                    >
                        <DropdownMenuItem
                            v-if="connected"
                            :class="itemClass"
                            @select="emit('test')"
                        >
                            <Send class="size-4" />
                            {{ t('kinetix.channel_test') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="channel.status !== 'disconnected'"
                            :class="itemClass"
                            @select="emit('restart')"
                        >
                            <RotateCw class="size-4" />
                            {{ t('kinetix.channel_restart') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="agents"
                            :class="itemClass"
                            data-test="channel-agents"
                            @select="emit('agents')"
                        >
                            <Users class="size-4" />
                            {{ t('kinetix.channel_agents') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :class="itemClass"
                            @select="emit('rename')"
                        >
                            <Pencil class="size-4" />
                            {{ t('kinetix.channel_rename') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator class="my-1 h-px bg-border" />
                        <DropdownMenuItem
                            v-if="connected"
                            :class="itemClass"
                            @select="emit('disconnect')"
                        >
                            <Unlink class="size-4" />
                            {{ t('kinetix.channel_unlink') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :class="[
                                itemClass,
                                'text-destructive focus:text-destructive',
                            ]"
                            data-test="channel-delete"
                            @select="emit('remove')"
                        >
                            <Trash2 class="size-4" />
                            {{ t('kinetix.delete') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenuPortal>
            </DropdownMenuRoot>
        </div>

        <p
            v-if="channel.status === 'failed' && channel.lastError"
            class="text-xs -mt-2 text-destructive"
        >
            {{ channel.lastError }}
        </p>

        <div class="gap-2 mt-auto flex flex-wrap items-center justify-between">
            <KinetixBadge :color="statusColor" class="gap-1.5">
                <span
                    class="size-1.5 rounded-full bg-current"
                    :class="{
                        'motion-safe:animate-pulse':
                            channel.status === 'connecting',
                    }"
                    aria-hidden="true"
                />
                {{ t(`kinetix.channel_status_${channel.status}`) }}
            </KinetixBadge>

            <button
                v-if="!connected && scannable"
                type="button"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                data-test="channel-link"
                @click="emit('link')"
            >
                <QrCode class="size-4" />
                {{ t('kinetix.channel_link') }}
            </button>
            <button
                v-else-if="!connected"
                type="button"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                @click="emit('restart')"
            >
                <RefreshCw class="size-4" />
                {{ t('kinetix.channel_reconnect') }}
            </button>
            <a
                v-else-if="botUrl"
                :href="botUrl"
                target="_blank"
                rel="noopener"
                :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
            >
                <ExternalLink class="size-4" />
                {{ t('kinetix.channel_open_bot') }}
            </a>
        </div>
    </li>
</template>
