<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { roleLabel } from '@/composables/useKinetixMembers';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixButton from './KinetixButton.vue';
import KinetixLabel from './KinetixLabel.vue';
import KinetixSelect from './KinetixSelect.vue';

/**
 * The provisioning form — substitute for the starter-kit's InviteMemberModal.
 * Presentational: it collects an identifier + role (constrained to the
 * server-enforced allow-list) and emits `submit`. Gate it behind
 * `members.provision` where you mount it.
 *
 * `identifierType` mirrors `membership.identifier` on the server — staff with
 * no email address are provisioned by username or phone instead.
 */
const props = withDefaults(
    defineProps<{
        assignableRoles: string[];
        /** Which field identifies a member: mirrors `membership.identifier`. */
        identifierType?: 'email' | 'username' | 'phone';
        /** True while the parent's provision request is in flight. */
        submitting?: boolean;
    }>(),
    { identifierType: 'email', submitting: false },
);

const emit = defineEmits<{
    submit: [identifier: string, role: string];
}>();

const { t } = useI18n();

const identifier = ref('');
const role = ref(props.assignableRoles[0] ?? '');

// The allow-list arrives async (the parent loads it after mount), so default
// the role once it lands — otherwise the select opens empty and a submit with
// only the identifier filled is a silent no-op.
watch(
    () => props.assignableRoles,
    (roles) => {
        if (!role.value && roles.length > 0) {
            role.value = roles[0];
        }
    },
);

/** KinetixSelect expects a `{ value: label }` record. */
const roleOptions = computed<Record<string, string>>(() =>
    Object.fromEntries(props.assignableRoles.map((r) => [r, roleLabel(r)])),
);

/** `type="email"` gives the right keyboard and browser validation; a username is plain text. */
const inputType = computed(() =>
    props.identifierType === 'email'
        ? 'email'
        : props.identifierType === 'phone'
          ? 'tel'
          : 'text',
);

const identifierLabel = computed(() =>
    t(`kinetix.member_${props.identifierType}`),
);

function submit(): void {
    if (!identifier.value || !role.value) {
        return;
    }

    emit('submit', identifier.value, role.value);
    identifier.value = '';
    role.value = props.assignableRoles[0] ?? '';
}
</script>

<template>
    <!-- items-end keeps the inputs and the (label-less) submit button bottom-aligned;
       every control is h-9 so tops align too. -->
    <form
        class="gap-3 sm:flex-row sm:items-end flex flex-col"
        @submit.prevent="submit"
    >
        <div class="space-y-2 flex-1">
            <KinetixLabel for="kinetix-member-email">
                {{ identifierLabel }}
            </KinetixLabel>
            <input
                id="kinetix-member-email"
                v-model="identifier"
                :type="inputType"
                required
                :class="inputClass"
                placeholder="name@example.com"
            />
        </div>

        <div class="space-y-2 sm:w-44">
            <KinetixLabel for="kinetix-member-role">
                {{ t('kinetix.member_role') }}
            </KinetixLabel>
            <KinetixSelect
                id="kinetix-member-role"
                :value="role"
                :options="roleOptions"
                :placeholder="t('kinetix.member_role')"
                @update:value="role = $event"
            />
        </div>

        <KinetixButton type="submit" :loading="submitting">
            {{ t('kinetix.member_provision') }}
        </KinetixButton>
    </form>
</template>
