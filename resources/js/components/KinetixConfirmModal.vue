<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { actionButtonVariant } from '@/composables/useKinetixShadcnVariants';
import { statusSoftClass } from '@/composables/useKinetixStatusColor';
import KinetixButton from './KinetixButton.vue';
import KinetixModal from './primitives/KinetixModal.vue';

/**
 * Confirmation dialog on the shared KinetixModal shell (shadcn new-york-v4
 * Dialog line: fade+zoom animation, bg-background panel, v4 footer stack),
 * keeping the Kinetix confirm semantics: an icon chip colored by the action,
 * a processing state that blocks dismissal/re-confirm, and NO self-close on
 * confirm — the parent closes once its async handler resolves.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        heading?: string | null;
        description?: string | null;
        icon?: string | null;
        color?: string | null;
        submitLabel?: string | null;
        cancelLabel?: string | null;
        /** When true, the confirm action is running: buttons disable, a spinner
         *  shows, and the modal can't be dismissed or re-confirmed until it clears
         *  (the parent closes it once its async handler resolves). */
        processing?: boolean;
    }>(),
    {
        heading: null,
        description: null,
        icon: null,
        color: 'danger',
        submitLabel: null,
        cancelLabel: null,
        processing: false,
    },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm'): void;
    (e: 'cancel'): void;
}>();

const { t } = useI18n();

const cancel = () => {
    if (props.processing) {
        return; // can't dismiss while the action is running
    }

    emit('update:open', false);
    emit('cancel');
};

const confirm = () => {
    if (props.processing) {
        return; // guard a double confirm
    }

    emit('confirm');
};
</script>

<template>
    <KinetixModal
        :open="open"
        max-width="sm:max-w-md"
        :processing="processing"
        @update:open="(value) => !value && cancel()"
    >
        <template #header="{ headingId }">
            <div class="gap-4 flex items-start text-left">
                <span
                    class="h-10 w-10 flex shrink-0 items-center justify-center rounded-full"
                    :class="statusSoftClass(color)"
                >
                    <AlertTriangle class="h-5 w-5" />
                </span>

                <div class="pt-1 min-w-0 flex-1">
                    <h2
                        :id="headingId"
                        class="text-lg font-semibold tracking-tight leading-none text-foreground"
                    >
                        {{ heading ?? t('kinetix.confirm_heading') }}
                    </h2>
                    <p
                        v-if="description"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
            </div>
        </template>

        <!-- DRY: the SHARED KinetixButton — never re-write button classes;
             pass variant/loading/text and let the base own the look. -->
        <template #footer>
            <KinetixButton
                variant="outline"
                :disabled="processing"
                @click="cancel"
            >
                {{ cancelLabel ?? t('kinetix.cancel') }}
            </KinetixButton>
            <KinetixButton
                :variant="actionButtonVariant(color)"
                :loading="processing"
                @click="confirm"
            >
                {{ submitLabel ?? t('kinetix.confirm') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
