<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixActivity } from '@/composables/useKinetixActivity';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixActivityEntry } from '@/types/kinetix';

/**
 * A team-scoped activity timeline. Drop it in globally, or scope it to one record
 * (per feature) by passing `subject-type` + `subject-id` — e.g. on a product's
 * show page to see that product's change history. Self-loading and paginated
 * ("load more"); descriptions are composed from i18n so they translate.
 */
const props = defineProps<{
    subjectType?: string;
    subjectId?: string | number;
    event?: string;
}>();

const { t } = useI18n();
const { loading, load } = useKinetixActivity();

const entries = ref<KinetixActivityEntry[]>([]);
const currentPage = ref(0);
const lastPage = ref(1);

const hasMore = computed(() => currentPage.value < lastPage.value);

async function fetchPage(page: number): Promise<void> {
    const result = await load({
        subject_type: props.subjectType ?? '',
        subject_id: props.subjectId ?? '',
        event: props.event ?? '',
        page,
    });

    if (!result) {
        return;
    }

    entries.value =
        page === 1 ? result.data : [...entries.value, ...result.data];
    currentPage.value = result.pagination.current_page;
    lastPage.value = result.pagination.last_page;
}

onMounted(() => fetchPage(1));

function eventLabel(event: string): string {
    const key = `kinetix.activity_event_${event}`;
    const label = t(key);

    // Unknown/custom events fall back to the raw event name.
    return label === key ? event : label;
}

/** Longest value a change line shows; the full one is in its tooltip. */
const MAX_VALUE = 80;

/**
 * A value as a change line says it: empty as a dash, and a structure (or the
 * JSON text a JSON column logs) re-serialized compactly — printed as logged it
 * came with every `/` escaped (`products\/gallery\/…`).
 */
function display(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'string' && /^\s*[[{]/.test(value)) {
        try {
            return JSON.stringify(JSON.parse(value));
        } catch {
            return value;
        }
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

const shorten = (text: string): string =>
    text.length > MAX_VALUE ? `${text.slice(0, MAX_VALUE - 1)}…` : text;

/** Changed fields for an "updated" entry: `field: old → new`. */
function changeList(
    entry: KinetixActivityEntry,
): { line: string; full: string }[] {
    return Object.keys(entry.changes.attributes).map((field) => {
        const next = display(entry.changes.attributes[field]);
        const prev = entry.changes.old[field];

        if (prev === undefined) {
            return {
                line: `${field}: ${shorten(next)}`,
                full: `${field}: ${next}`,
            };
        }

        const before = display(prev);

        return {
            line: `${field}: ${shorten(before)} → ${shorten(next)}`,
            full: `${field}: ${before} → ${next}`,
        };
    });
}
</script>

<template>
    <div class="space-y-4">
        <!-- Skeleton on first load -->
        <div v-if="loading && entries.length === 0" class="space-y-3">
            <div
                v-for="n in 3"
                :key="n"
                class="h-12 animate-pulse w-full rounded-md bg-muted"
            />
        </div>

        <p
            v-else-if="entries.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ t('kinetix.activity_empty') }}
        </p>

        <ol v-else class="space-y-3">
            <li
                v-for="entry in entries"
                :key="String(entry.id)"
                class="rounded-lg p-3 border border-border bg-card"
            >
                <div class="gap-2 flex items-baseline justify-between">
                    <span class="text-sm font-medium text-foreground">
                        {{ eventLabel(entry.event) }}
                        <span class="font-normal text-muted-foreground">
                            {{
                                entry.causerName
                                    ? t('kinetix.activity_by', {
                                          name: entry.causerName,
                                      })
                                    : t('kinetix.activity_system')
                            }}
                        </span>
                    </span>
                    <time
                        v-if="entry.createdAt"
                        :datetime="entry.createdAt"
                        class="text-xs shrink-0 text-muted-foreground"
                    >
                        {{ new Date(entry.createdAt).toLocaleString() }}
                    </time>
                </div>

                <ul
                    v-if="changeList(entry).length"
                    class="mt-2 space-y-0.5 text-xs text-muted-foreground"
                >
                    <li
                        v-for="(change, i) in changeList(entry)"
                        :key="i"
                        class="font-mono [overflow-wrap:anywhere]"
                        :title="
                            change.full !== change.line
                                ? change.full
                                : undefined
                        "
                    >
                        {{ change.line }}
                    </li>
                </ul>
            </li>
        </ol>

        <div v-if="hasMore" class="flex justify-center">
            <button
                type="button"
                :disabled="loading"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                @click="fetchPage(currentPage + 1)"
            >
                {{ t('kinetix.activity_load_more') }}
            </button>
        </div>
    </div>
</template>
