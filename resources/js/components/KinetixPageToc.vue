<script setup lang="ts">
import { List } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { KinetixHelpTocEntry } from '@/composables/useKinetixHelpToc';

/**
 * "On this page" for a long page (help articles, docs): the `rail` is the
 * sticky right column that marks the section in view; the `disclosure` is the
 * collapsible version for narrower screens. Pair it with
 * `useKinetixHelpToc()`, which builds the entries and tracks `activeId`.
 */
withDefaults(
    defineProps<{
        entries: KinetixHelpTocEntry[];
        activeId?: string;
        variant?: 'rail' | 'disclosure';
    }>(),
    { activeId: '', variant: 'rail' },
);

const emit = defineEmits<{ (e: 'navigate', id: string): void }>();

const { t } = useI18n();
</script>

<template>
    <details
        v-if="variant === 'disclosure'"
        class="rounded-lg border border-border bg-card"
    >
        <summary
            class="gap-2 px-4 py-2.5 text-sm font-medium flex cursor-pointer items-center select-none"
        >
            <List class="size-4 text-muted-foreground" />
            {{ t('kinetix.help_on_this_page') }}
        </summary>
        <nav
            class="space-y-0.5 p-2 border-t border-border"
            :aria-label="t('kinetix.help_on_this_page')"
        >
            <a
                v-for="entry in entries"
                :key="entry.id"
                :href="`#${entry.id}`"
                :class="[
                    'px-2 py-1 text-sm block rounded-md text-muted-foreground transition-colors hover:text-foreground',
                    entry.level === 3 && 'pl-5',
                ]"
                @click.prevent="emit('navigate', entry.id)"
            >
                {{ entry.text }}
            </a>
        </nav>
    </details>

    <div v-else class="top-4 sticky max-h-[calc(100vh-2rem)] overflow-y-auto">
        <p
            class="mb-2 gap-1.5 px-2 text-xs font-semibold tracking-wide flex items-center text-muted-foreground uppercase"
        >
            <List class="size-3.5" />
            {{ t('kinetix.help_on_this_page') }}
        </p>
        <nav
            class="space-y-0.5 border-l border-border"
            :aria-label="t('kinetix.help_on_this_page')"
        >
            <a
                v-for="entry in entries"
                :key="entry.id"
                :href="`#${entry.id}`"
                :aria-current="activeId === entry.id ? 'location' : undefined"
                :class="[
                    'py-1 text-sm -ml-px block border-l-2 transition-colors',
                    entry.level === 3 ? 'pl-5' : 'pl-3',
                    activeId === entry.id
                        ? 'font-medium border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                ]"
                @click.prevent="emit('navigate', entry.id)"
            >
                {{ entry.text }}
            </a>
        </nav>
    </div>
</template>
