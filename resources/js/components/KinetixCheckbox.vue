<script setup lang="ts">
import { Check } from "@lucide/vue";
import { computed } from "vue";

const props = withDefaults(
  defineProps<{
    id?: string;
    modelValue?: boolean;
    checked?: boolean;
    disabled?: boolean;
  }>(),
  {
    id: undefined,
    modelValue: false,
    checked: undefined,
    disabled: false,
  },
);

const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
  (e: "change", value: boolean): void;
}>();

const isChecked = computed(() => {
  if (props.checked !== undefined) {
    return props.checked;
  }

  return props.modelValue;
});

const toggle = () => {
  if (props.disabled) {
    return;
  }

  const newValue = !isChecked.value;
  emit("update:modelValue", newValue);
  emit("change", newValue);
};
</script>

<template>
  <button
    :id="id"
    type="button"
    role="checkbox"
    :aria-checked="isChecked"
    :disabled="disabled"
    class="peer h-4 w-4 shrink-0 rounded border border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-950 dark:focus-visible:ring-neutral-300 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-950 disabled:cursor-not-allowed disabled:opacity-50 transition-colors flex items-center justify-center cursor-pointer select-none"
    :class="[
      isChecked
        ? 'bg-neutral-900 border-neutral-900 text-white dark:bg-neutral-50 dark:border-neutral-50 dark:text-neutral-900'
        : 'bg-white dark:bg-neutral-900 text-transparent',
    ]"
    @click="toggle"
  >
    <Check v-if="isChecked" class="h-3 w-3 stroke-[3]" />
  </button>
</template>
