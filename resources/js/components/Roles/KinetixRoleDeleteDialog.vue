<script setup lang="ts">
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixRole } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';

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
    <DialogRoot
        :open="open"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <DialogPortal>
            <DialogOverlay
                class="inset-0 bg-black/80 fixed z-[var(--kinetix-z-overlay,100)]"
            />
            <DialogContent
                class="max-w-sm rounded-xl p-6 shadow-lg fixed top-1/2 left-1/2 z-[var(--kinetix-z-modal,100)] w-[92vw] -translate-x-1/2 -translate-y-1/2 border border-border bg-card text-card-foreground outline-none"
            >
                <DialogTitle
                    class="text-lg font-semibold tracking-tight leading-none"
                >
                    {{ t('kinetix.delete') }}
                </DialogTitle>
                <DialogDescription class="mt-1.5 text-sm text-muted-foreground">
                    {{ t('kinetix.confirm_delete') }}
                </DialogDescription>

                <p
                    v-if="role && (role.usersCount ?? 0) > 0"
                    class="mt-3 px-3 py-2 text-xs rounded-md border border-warning/40 bg-warning/10 text-foreground"
                >
                    {{
                        t('kinetix.role_delete_members_warning', {
                            role: role.name,
                            count: role.usersCount,
                        })
                    }}
                </p>

                <div class="mt-4 gap-2 flex justify-end">
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
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
