<script setup lang="ts">
import { notificationChannelIcon } from './notificationRoutingIcons';

/**
 * A row of delivery-channel icons, each named for assistive tech and on hover
 * (`title`). `labels` maps a channel key to its label; an unknown key shows
 * the key itself.
 */
defineProps<{
    channels: string[];
    labels: Record<string, string>;
}>();
</script>

<template>
    <ul class="gap-1.5 flex items-center">
        <li
            v-for="channel in channels"
            :key="channel"
            :title="labels[channel] ?? channel"
            class="size-6 flex items-center justify-center rounded-md bg-muted text-muted-foreground"
        >
            <component
                :is="notificationChannelIcon(channel)"
                class="size-3.5"
                aria-hidden="true"
            />
            <span class="sr-only">{{ labels[channel] ?? channel }}</span>
        </li>
    </ul>
</template>
