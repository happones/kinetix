<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixPresence } from '@/composables/useKinetixPresence';

/**
 * A live avatar facepile of the users currently online, over a presence channel.
 * Shows up to `max` avatars (image or initials), a "+N" overflow, and a count.
 * Requires broadcasting configured; renders nothing until presence is enabled.
 */
const props = withDefaults(
    defineProps<{
        /** Maximum avatars to show before collapsing into "+N". */
        max?: number;
        /** Show the "{n} online" count label. */
        showCount?: boolean;
        /** Optional presence channel override (defaults to the shared one). */
        channel?: string;
    }>(),
    { max: 5, showCount: true, channel: undefined },
);

const { t } = useI18n();
const { users, count, channel } = useKinetixPresence(props.channel);

const visible = computed(() => users.value.slice(0, props.max));
const overflow = computed(() => Math.max(0, count.value - props.max));

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}
</script>

<template>
    <div
        v-if="channel"
        class="gap-2 flex items-center"
        :aria-label="t('kinetix.presence_online', { count })"
    >
        <div class="-space-x-2 flex">
            <span
                v-for="user in visible"
                :key="user.id"
                class="size-7 text-xs font-medium flex items-center justify-center overflow-hidden rounded-full bg-muted text-muted-foreground ring-2 ring-background"
                :title="user.name"
            >
                <img
                    v-if="user.avatar"
                    :src="user.avatar"
                    :alt="user.name"
                    class="size-full object-cover"
                />
                <template v-else>{{ initials(user.name) }}</template>
            </span>
            <span
                v-if="overflow > 0"
                class="size-7 text-xs font-medium flex items-center justify-center rounded-full bg-accent text-accent-foreground ring-2 ring-background"
            >
                +{{ overflow }}
            </span>
        </div>

        <span
            v-if="showCount && count > 0"
            class="text-sm text-muted-foreground"
        >
            <span
                class="mr-1.5 size-2 bg-green-500 inline-block rounded-full align-middle"
                aria-hidden="true"
            />
            {{ t('kinetix.presence_online', { count }) }}
        </span>
    </div>
</template>
