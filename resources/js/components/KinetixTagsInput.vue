<script setup lang="ts">
import { X } from "@lucide/vue";
import { ref } from "vue";

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
  (e: "update:value", value: string[]): void;
}>();

const draft = ref("");

const tags = () => (Array.isArray(props.value) ? props.value : []);

const addTag = () => {
  const candidate = draft.value.trim();
  draft.value = "";

  if (!candidate || tags().includes(candidate)) {
    return;
  }

  emit("update:value", [...tags(), candidate]);
};

const removeTag = (tag: string) => {
  emit(
    "update:value",
    tags().filter((t) => t !== tag),
  );
};

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === "Enter" || event.key === ",") {
    event.preventDefault();
    addTag();

    return;
  }

  if (event.key === "Backspace" && draft.value === "" && tags().length > 0) {
    emit("update:value", tags().slice(0, -1));
  }
};
</script>

<template>
  <div
    class="flex min-h-9 w-full flex-wrap items-center gap-1.5 rounded-md border border-input bg-background px-2 py-1 text-sm shadow-sm focus-within:ring-1 focus-within:ring-ring"
    :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
  >
    <span
      v-for="tag in tags()"
      :key="tag"
      class="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-xs font-medium text-foreground"
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
      class="flex-1 min-w-[8rem] border-0 bg-transparent px-1 py-0.5 text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
      @keydown="onKeydown"
      @blur="addTag"
    />
  </div>
</template>
