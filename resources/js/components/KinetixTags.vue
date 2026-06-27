<script setup lang="ts">
import { X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixTags } from '@/composables/useKinetixTags';

/**
 * Drop-in polymorphic tag editor for a taggable model. Shows the current tags as
 * removable chips, autocompletes from existing tags, and creates new ones on
 * Enter. Every change is synced to the server, which returns the canonical set.
 */
const props = defineProps<{
    taggableType: string;
    taggableId: number | string;
}>();

const { t } = useI18n();
const { tags, load, suggest, sync } = useKinetixTags(
    props.taggableType,
    props.taggableId,
);

onMounted(load);

const input = ref('');
const suggestions = ref<string[]>([]);
const open = ref(false);
let debounce: ReturnType<typeof setTimeout> | undefined;

onBeforeUnmount(() => clearTimeout(debounce));

function onInput(): void {
    clearTimeout(debounce);
    const q = input.value.trim();

    if (q === '') {
        suggestions.value = [];
        open.value = false;

        return;
    }

    debounce = setTimeout(async () => {
        const found = await suggest(q);
        suggestions.value = found.filter((s) => !tags.value.includes(s));
        open.value = suggestions.value.length > 0;
    }, 200);
}

async function add(name: string): Promise<void> {
    const value = name.trim();

    if (value === '' || tags.value.includes(value)) {
        input.value = '';

        return;
    }

    input.value = '';
    suggestions.value = [];
    open.value = false;
    await sync([...tags.value, value]);
}

async function removeAt(index: number): Promise<void> {
    const next = tags.value.filter((_, i) => i !== index);
    await sync(next);
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
                    class="text-muted-foreground hover:text-foreground"
                    :aria-label="t('kinetix.tag_remove')"
                    @click="removeAt(i)"
                >
                    <X class="size-3" />
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
