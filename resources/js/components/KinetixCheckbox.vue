<script setup lang="ts">
import { Check } from "@lucide/vue";
import { CheckboxIndicator, CheckboxRoot } from "reka-ui";
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

const isChecked = computed(() =>
  props.checked !== undefined ? props.checked : props.modelValue,
);

const onUpdate = (value: boolean | "indeterminate") => {
  const next = value === true;
  emit("update:modelValue", next);
  emit("change", next);
};
</script>

<template>
  <CheckboxRoot
    :id="id"
    :model-value="isChecked"
    :disabled="disabled"
    class="peer flex size-4 shrink-0 items-center justify-center rounded-[4px] border border-input shadow-xs transition-shadow outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 dark:bg-input/30 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground dark:data-[state=checked]:bg-primary"
    @update:model-value="onUpdate"
  >
    <CheckboxIndicator class="flex items-center justify-center text-current transition-none">
      <Check class="size-3.5" />
    </CheckboxIndicator>
  </CheckboxRoot>
</template>
