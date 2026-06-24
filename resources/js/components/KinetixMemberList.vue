<script setup lang="ts">
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buttonVariants } from "@/composables/useShadcnVariants";
import { useKinetixMembers } from "@/composables/useKinetixMembers";
import type { KinetixMemberProvision } from "@/types";
import KinetixMemberProvisioner from "./KinetixMemberProvisioner.vue";

/**
 * Drop-in members directory for the admin-provisioned onboarding model — the
 * substitute for the starter-kit's team-invitation screen. Lists provisioned
 * members (pending / active / revoked) with resend, role change and revoke, and
 * embeds the provisioning form. Place behind the `members.provision` ability.
 */
const {
  provisions,
  assignableRoles,
  loading,
  load,
  provision,
  resend,
  updateRole,
  revoke,
} = useKinetixMembers();
const { t } = useI18n();

const rowKey = (member: KinetixMemberProvision): string | number =>
  member.id ?? member.email;

onMounted(load);

async function onProvision(email: string, role: string): Promise<void> {
  try {
    await provision(email, role);
    await load();
    toast.success(t("kinetix.member_provisioned"));
  } catch {
    toast.error(t("kinetix.member_provision_failed"));
  }
}

async function onResend(member: KinetixMemberProvision): Promise<void> {
  try {
    await resend(member);
    await load();
    toast.success(t("kinetix.member_provisioned"));
  } catch {
    toast.error(t("kinetix.member_provision_failed"));
  }
}

async function onRoleChange(
  member: KinetixMemberProvision,
  role: string,
): Promise<void> {
  try {
    await updateRole(member, role);
    await load();
    toast.success(t("kinetix.member_role_updated"));
  } catch {
    toast.error(t("kinetix.save_failed"));
  }
}

async function onRevoke(member: KinetixMemberProvision): Promise<void> {
  try {
    await revoke(member);
    await load();
    toast.success(t("kinetix.member_revoked"));
  } catch {
    toast.error(t("kinetix.delete_failed"));
  }
}

function statusLabel(status: KinetixMemberProvision["status"]): string {
  return t(`kinetix.member_status_${status}`);
}
</script>

<template>
  <div class="space-y-4">
    <h2 class="text-lg font-semibold text-foreground">
      {{ t("kinetix.members_title") }}
    </h2>

    <div class="rounded-lg border border-border bg-card p-4">
      <KinetixMemberProvisioner
        :assignable-roles="assignableRoles"
        @submit="onProvision"
      />
    </div>

    <div class="divide-y divide-border rounded-lg border border-border bg-card">
      <p
        v-if="!loading && provisions.length === 0"
        class="p-4 text-sm text-muted-foreground"
      >
        {{ t("kinetix.no_members") }}
      </p>

      <div
        v-for="member in provisions"
        :key="rowKey(member)"
        class="flex flex-wrap items-center justify-between gap-2 p-3"
      >
        <div class="min-w-0">
          <span class="text-sm font-medium text-foreground">
            {{ member.name ?? member.email }}
          </span>
          <span v-if="member.name" class="ml-2 text-xs text-muted-foreground">
            {{ member.email }}
          </span>
        </div>

        <div class="flex items-center gap-2">
          <span
            class="rounded-full px-2 py-0.5 text-xs"
            :class="{
              'bg-muted text-muted-foreground': member.status !== 'active',
              'bg-primary/10 text-primary': member.status === 'active',
            }"
          >
            {{ statusLabel(member.status) }}
          </span>

          <select
            :value="member.role"
            class="rounded-md border border-input bg-background px-2 py-1 text-xs text-foreground"
            @change="
              onRoleChange(member, ($event.target as HTMLSelectElement).value)
            "
          >
            <option v-for="r in assignableRoles" :key="r" :value="r">
              {{ r }}
            </option>
          </select>

          <button
            v-if="member.status === 'pending'"
            type="button"
            :class="buttonVariants({ variant: 'outline', size: 'sm' })"
            @click="onResend(member)"
          >
            {{ t("kinetix.member_resend") }}
          </button>

          <button
            v-if="member.status !== 'revoked'"
            type="button"
            :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
            @click="onRevoke(member)"
          >
            {{ t("kinetix.member_revoke") }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
