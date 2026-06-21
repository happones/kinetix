<script setup lang="ts">
import { ref, watch } from "vue";
import KinetixFormSchema from "./KinetixFormSchema.vue";

const props = defineProps<{
  form: {
    schema: any[];
    data: Record<string, any>;
    rules: Record<string, any>;
    operation: string;
  };
}>();

const emit = defineEmits<{
  (e: "submit", values: Record<string, any>): void;
}>();

const formValues = ref<Record<string, any>>({ ...props.form.data });
const formErrors = ref<Record<string, string>>({});

// Sync form values if data changes externally
watch(
  () => props.form.data,
  (newData) => {
    formValues.value = { ...newData };
  },
  { deep: true },
);

const onSubmit = (e: Event) => {
  e.preventDefault();
  formErrors.value = {};
  emit("submit", formValues.value);
};
</script>

<template>
  <form @submit="onSubmit" class="space-y-6">
    <div class="grid grid-cols-12 gap-4">
      <KinetixFormSchema
        :schema="form.schema"
        :values="formValues"
        :errors="formErrors"
        @update:value="(name, val) => (formValues[name] = val)"
      />
    </div>

    <!-- Actions Slot -->
    <div class="flex justify-end gap-3 mt-4">
      <slot :values="formValues">
        <button
          type="submit"
          class="inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2"
        >
          Submit
        </button>
      </slot>
    </div>
  </form>
</template>
