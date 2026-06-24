<script setup lang="ts">
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { buttonVariants, inputClass } from "@/composables/useShadcnVariants";
import type { KinetixPermissionFeature, KinetixRole } from "@/types";
import KinetixPermissionMatrix from "./KinetixPermissionMatrix.vue";

const props = defineProps<{
  role: KinetixRole;
  features: KinetixPermissionFeature[];
}>();

const emit = defineEmits<{
  (e: "save", role: KinetixRole): void;
  (e: "cancel"): void;
}>();

const { t } = useI18n();

const name = ref(props.role.name);
const permissions = ref<string[]>([...props.role.permissions]);

watch(
  () => props.role,
  (role) => {
    name.value = role.name;
    permissions.value = [...role.permissions];
  },
);

function submit(): void {
  emit("save", {
    ...props.role,
    name: name.value,
    permissions: permissions.value,
  });
}
</script>

<template>
  <div class="space-y-4">
    <div>
      <label class="mb-1 block text-sm font-medium text-foreground">{{
        t("kinetix.role_name")
      }}</label>
      <input v-model="name" type="text" :class="inputClass" />
    </div>

    <KinetixPermissionMatrix v-model="permissions" :features="features" />

    <div class="flex justify-end gap-2">
      <button
        type="button"
        :class="buttonVariants({ variant: 'outline', size: 'sm' })"
        @click="emit('cancel')"
      >
        {{ t("kinetix.cancel") }}
      </button>
      <button
        type="button"
        :class="buttonVariants({ size: 'sm' })"
        :disabled="!name.trim()"
        @click="submit"
      >
        {{ t("kinetix.save") }}
      </button>
    </div>
  </div>
</template>
