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
    perPage: number;
    hasMore: boolean;
    /** Null in cursor mode: a cursor has no page number. */
    currentPage: number | null;
    /** Null when the table is simple- or cursor-paginated (no COUNT(*)). */
    total: number | null;
    lastPage: number | null;
    from: number | null;
    to: number | null;
    nextCursor?: string | null;
    prevCursor?: string | null;
    onFirstPage?: boolean | null;
}

const props = defineProps<{
    pagination: PaginationData;
    paginationPageOptions: number[];
}>();

const emit = defineEmits<{
    (e: 'change-page', page: number): void;
    /** Cursor mode: null rewinds to the first page. */
    (e: 'change-cursor', cursor: string | null): void;
    (e: 'change-per-page', perPage: number): void;
}>();

const { t } = useI18n();

/**
 * Cursor mode navigates by opaque seek positions: no page number, no offsets,
 * no total. Simple mode keeps page numbers but has no total either. In both
 * cases the footer omits what the server deliberately did not compute rather
 * than rendering placeholders.
 */
const isCursor = computed(() => props.pagination.currentPage === null);

const isSimple = computed(
    () => !isCursor.value && props.pagination.lastPage === null,
);

/** True in both count-free modes: no first/last jumps, no total. */
const isCountFree = computed(() => isCursor.value || isSimple.value);

const isFirstPage = computed(() =>
    isCursor.value
        ? (props.pagination.onFirstPage ?? !props.pagination.prevCursor)
        : (props.pagination.currentPage ?? 1) <= 1,
);

const isLastPage = computed(() =>
    isCountFree.value
        ? !props.pagination.hasMore
        : props.pagination.currentPage === props.pagination.lastPage,
);

const goPrevious = (): void => {
    if (isCursor.value) {
        emit('change-cursor', props.pagination.prevCursor ?? null);

        return;
    }

    emit('change-page', (props.pagination.currentPage ?? 1) - 1);
};

/** Only rendered when a last page is known, so the fallbacks never fire. */
const goLast = (): void => {
    emit(
        'change-page',
        props.pagination.lastPage ?? props.pagination.currentPage ?? 1,
    );
};

const goNext = (): void => {
    if (isCursor.value) {
        emit('change-cursor', props.pagination.nextCursor ?? null);

        return;
    }

    emit('change-page', (props.pagination.currentPage ?? 1) + 1);
};

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
            <span v-if="isCursor">&nbsp;</span>
            <span v-else-if="isSimple && pagination.from !== null">
                {{
                    t('kinetix.showing_range', {
                        from: pagination.from,
                        to: pagination.to,
                    })
                }}
            </span>
            <span v-else-if="!isCountFree && (pagination.total ?? 0) > 0">
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
            <nav
                :aria-label="t('kinetix.pagination')"
                class="gap-1 flex items-center"
            >
                <button
                    v-if="!isCountFree"
                    type="button"
                    data-testid="page-first"
                    :aria-label="t('kinetix.first_page')"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="isFirstPage"
                    @click="emit('change-page', 1)"
                >
                    <ChevronsLeft class="h-4 w-4" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    data-testid="page-prev"
                    :aria-label="t('kinetix.previous_page')"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="isFirstPage"
                    @click="goPrevious"
                >
                    <ChevronLeft class="h-4 w-4" aria-hidden="true" />
                </button>
                <span
                    class="text-xs font-medium mx-2 whitespace-nowrap text-muted-foreground"
                >
                    <!-- A cursor has no page number to show. -->
                    <template v-if="isCursor">&nbsp;</template>
                    <template v-else-if="isSimple">
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
                    :aria-label="t('kinetix.next_page')"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="isLastPage"
                    @click="goNext"
                >
                    <ChevronRight class="h-4 w-4" aria-hidden="true" />
                </button>
                <button
                    v-if="!isCountFree"
                    type="button"
                    data-testid="page-last"
                    :aria-label="t('kinetix.last_page')"
                    class="text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs size-8 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-muted-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    :disabled="isLastPage"
                    @click="goLast"
                >
                    <ChevronsRight class="h-4 w-4" aria-hidden="true" />
                </button>
            </nav>

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
