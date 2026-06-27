<script setup lang="ts">
import { ref, watch } from 'vue';
import { buttonVariants } from '@/composables/useShadcnVariants';
import KinetixFormSchema from './KinetixFormSchema.vue';

const props = defineProps<{
    form: {
        schema: any[];
        data: Record<string, any>;
        rules: Record<string, any>;
        operation: string;
    };
}>();

const emit = defineEmits<{
    (e: 'submit', values: Record<string, any>): void;
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
    emit('submit', formValues.value);
};
</script>

<template>
    <form @submit="onSubmit" class="space-y-6">
        <div class="gap-4 grid grid-cols-12">
            <KinetixFormSchema
                :schema="form.schema"
                :values="formValues"
                :errors="formErrors"
                @update:value="(name, val) => (formValues[name] = val)"
            />
        </div>

        <!-- Actions Slot -->
        <div class="gap-3 mt-4 flex justify-end">
            <slot :values="formValues">
                <button type="submit" :class="buttonVariants()">Submit</button>
            </slot>
        </div>
    </form>
</template>
