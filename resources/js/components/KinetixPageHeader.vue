<script setup lang="ts">
import { Circle } from '@lucide/vue';
import { useActionConfirmation } from '@/composables/useKinetixActions';
import { useKinetixHotkeys } from '@/composables/useKinetixHotkeys';
import { resolveIcon as resolveKinetixIcon } from '@/composables/useKinetixIcons';
import {
    actionButtonSize,
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixAction } from '@/types/kinetix';
import KinetixActionDropdown from './KinetixActionDropdown.vue';
import KinetixButton from './KinetixButton.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import { cn } from './primitives/cn';

const props = withDefaults(
    defineProps<{
        heading?: string | null;
        description?: string | null;
        actions?: KinetixAction[];
    }>(),
    {
        heading: null,
        description: null,
        actions: () => [],
    },
);

const {
    pendingAction,
    isConfirmOpen,
    processing,
    processingAction,
    requestAction,
    confirm,
    cancel,
} = useActionConfirmation();

// Register keyboard shortcuts declared on header actions (auto-cleaned on unmount).
const { register } = useKinetixHotkeys();

for (const action of props.actions) {
    if (action.shortcut && action.type !== 'group') {
        register({
            keys: action.shortcut,
            label: action.label,
            handler: () => requestAction(action),
        });
    }
}

// Unknown (but non-empty) names fall back to a neutral circle.
const resolveIcon = (name?: string | null) =>
    name ? (resolveKinetixIcon(name) ?? Circle) : null;

// shadcn-vue (new-york) link UI for `viewType: 'link'` actions; button-type
// actions render through <KinetixButton> (shared pending/disabled behaviour).
const linkActionClass = (action: KinetixAction) =>
    cn(
        buttonVariants({
            variant: 'link',
            size: actionButtonSize(action.size),
        }),
        'cursor-pointer',
        processing.value ? 'pointer-events-none opacity-50' : '',
    );
</script>

<template>
    <div
        class="gap-3 sm:flex-row sm:items-center sm:justify-between mb-6 flex flex-col"
    >
        <div v-if="heading || description" class="min-w-0">
            <h1
                v-if="heading"
                class="text-xl font-semibold tracking-tight text-foreground"
            >
                {{ heading }}
            </h1>
            <p v-if="description" class="mt-1 text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <div class="gap-2 flex shrink-0 flex-wrap items-center">
            <slot name="before-actions" />

            <template v-for="(action, i) in actions" :key="action.name ?? i">
                <KinetixActionDropdown
                    v-if="action.type === 'group'"
                    :group="action"
                    @action-click="(a: KinetixAction) => requestAction(a)"
                />

                <a
                    v-else-if="action.viewType === 'link'"
                    :href="action.url ?? undefined"
                    role="button"
                    :aria-disabled="processing || undefined"
                    :class="linkActionClass(action)"
                    @click.prevent="requestAction(action)"
                >
                    <component
                        :is="resolveIcon(action.icon)"
                        v-if="action.icon && action.iconPosition !== 'after'"
                        class="h-4 w-4"
                    />
                    {{ action.label }}
                    <component
                        :is="resolveIcon(action.icon)"
                        v-if="action.icon && action.iconPosition === 'after'"
                        class="h-4 w-4"
                    />
                </a>

                <KinetixButton
                    v-else
                    :variant="actionButtonVariant(action.color)"
                    :size="actionButtonSize(action.size)"
                    :disabled="processing"
                    :loading="processing && processingAction === action.name"
                    @click="requestAction(action)"
                >
                    <template #icon>
                        <component
                            :is="resolveIcon(action.icon)"
                            v-if="
                                action.icon && action.iconPosition !== 'after'
                            "
                            class="h-4 w-4"
                        />
                    </template>
                    {{ action.label }}
                    <component
                        :is="resolveIcon(action.icon)"
                        v-if="action.icon && action.iconPosition === 'after'"
                        class="h-4 w-4"
                    />
                </KinetixButton>
            </template>

            <slot />
        </div>

        <KinetixConfirmModal
            v-model:open="isConfirmOpen"
            :heading="pendingAction?.modalHeading"
            :description="pendingAction?.modalDescription"
            :icon="pendingAction?.modalIcon"
            :color="pendingAction?.color"
            :submit-label="pendingAction?.modalSubmitActionLabel"
            :cancel-label="pendingAction?.modalCancelActionLabel"
            :processing="processing"
            @confirm="confirm"
            @cancel="cancel"
        />
    </div>
</template>
