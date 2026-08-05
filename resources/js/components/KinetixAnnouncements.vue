<script setup lang="ts">
import { Megaphone } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixAnnouncements } from '@/composables/useKinetixAnnouncements';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';

/**
 * "What's new" header control: an icon button with an unread badge that opens a
 * popover listing published announcements. Opening the popover marks the feed
 * seen, clearing the badge. Mount it in your app header.
 */
const { t } = useI18n();
const { announcements, unread, load, markSeen } = useKinetixAnnouncements();

const open = ref(false);

onMounted(load);

function onOpen(next: boolean): void {
    open.value = next;

    if (next && unread.value > 0) {
        markSeen();
    }
}

const levelClass: Record<string, string> = {
    feature: 'bg-success/15 text-success',
    fix: 'bg-info/15 text-info',
    info: 'bg-muted text-muted-foreground',
};

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString() : '';
}
</script>

<template>
    <PopoverRoot :open="open" @update:open="onOpen">
        <PopoverTrigger
            :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
            class="relative"
            :aria-label="t('kinetix.announcements_title')"
        >
            <Megaphone class="size-[1.2rem]" />
            <span
                v-if="unread > 0"
                class="-top-1.5 -right-1.5 min-w-4 px-1 font-semibold absolute flex items-center justify-center rounded-full bg-primary text-[10px] text-primary-foreground"
            >
                {{ unread > 9 ? '9+' : unread }}
            </span>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="8"
                class="w-80 rounded-lg shadow-lg z-[var(--kinetix-z-popover,120)] border border-border bg-popover text-popover-foreground outline-none"
            >
                <div class="px-4 py-3 border-b border-border">
                    <p class="text-sm font-semibold text-foreground">
                        {{ t('kinetix.announcements_title') }}
                    </p>
                </div>

                <div class="max-h-96 overflow-y-auto">
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
                                class="size-2 shrink-0 rounded-full bg-primary"
                                :aria-label="t('kinetix.announcements_new')"
                            />
                            <h3
                                class="min-w-0 text-sm font-medium flex-1 text-foreground"
                            >
                                {{ a.title }}
                            </h3>
                            <span
                                class="px-2 py-0.5 font-medium shrink-0 rounded-full text-[10px]"
                                :class="levelClass[a.level] ?? levelClass.info"
                            >
                                {{ a.level }}
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
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
