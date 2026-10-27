<script setup lang="ts">
import { Globe, ShieldCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { memberRoleLabel, roleLabel } from '@/composables/useKinetixMembers';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type { KinetixMemberRole } from '@/types/kinetix';
import KinetixButton from './KinetixButton.vue';
import KinetixLabel from './KinetixLabel.vue';
import KinetixSelect from './KinetixSelect.vue';

/**
 * The provisioning form — substitute for the starter-kit's InviteMemberModal.
 * Presentational: it collects an identifier, an optional name and a role
 * (constrained to the server-enforced allow-list) and emits `submit`. Gate it
 * behind `members.provision` where you mount it.
 *
 * `identifierType` mirrors `membership.identifier` on the server — staff with
 * no email address are provisioned by username or phone instead. `roles`
 * (optional) carries what each role means — the app's own label and
 * description for roles from its own logic, the permission count for
 * Roles-screen roles — shown under the picker so the person choosing knows
 * what a role grants; `reservedRoles` are named in a note as the owner's,
 * never offered here.
 */
const props = withDefaults(
    defineProps<{
        assignableRoles: string[];
        /** Which field identifies a member: mirrors `membership.identifier`. */
        identifierType?: 'email' | 'username' | 'phone';
        /** The assignable roles with their permission counts. */
        roles?: KinetixMemberRole[];
        /** Roles never assignable here (super-admin: the team owner's). */
        reservedRoles?: string[];
        /** True while the parent's provision request is in flight. */
        submitting?: boolean;
    }>(),
    {
        identifierType: 'email',
        roles: () => [],
        reservedRoles: () => [],
        submitting: false,
    },
);

const emit = defineEmits<{
    /** `name` is passed only when one was typed. */
    submit: [identifier: string, role: string, name?: string];
}>();

const { t } = useI18n();

const identifier = ref('');
const name = ref('');
const role = ref(props.assignableRoles[0] ?? '');

// The allow-list arrives async (the parent loads it after mount), so default
// the role once it lands — otherwise the select opens empty and a submit with
// only the identifier filled is a silent no-op. A role that left the list
// (deleted in the Roles screen meanwhile) falls back to the first one too.
watch(
    () => props.assignableRoles,
    (roles) => {
        if (!roles.includes(role.value)) {
            role.value = roles[0] ?? '';
        }
    },
);

/** KinetixSelect expects a `{ value: label }` record. */
const roleOptions = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.assignableRoles.map((r) => [r, memberRoleLabel(r, props.roles)]),
    ),
);

const selectedRole = computed<KinetixMemberRole | null>(
    () => props.roles.find((entry) => entry.name === role.value) ?? null,
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

const PLACEHOLDERS: Record<string, string> = {
    email: 'name@example.com',
    username: 'jdoe',
    phone: '+1 555 0100',
};

/** Reserved role names, as the rest of the UI labels them. */
const reservedLabel = computed(() =>
    props.reservedRoles.map((r) => roleLabel(r)).join(', '),
);

/**
 * No role this person may hand out: the form can't add anybody, so it says
 * why instead of a submit that silently did nothing.
 */
const noRoles = computed(() => props.assignableRoles.length === 0);

function submit(): void {
    const value = identifier.value.trim();

    if (!value || !role.value) {
        return;
    }

    const typedName = name.value.trim();

    if (typedName) {
        emit('submit', value, role.value, typedName);
    } else {
        emit('submit', value, role.value);
    }

    identifier.value = '';
    name.value = '';
    role.value = props.assignableRoles[0] ?? '';
}
</script>

<template>
    <!-- A container: the layout follows the FORM's width, not the viewport's.
       Stacked when narrow; two columns (identifier | name, role | submit)
       from @xl; one row from @4xl. A label on every cell keeps the inputs
       aligned; the submit cell gets an invisible one so its top aligns too. -->
    <form
        class="gap-3 @container flex flex-col"
        :aria-describedby="noRoles ? 'kinetix-member-no-roles' : undefined"
        @submit.prevent="submit"
    >
        <div
            class="gap-3 @xl:grid-cols-2 @4xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_12rem_auto] grid grid-cols-1 items-start"
        >
            <div class="space-y-2 min-w-0">
                <KinetixLabel for="kinetix-member-email">
                    {{ identifierLabel }}
                </KinetixLabel>
                <input
                    id="kinetix-member-email"
                    v-model="identifier"
                    :type="inputType"
                    required
                    autocomplete="off"
                    :class="inputClass"
                    :placeholder="PLACEHOLDERS[identifierType]"
                />
            </div>

            <div class="space-y-2 min-w-0">
                <KinetixLabel for="kinetix-member-name">
                    {{ t('kinetix.member_name_optional') }}
                </KinetixLabel>
                <input
                    id="kinetix-member-name"
                    v-model="name"
                    type="text"
                    maxlength="255"
                    autocomplete="off"
                    :class="inputClass"
                />
            </div>

            <div class="space-y-2 min-w-0">
                <KinetixLabel for="kinetix-member-role">
                    {{ t('kinetix.member_role') }}
                </KinetixLabel>
                <KinetixSelect
                    id="kinetix-member-role"
                    :value="role"
                    :options="roleOptions"
                    :disabled="noRoles"
                    :placeholder="t('kinetix.member_role')"
                    aria-describedby="kinetix-member-role-summary"
                    @update:value="role = $event"
                />
                <!-- What the chosen role grants, so nobody hands out a role
                     by its name alone. -->
                <p
                    id="kinetix-member-role-summary"
                    class="gap-1.5 text-xs min-h-4 flex flex-wrap items-center text-muted-foreground"
                    data-test="member-role-summary"
                >
                    <template v-if="selectedRole?.description">
                        {{ selectedRole.description }}
                    </template>
                    <template
                        v-else-if="
                            selectedRole && selectedRole.permissions !== null
                        "
                    >
                        {{
                            t(
                                'kinetix.member_role_permissions',
                                { count: selectedRole.permissions },
                                selectedRole.permissions,
                            )
                        }}
                        <span
                            v-if="selectedRole.global"
                            class="gap-1 inline-flex items-center"
                        >
                            ·
                            <Globe class="size-3" aria-hidden="true" />
                            {{ t('kinetix.role_global') }}
                        </span>
                    </template>
                </p>
            </div>

            <div class="space-y-2">
                <span
                    class="text-sm @xl:block hidden leading-none select-none"
                    aria-hidden="true"
                    >&nbsp;</span
                >
                <KinetixButton
                    type="submit"
                    class="@xl:w-auto w-full"
                    :loading="submitting"
                    :disabled="noRoles"
                >
                    {{ t('kinetix.member_provision') }}
                </KinetixButton>
            </div>
        </div>

        <p
            v-if="noRoles"
            id="kinetix-member-no-roles"
            class="text-sm text-muted-foreground"
        >
            {{ t('kinetix.member_no_assignable_roles') }}
        </p>

        <!-- The super-admin is the team owner's: say so where roles are
             chosen, so its absence from the picker isn't a mystery. -->
        <p
            v-if="reservedRoles.length > 0"
            class="gap-1.5 text-xs flex items-start text-muted-foreground"
            data-test="member-reserved-roles"
        >
            <ShieldCheck class="size-3.5 mt-px shrink-0" aria-hidden="true" />
            {{ t('kinetix.member_reserved_roles', { roles: reservedLabel }) }}
        </p>
    </form>
</template>
