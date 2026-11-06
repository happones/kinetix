<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixComponentOf } from '@/composables/kinetixLazy';
import { firstErroredField } from '@/composables/useKinetixFormErrors';
import type {
    KinetixMemberFieldsForm,
    KinetixMemberProvision,
} from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixSelect from '../KinetixSelect.vue';
import KinetixModal from '../primitives/KinetixModal.vue';
import { KINETIX_LAZY_MEMBER_FIELDS } from './kinetixMemberLoaders';

/**
 * The Edit member dialog: the member's role plus the custom fields the app
 * describes (`membership.fields`). Presentational: the list fetches `form`
 * (null while it loads, shown as a busy skeleton), owns the request and feeds
 * back `errors` (by field name) and `submitting`; this emits `submit(role,
 * fields)`. A member whose current role is no longer assignable keeps it
 * listed, locked, so their fields can still be saved.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        member: KinetixMemberProvision | null;
        /** value → label, the current role included. */
        roleOptions: Record<string, string>;
        /** Roles listed but not selectable. */
        lockedRoles?: string[];
        /** The custom-field form with the member's current values; null while loading. */
        form: KinetixMemberFieldsForm | null;
        errors?: Record<string, string>;
        submitting?: boolean;
    }>(),
    { lockedRoles: () => [], errors: () => ({}), submitting: false },
);

const emit = defineEmits<{
    'update:open': [open: boolean];
    submit: [role: string, fields: Record<string, unknown>];
}>();

const { t } = useI18n();

const MemberFields = kinetixComponentOf(KINETIX_LAZY_MEMBER_FIELDS);

const role = ref(props.member?.role ?? '');
const fieldValues = ref<Record<string, unknown>>({
    ...(props.form?.data ?? {}),
});

watch(
    () => props.member,
    (member) => (role.value = member?.role ?? ''),
);
watch(
    () => props.form,
    (form) => (fieldValues.value = { ...(form?.data ?? {}) }),
);

// A rejected save points at the first field with an error.
watch(
    () => props.errors,
    (errors) => {
        const keys = Object.keys(errors);

        if (props.form && keys.length > 0) {
            fieldsRef.value?.focus?.(
                firstErroredField(props.form.schema as any[], keys),
            );
        }
    },
);

const memberName = computed(
    () => props.member?.name ?? props.member?.identifier ?? '',
);

const formId = 'kinetix-member-edit-form';
const fieldsRef = ref<{ focus?: (name: string | null) => void } | null>(null);

function submit(): void {
    if (props.form && !props.submitting) {
        emit('submit', role.value, fieldValues.value);
    }
}
</script>

<template>
    <KinetixModal
        :open="open"
        :title="t('kinetix.member_edit')"
        :description="
            t('kinetix.member_edit_description', { name: memberName })
        "
        :processing="submitting"
        placement="top"
        @update:open="emit('update:open', $event)"
    >
        <form :id="formId" class="space-y-4" @submit.prevent="submit">
            <div class="space-y-2">
                <KinetixLabel for="kinetix-member-edit-role">
                    {{ t('kinetix.member_role') }}
                </KinetixLabel>
                <KinetixSelect
                    id="kinetix-member-edit-role"
                    :value="role"
                    :options="roleOptions"
                    :disabled-keys="lockedRoles"
                    :disabled="submitting"
                    :placeholder="t('kinetix.member_role')"
                    @update:value="role = $event"
                />
            </div>

            <MemberFields
                v-if="form"
                ref="fieldsRef"
                v-model="fieldValues"
                :form="form"
                :errors="errors"
            />
            <div
                v-else
                class="space-y-4"
                aria-busy="true"
                data-test="member-edit-skeleton"
            >
                <div
                    class="h-9 animate-pulse rounded-md bg-muted"
                    aria-hidden="true"
                />
                <div
                    class="h-9 animate-pulse rounded-md bg-muted"
                    aria-hidden="true"
                />
                <span class="sr-only">{{ t('kinetix.member_details') }}</span>
            </div>
        </form>

        <template #footer>
            <KinetixButton
                variant="outline"
                :disabled="submitting"
                @click="emit('update:open', false)"
            >
                {{ t('kinetix.cancel') }}
            </KinetixButton>
            <KinetixButton
                type="submit"
                :form="formId"
                :loading="submitting"
                :disabled="!form"
            >
                {{ t('kinetix.save') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
