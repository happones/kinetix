<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixRole } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

/**
 * Role delete confirmation shared by the role-management UIs. `open` when a
 * target role is pending; `confirm` performs the delete, closing is always
 * allowed (the destructive button alone shows the pending state). When the
 * target role still has members, a warning explains the server will refuse.
 */
defineProps<{
    open: boolean;
    deleting?: boolean;
    /** The role about to be deleted — enables the members-in-use warning. */
    role?: KinetixRole | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <KinetixModal
        :open="open"
        :title="t('kinetix.delete')"
        :description="t('kinetix.confirm_delete')"
        max-width="sm:max-w-sm"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <p
            v-if="role && (role.usersCount ?? 0) > 0"
            class="px-3 py-2 text-xs rounded-md border border-warning/40 bg-warning/10 text-foreground"
        >
            {{
                t('kinetix.role_delete_members_warning', {
                    role: role.name,
                    count: role.usersCount,
                })
            }}
        </p>

        <template #footer>
            <button
                type="button"
                :class="buttonVariants({ variant: 'outline' })"
                :disabled="deleting"
                @click="emit('update:open', false)"
            >
                {{ t('kinetix.cancel') }}
            </button>
            <KinetixButton
                variant="destructive"
                :loading="deleting"
                @click="emit('confirm')"
            >
                {{ t('kinetix.delete') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
