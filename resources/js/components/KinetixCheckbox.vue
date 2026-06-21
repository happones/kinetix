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
    class="peer flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border border-input shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground"
    @update:model-value="onUpdate"
  >
    <CheckboxIndicator class="flex items-center justify-center text-current">
      <Check class="h-3 w-3 stroke-[3]" />
    </CheckboxIndicator>
  </CheckboxRoot>
</template>
