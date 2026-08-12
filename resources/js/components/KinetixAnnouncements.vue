<script setup lang="ts">
import { Megaphone } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    useKinetixAnnouncementFormat,
    useKinetixAnnouncements,
} from '@/composables/useKinetixAnnouncements';
import {
    buttonVariants,
    triggerCountBadgeClass,
} from '@/composables/useKinetixShadcnVariants';
import ScrollArea from './primitives/ScrollArea.vue';

/**
 * "What's new" header control: an icon button with an unread badge that opens a
 * popover listing published announcements. Opening the popover marks the feed
 * seen, clearing the badge. Mount it in your app header.
 */
const { t } = useI18n();
const { announcements, unread, load, markSeen } = useKinetixAnnouncements();
const { levelClass, levelLabel, formatDate } = useKinetixAnnouncementFormat();

const open = ref(false);

/** The badge is a number on screen; the trigger has to say what it counts. */
const triggerLabel = computed(() =>
    unread.value > 0
        ? `${t('kinetix.announcements_title')} — ${t('kinetix.unread_count', { count: unread.value })}`
        : t('kinetix.announcements_title'),
);

onMounted(load);

function onOpen(next: boolean): void {
    open.value = next;

    if (next && unread.value > 0) {
        markSeen();
    }
}
</script>

<template>
    <PopoverRoot :open="open" @update:open="onOpen">
        <PopoverTrigger
            :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
            class="relative"
            :aria-label="triggerLabel"
        >
            <Megaphone class="size-[1.2rem]" />
            <span
                v-if="unread > 0"
                aria-hidden="true"
                :class="triggerCountBadgeClass"
            >
                {{ unread > 99 ? '99+' : unread }}
            </span>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="8"
                class="w-80 shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] origin-(--reka-popover-content-transform-origin) rounded-md border border-border bg-popover text-popover-foreground outline-none"
            >
                <div class="px-4 py-3 border-b border-border">
                    <p class="text-sm font-semibold text-foreground">
                        {{ t('kinetix.announcements_title') }}
                    </p>
                </div>

                <!-- The cap goes on the viewport, so a short feed keeps its own
                     height and a long one scrolls inside the popover. -->
                <ScrollArea viewport-class="max-h-96">
                    <p
                        v-if="announcements.length === 0"
                        class="px-4 py-6 text-sm text-center text-muted-foreground"
                    >
                        {{ t('kinetix.announcements_empty') }}
                    </p>

                    <article
                        v-for="a in announcements"
                        :key="String(a.id)"
                        class="px-4 py-3 border-b border-border last:border-0"
                    >
                        <div class="gap-2 flex items-center">
                            <span
                                v-if="a.isNew"
                                aria-hidden="true"
                                class="size-2 shrink-0 rounded-full bg-primary"
                            />
                            <h3
                                class="min-w-0 text-sm font-medium flex-1 text-foreground"
                            >
                                <span v-if="a.isNew" class="sr-only">
                                    {{ t('kinetix.announcements_new') }}:
                                </span>
                                {{ a.title }}
                            </h3>
                            <span
                                class="px-2 py-0.5 font-medium shrink-0 rounded-full text-[10px]"
                                :class="levelClass(a.level)"
                            >
                                {{ levelLabel(a.level) }}
                            </span>
                        </div>
                        <p
                            class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ a.body }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground/70">
                            {{ formatDate(a.publishedAt) }}
                        </p>
                    </article>
                </ScrollArea>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
