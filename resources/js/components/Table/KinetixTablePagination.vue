<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import KinetixSelect from '../KinetixSelect.vue';

interface PaginationData {
    currentPage: number;
    perPage: number;
    hasMore: boolean;
    /** Null when the table is simple-paginated (no COUNT(*) is run). */
    total: number | null;
    /** Null when the table is simple-paginated. */
    lastPage: number | null;
    from: number | null;
    to: number | null;
}

const props = defineProps<{
    pagination: PaginationData;
    paginationPageOptions: number[];
}>();

const emit = defineEmits<{
    (e: 'change-page', page: number): void;
    (e: 'change-per-page', perPage: number): void;
}>();

const { t } = useI18n();

/**
 * Simple pagination has no total and no last page, so the footer drops the
 * "showing x–y of N" line and the first/last jumps rather than rendering
 * placeholders for numbers the server deliberately did not compute.
 */
const isSimple = computed(
    () => props.pagination.total === null || props.pagination.lastPage === null,
);

const isFirstPage = computed(() => props.pagination.currentPage <= 1);

const isLastPage = computed(() =>
    isSimple.value
        ? !props.pagination.hasMore
        : props.pagination.currentPage === props.pagination.lastPage,
);

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
            <span v-if="isSimple && pagination.from !== null">
                {{
                    t('kinetix.showing_range', {
                        from: pagination.from,
                        to: pagination.to,
                    })
                }}
            </span>
            <span v-else-if="!isSimple && (pagination.total ?? 0) > 0">
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
                    v-if="!isSimple"
                    type="button"
                    data-testid="page-first"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="isFirstPage"
                    @click="emit('change-page', 1)"
                >
                    <ChevronsLeft class="h-4 w-4" />
                </button>
                <button
                    type="button"
                    data-testid="page-prev"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="isFirstPage"
                    @click="emit('change-page', pagination.currentPage - 1)"
                >
                    <ChevronLeft class="h-4 w-4" />
                </button>
                <span class="text-xs font-medium mx-2 text-muted-foreground">
                    <template v-if="isSimple">
                        {{
                            t('kinetix.page_number', {
                                current: pagination.currentPage,
                            })
                        }}
                    </template>
                    <template v-else>
                        {{
                            t('kinetix.page_of', {
                                current: pagination.currentPage,
                                total: pagination.lastPage,
                            })
                        }}
                    </template>
                </span>
                <button
                    type="button"
                    data-testid="page-next"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="isLastPage"
                    @click="emit('change-page', pagination.currentPage + 1)"
                >
                    <ChevronRight class="h-4 w-4" />
                </button>
                <button
                    v-if="!isSimple"
                    type="button"
                    data-testid="page-last"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="isLastPage"
                    @click="
                        emit(
                            'change-page',
                            pagination.lastPage ?? pagination.currentPage,
                        )
                    "
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
