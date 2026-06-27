<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import KinetixSelect from '../KinetixSelect.vue';

interface PaginationData {
    currentPage: number;
    lastPage: number;
    from: number | null;
    to: number | null;
    total: number;
    perPage: number;
}

defineProps<{
    pagination: PaginationData;
    paginationPageOptions: number[];
}>();

const emit = defineEmits<{
    (e: 'change-page', page: number): void;
    (e: 'change-per-page', perPage: number): void;
}>();

const { t } = useI18n();

const getPerPageOptions = (options?: number[]) => {
    const record: Record<string, string> = {};

    if (options) {
        options.forEach((opt) => {
            record[String(opt)] = String(opt);
        });
    }

    return record;
};
</script>

<template>
    <div
        class="sm:flex-row gap-4 px-6 py-4 flex flex-col items-center justify-between border-t border-border bg-muted/20"
    >
        <div class="text-xs font-medium text-muted-foreground">
            <span v-if="pagination.total > 0">
                {{
                    t('kinetix.showing_records', {
                        from: pagination.from,
                        to: pagination.to,
                        total: pagination.total,
                    })
                }}
            </span>
            <span v-else>{{ t('kinetix.no_records') }}</span>
        </div>

        <div class="gap-4 flex items-center">
            <div class="gap-1 flex items-center">
                <button
                    type="button"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="pagination.currentPage === 1"
                    @click="emit('change-page', 1)"
                >
                    <ChevronsLeft class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="pagination.currentPage === 1"
                    @click="emit('change-page', pagination.currentPage - 1)"
                >
                    <ChevronLeft class="h-4 w-4" />
                </button>
                <span class="text-xs font-medium mx-2 text-muted-foreground">
                    {{
                        t('kinetix.page_of', {
                            current: pagination.currentPage,
                            total: pagination.lastPage,
                        })
                    }}
                </span>
                <button
                    type="button"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="pagination.currentPage === pagination.lastPage"
                    @click="emit('change-page', pagination.currentPage + 1)"
                >
                    <ChevronRight class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="pagination.currentPage === pagination.lastPage"
                    @click="emit('change-page', pagination.lastPage)"
                >
                    <ChevronsRight class="h-4 w-4" />
                </button>
            </div>

            <!-- Page Size selector -->
            <div class="gap-2 text-xs flex items-center text-muted-foreground">
                <span>{{ t('kinetix.per_page') }}</span>
                <div class="w-16">
                    <KinetixSelect
                        :value="pagination.perPage"
                        :options="getPerPageOptions(paginationPageOptions)"
                        @update:value="emit('change-per-page', Number($event))"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
