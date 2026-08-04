<script setup lang="ts">
/**
 * Mirror of the `make-resource` Create.vue scaffold (gallery import paths),
 * so the generated page's layout is visually verifiable here. Keep in sync
 * with the template in MakeResourceCommand.php.
 */
import { ref } from 'vue';
import KinetixForm from '@/components/KinetixForm.vue';
import KinetixPageHeader from '@/components/KinetixPageHeader.vue';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';

defineProps<{ form: any }>();

const saving = ref(false);

const handleSubmit = () => {};
const handleCancel = () => {};
</script>

<template>
    <div class="max-w-3xl space-y-6 p-4 sm:p-6 lg:p-8 mx-auto w-full">
        <KinetixPageHeader
            heading="Create Post"
            description="Add a new Post record."
        />

        <!-- The form's own Section provides the card surface — no extra
             wrapper, so schemas never render card-inside-card. -->
        <KinetixForm :form="form" @submit="handleSubmit">
            <template #default>
                <div
                    class="gap-3 sm:flex-row sm:justify-end flex w-full flex-col-reverse"
                >
                    <button
                        type="button"
                        :class="buttonVariants({ variant: 'outline' })"
                        class="sm:w-auto w-full"
                        :disabled="saving"
                        @click="handleCancel"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        :class="buttonVariants()"
                        class="sm:w-auto w-full"
                        :disabled="saving"
                    >
                        {{ saving ? 'Creating…' : 'Create Post' }}
                    </button>
                </div>
            </template>
        </KinetixForm>
    </div>
</template>
