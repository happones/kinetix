<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { buttonVariants, inputClass } from "@/composables/useShadcnVariants";
import KinetixLabel from "./KinetixLabel.vue";
import KinetixSelect from "./KinetixSelect.vue";

/**
 * The provisioning form — substitute for the starter-kit's InviteMemberModal.
 * Presentational: it only collects an email + role (constrained to the
 * server-enforced allow-list) and emits `submit`. Gate it behind
 * `members.provision` where you mount it.
 */
const props = defineProps<{
  assignableRoles: string[];
}>();

const emit = defineEmits<{
  submit: [email: string, role: string];
}>();

const { t } = useI18n();

const email = ref("");
const role = ref(props.assignableRoles[0] ?? "");

/** KinetixSelect expects a `{ value: label }` record. */
const roleOptions = computed<Record<string, string>>(() =>
  Object.fromEntries(props.assignableRoles.map((r) => [r, r])),
);

function submit(): void {
  if (!email.value || !role.value) {
    return;
  }

  emit("submit", email.value, role.value);
  email.value = "";
  role.value = props.assignableRoles[0] ?? "";
}
</script>

<template>
  <!-- items-end keeps the inputs and the (label-less) submit button bottom-aligned;
       every control is h-9 so tops align too. -->
  <form
    class="flex flex-col gap-3 sm:flex-row sm:items-end"
    @submit.prevent="submit"
  >
    <div class="flex-1 space-y-2">
      <KinetixLabel for="kinetix-member-email">
        {{ t("kinetix.member_email") }}
      </KinetixLabel>
      <input
        id="kinetix-member-email"
        v-model="email"
        type="email"
        required
        :class="inputClass"
        :placeholder="t('kinetix.member_email')"
      />
    </div>

    <div class="space-y-2 sm:w-44">
      <KinetixLabel for="kinetix-member-role">
        {{ t("kinetix.member_role") }}
      </KinetixLabel>
      <KinetixSelect
        id="kinetix-member-role"
        :value="role"
        :options="roleOptions"
        :placeholder="t('kinetix.member_role')"
        @update:value="role = $event"
      />
    </div>

    <button type="submit" :class="buttonVariants()">
      {{ t("kinetix.member_provision") }}
    </button>
  </form>
</template>
