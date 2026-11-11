<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { clearKinetixSearchCache } from '@/composables/kinetixSearchCache';
import { kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import {
    useKinetixSearch,
    useKinetixSearchAnnouncer,
} from '@/composables/useKinetixSearch';
import { tagRemoveButtonClass } from '@/composables/useKinetixShadcnVariants';
import { useKinetixTags } from '@/composables/useKinetixTags';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * Drop-in polymorphic tag editor for a taggable model. Shows the current tags as
 * removable chips, autocompletes from existing tags, and creates new ones on
 * Enter. Every change is synced to the server, which returns the canonical set.
 * Pass the model's sealed `reference` (`KinetixTags::reference($model)`), or
 * the legacy morph type + id.
 */
const props = defineProps<{
    /** Sealed target from the server; when set, type + id are not sent. */
    reference?: string;
    taggableType?: string;
    taggableId?: number | string;
}>();

const { t } = useI18n();
const { tags, load, suggest, sync } = useKinetixTags(
    props.taggableType,
    props.taggableId,
    () => props.reference,
);

/** A refused request says why; the chips keep the last set the server sent. */
function refused(e: unknown, fallback: string): void {
    toast.error(e instanceof Error && e.message ? e.message : t(fallback));
}

onMounted(async () => {
    try {
        await load();
    } catch (e) {
        refused(e, 'kinetix.action_failed');
    }
});

const page = usePage<KinetixSharedProps>();
const input = ref('');
const open = ref(false);

// Suggestions are filed under the route prefix (team segment) and the target;
// a sync can create a tag, so it drops them.
const suggestNamespace = (): string =>
    `${kinetixRoutePrefix(page)}:tags.suggest`;

const searcher = useKinetixSearch<string[]>({
    fetcher: async (query, { signal }) => ({
        value: await suggest(query, { signal }),
    }),
    cacheKey: () =>
        `${suggestNamespace()}:${props.reference ?? `${props.taggableType}:${props.taggableId}`}`,
    onSettled: useKinetixSearchAnnouncer<string[]>({
        count: (list) => list.length,
    }),
});

// Autocomplete is a convenience: a failed lookup just offers nothing.
const suggestions = computed<string[]>(() =>
    (searcher.results.value ?? []).filter((s) => !tags.value.includes(s)),
);

watch(searcher.results, () => {
    open.value = suggestions.value.length > 0;
});

onBeforeUnmount(() => searcher.cancel());

function closeSuggestions(): void {
    searcher.reset();
    open.value = false;
}

function onInput(): void {
    const q = input.value.trim();

    if (q === '') {
        closeSuggestions();

        return;
    }

    void searcher.search(q);
}

async function add(name: string): Promise<void> {
    const value = name.trim();

    if (value === '' || tags.value.includes(value)) {
        input.value = '';

        return;
    }

    input.value = '';
    closeSuggestions();

    try {
        await sync([...tags.value, value]);
        clearKinetixSearchCache(suggestNamespace());
    } catch (e) {
        // Not saved: hand the typed tag back so it isn't lost.
        input.value = value;
        refused(e, 'kinetix.save_failed');
    }
}

async function removeAt(index: number): Promise<void> {
    const next = tags.value.filter((_, i) => i !== index);

    try {
        await sync(next);
        clearKinetixSearchCache(suggestNamespace());
    } catch (e) {
        refused(e, 'kinetix.save_failed');
    }
}

function onBackspace(): void {
    if (input.value === '' && tags.value.length > 0) {
        removeAt(tags.value.length - 1);
    }
}
</script>

<template>
    <div class="relative">
        <div
            class="gap-1.5 p-2 shadow-xs flex flex-wrap items-center rounded-md border border-input bg-transparent transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
        >
            <span
                v-for="(tag, i) in tags"
                :key="tag"
                class="gap-1 px-2 py-0.5 text-xs font-medium inline-flex items-center rounded-md bg-secondary text-secondary-foreground"
            >
                {{ tag }}
                <button
                    type="button"
                    :class="tagRemoveButtonClass"
                    :aria-label="`${t('kinetix.tag_remove')}: ${tag}`"
                    @click="removeAt(i)"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </span>

            <input
                v-model="input"
                type="text"
                class="text-sm min-w-[8rem] flex-1 bg-transparent text-foreground outline-none"
                :placeholder="t('kinetix.tag_placeholder')"
                @input="onInput"
                @keydown.enter.prevent="add(input)"
                @keydown.delete="onBackspace"
                @focus="onInput"
            />
        </div>

        <ul
            v-if="open"
            class="mt-1 max-h-48 p-1 shadow-md absolute z-50 w-full overflow-y-auto rounded-md border border-border bg-popover"
            :aria-busy="searcher.loading.value"
        >
            <li
                v-for="s in suggestions"
                :key="s"
                class="rounded-sm px-2 py-1.5 text-sm cursor-default text-foreground hover:bg-accent hover:text-accent-foreground"
                @mousedown.prevent="add(s)"
            >
                {{ s }}
            </li>
        </ul>
    </div>
</template>
