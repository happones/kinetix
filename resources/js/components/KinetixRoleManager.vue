<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useKinetixRoles } from "@/composables/useKinetixRoles";
import { buttonVariants } from "@/composables/useShadcnVariants";
import type { KinetixRole } from "@/types";
import KinetixRoleForm from "./KinetixRoleForm.vue";

/**
 * Drop-in roles & permissions manager: lists roles and creates/edits/deletes
 * them against Kinetix's permission endpoints. Place behind the `roles.manage`
 * permission (e.g. wrap with <KinetixCan permission="roles.manage">).
 */
const { features, roles, loading, load, save, remove } = useKinetixRoles();
const { t } = useI18n();

const editing = ref<KinetixRole | null>(null);
const confirmKey = ref<string | number | null>(null);

const rowKey = (role: KinetixRole): string | number => role.id ?? role.name;

onMounted(load);

function create(): void {
  editing.value = { id: null, name: "", permissions: [] };
}

function edit(role: KinetixRole): void {
  editing.value = { ...role, permissions: [...role.permissions] };
}

async function onSave(role: KinetixRole): Promise<void> {
  try {
    await save(role);
    editing.value = null;
    await load();
    toast.success(t("kinetix.saved"));
  } catch {
    toast.error(t("kinetix.save_failed"));
  }
}

async function onDelete(role: KinetixRole): Promise<void> {
  try {
    await remove(role);
    confirmKey.value = null;
    await load();
    toast.success(t("kinetix.deleted"));
  } catch {
    toast.error(t("kinetix.delete_failed"));
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-foreground">
        {{ t("kinetix.roles_title") }}
      </h2>
      <button
        v-if="!editing"
        type="button"
        :class="buttonVariants({ size: 'sm' })"
        @click="create"
      >
        {{ t("kinetix.create_role") }}
      </button>
    </div>

    <div v-if="editing" class="rounded-lg border border-border bg-card p-4">
      <KinetixRoleForm
        :role="editing"
        :features="features"
        @save="onSave"
        @cancel="editing = null"
      />
    </div>

    <div
      v-else
      class="divide-y divide-border rounded-lg border border-border bg-card"
    >
      <p
        v-if="!loading && roles.length === 0"
        class="p-4 text-sm text-muted-foreground"
      >
        {{ t("kinetix.no_roles") }}
      </p>

      <div
        v-for="role in roles"
        :key="rowKey(role)"
        class="flex items-center justify-between gap-2 p-3"
      >
        <div>
          <span class="text-sm font-medium text-foreground">{{
            role.name
          }}</span>
          <span class="ml-2 text-xs text-muted-foreground">{{
            role.permissions.length
          }}</span>
        </div>

        <div class="flex items-center gap-2">
          <template v-if="confirmKey === rowKey(role)">
            <span class="text-xs text-muted-foreground">{{
              t("kinetix.confirm_delete")
            }}</span>
            <button
              type="button"
              :class="buttonVariants({ variant: 'destructive', size: 'sm' })"
              @click="onDelete(role)"
            >
              {{ t("kinetix.delete") }}
            </button>
            <button
              type="button"
              :class="buttonVariants({ variant: 'outline', size: 'sm' })"
              @click="confirmKey = null"
            >
              {{ t("kinetix.cancel") }}
            </button>
          </template>
          <template v-else>
            <button
              type="button"
              :class="buttonVariants({ variant: 'outline', size: 'sm' })"
              @click="edit(role)"
            >
              {{ t("kinetix.edit") }}
            </button>
            <button
              type="button"
              :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
              @click="confirmKey = rowKey(role)"
            >
              {{ t("kinetix.delete") }}
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
