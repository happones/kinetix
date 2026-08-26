<script setup lang="ts">
import { Circle } from '@lucide/vue';
import { computed } from 'vue';
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

/**
 * A row of serialized `KinetixAction`s, with everything an action needs behind
 * it: grouped actions as a dropdown, `requiresConfirmation()` through the shared
 * confirmation modal, declared `->shortcut()` keys, and per-button pending state
 * so a double click can't fire an action twice.
 *
 * It renders ONLY the actions — no heading, no wrapper chrome — so it is the one
 * implementation `KinetixPageHeader` and `KinetixPageFooter` share instead of
 * each carrying its own copy. Use it directly when you want an action row
 * somewhere neither of those fits.
 *
 * Slots: `before` (left of the actions) and `after` (right of them).
 */
const props = withDefaults(
    defineProps<{
        actions?: KinetixAction[];
        /**
         * Register the actions' declared keyboard shortcuts. Turn it off for a
         * second bar on the same page that repeats actions the first one
         * already bound.
         */
        shortcuts?: boolean;
        /**
         * Stack the actions full-width on mobile (last action — the primary one
         * by convention — on top, where the thumb is) and lay them out as a
         * right-aligned row from `sm` up. This is the footer/dialog convention;
         * the default is the header's inline, wrapping row.
         */
        stack?: boolean;
    }>(),
    {
        actions: () => [],
        shortcuts: true,
        stack: false,
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

// Keyboard shortcuts declared on the actions (auto-cleaned on unmount).
const { register } = useKinetixHotkeys();

if (props.shortcuts) {
    for (const action of props.actions) {
        if (action.shortcut && action.type !== 'group') {
            register({
                keys: action.shortcut,
                label: action.label,
                handler: () => requestAction(action),
            });
        }
    }
}

// Unknown (but non-empty) names fall back to a neutral circle.
const resolveIcon = (name?: string | null) =>
    name ? (resolveKinetixIcon(name) ?? Circle) : null;

// shadcn-vue (new-york) link UI for `viewType: 'link'` actions; button-type
// actions render through <KinetixButton> (shared pending/disabled behaviour).
// Deliberately NOT `items-center` in the stacked layout: a column flex
// container stretches its children, which is what makes the buttons full-width
// on mobile without a `w-full` on each one.
const layoutClass = computed(() =>
    props.stack
        ? 'w-full flex-col-reverse sm:w-auto sm:flex-row sm:items-center sm:justify-end'
        : 'shrink-0 flex-wrap items-center',
);

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
    <div class="gap-2 flex" :class="layoutClass">
        <slot name="before" />

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
                        v-if="action.icon && action.iconPosition !== 'after'"
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

        <slot name="after" />

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
