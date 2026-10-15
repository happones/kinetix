<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { KinetixDocsNavGroup } from '@/types/kinetix';

/**
 * A docs section's pages, by group: the sidebar on wide screens and the
 * "Menu" disclosure on narrow ones.
 */
defineProps<{
    groups: KinetixDocsNavGroup[];
    label: string;
}>();
</script>

<template>
    <nav :aria-label="label" class="space-y-5">
        <div v-for="group in groups" :key="group.title">
            <p
                v-if="group.title"
                class="mb-1.5 px-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ group.title }}
            </p>
            <ul class="space-y-0.5">
                <li v-for="page in group.pages" :key="page.url">
                    <Link
                        :href="page.url"
                        :aria-current="page.active ? 'page' : undefined"
                        :class="[
                            'px-2 py-1.5 text-sm block rounded-md transition-colors',
                            page.active
                                ? 'font-medium bg-accent text-foreground'
                                : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                        ]"
                    >
                        {{ page.title }}
                    </Link>
                </li>
            </ul>
        </div>
    </nav>
</template>
