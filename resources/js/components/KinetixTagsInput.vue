<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { tagRemoveButtonClass } from '@/composables/useKinetixShadcnVariants';

const props = withDefaults(
    defineProps<{
        /** The field's id: what its label points at. */
        id?: string;
        value?: string[] | null;
        disabled?: boolean;
        placeholder?: string | null;
        /**
         * `distinct:ignore_case` matches tags regardless of case; `max:`
         * on the list caps how many can be added.
         */
        ignoreCase?: boolean;
        maxItems?: number | null;
    }>(),
    {
        value: () => [],
        disabled: false,
        placeholder: null,
        ignoreCase: false,
        maxItems: null,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: string[]): void;
}>();

const { t } = useI18n();
const draft = ref('');
// The tag a repeat pointed at: highlighted, and said, instead of silently
// swallowing what was typed.
const repeated = ref<string | null>(null);

const tags = () => (Array.isArray(props.value) ? props.value : []);

const full = computed(
    () => props.maxItems !== null && tags().length >= props.maxItems,
);

const same = (a: string, b: string): boolean =>
    props.ignoreCase ? a.toLowerCase() === b.toLowerCase() : a === b;

const addTag = () => {
    const candidate = draft.value.trim();

    if (!candidate) {
        return;
    }

    const existing = tags().find((tag) => same(tag, candidate));

    if (existing !== undefined) {
        repeated.value = existing;

        return;
    }

    if (full.value) {
        return;
    }

    draft.value = '';
    repeated.value = null;
    emit('update:value', [...tags(), candidate]);
};

const removeTag = (tag: string) => {
    emit(
        'update:value',
        tags().filter((t) => t !== tag),
    );
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        addTag();

        return;
    }

    if (event.key === 'Backspace' && draft.value === '' && tags().length > 0) {
        emit('update:value', tags().slice(0, -1));
    }
};
</script>

<template>
    <div>
        <div
            class="min-h-9 gap-1.5 px-2 py-1 text-sm shadow-xs flex w-full flex-wrap items-center rounded-md border border-input bg-background transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
            :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
        >
            <span
                v-for="tag in tags()"
                :key="tag"
                class="gap-1 rounded px-2 py-0.5 text-xs font-medium inline-flex items-center bg-muted text-foreground transition-shadow"
                :class="repeated === tag ? 'ring-2 ring-ring' : ''"
            >
                {{ tag }}
                <button
                    v-if="!disabled"
                    type="button"
                    :class="tagRemoveButtonClass"
                    :aria-label="`${t('kinetix.tag_remove')}: ${tag}`"
                    @click="removeTag(tag)"
                >
                    <X class="size-3" aria-hidden="true" />
                </button>
            </span>

            <input
                :id="id"
                v-model="draft"
                type="text"
                :disabled="disabled || full"
                :placeholder="placeholder ?? ''"
                class="px-1 py-0.5 text-sm min-w-[8rem] flex-1 border-0 bg-transparent outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
                @keydown="onKeydown"
                @input="repeated = null"
                @blur="addTag"
            />
        </div>
        <p
            v-if="repeated !== null"
            class="mt-1 text-xs text-muted-foreground"
            role="status"
        >
            {{ t('kinetix.tag_already_added', { tag: repeated }) }}
        </p>
    </div>
</template>
