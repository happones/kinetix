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

/**
 * Role delete confirmation shared by the role-management UIs. `open` when a
 * target role is pending; `confirm` performs the delete, closing is always
 * allowed (the destructive button alone is disabled while deleting).
 */
defineProps<{
    open: boolean;
    deleting?: boolean;
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
                <div class="mt-4 gap-2 flex justify-end">
                    <button
                        type="button"
                        :class="buttonVariants({ variant: 'outline' })"
                        @click="emit('update:open', false)"
                    >
                        {{ t('kinetix.cancel') }}
                    </button>
                    <button
                        type="button"
                        :class="buttonVariants({ variant: 'destructive' })"
                        :disabled="deleting"
                        @click="emit('confirm')"
                    >
                        {{ t('kinetix.delete') }}
                    </button>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
