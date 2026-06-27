<script setup lang="ts">
import { X } from '@lucide/vue';
import { ref } from 'vue';

const props = withDefaults(
    defineProps<{
        value?: string[] | null;
        disabled?: boolean;
        placeholder?: string | null;
    }>(),
    {
        value: () => [],
        disabled: false,
        placeholder: null,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: string[]): void;
}>();

const draft = ref('');

const tags = () => (Array.isArray(props.value) ? props.value : []);

const addTag = () => {
    const candidate = draft.value.trim();
    draft.value = '';

    if (!candidate || tags().includes(candidate)) {
        return;
    }

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
    <div
        class="min-h-9 gap-1.5 px-2 py-1 text-sm shadow-xs flex w-full flex-wrap items-center rounded-md border border-input bg-background transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
        :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
    >
        <span
            v-for="tag in tags()"
            :key="tag"
            class="gap-1 rounded px-2 py-0.5 text-xs font-medium inline-flex items-center bg-muted text-foreground"
        >
            {{ tag }}
            <button
                v-if="!disabled"
                type="button"
                class="text-muted-foreground hover:text-foreground"
                @click="removeTag(tag)"
            >
                <X class="h-3 w-3" />
            </button>
        </span>

        <input
            v-model="draft"
            type="text"
            :disabled="disabled"
            :placeholder="placeholder ?? ''"
            class="px-1 py-0.5 text-sm min-w-[8rem] flex-1 border-0 bg-transparent outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
            @keydown="onKeydown"
            @blur="addTag"
        />
    </div>
</template>
