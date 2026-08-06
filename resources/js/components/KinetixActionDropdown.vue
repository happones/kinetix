<script setup lang="ts">
import { Circle, MoreVertical } from '@lucide/vue';
import {
    DropdownMenuRoot,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
} from 'reka-ui';
import { computed, getCurrentInstance, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useActionConfirmation } from '@/composables/useKinetixActions';
import { resolveIcon as resolveKinetixIcon } from '@/composables/useKinetixIcons';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import { statusInteractiveTextClass } from '@/composables/useKinetixStatusColor';
import type { KinetixAction, KinetixTableRecord } from '@/types/kinetix';
import KinetixConfirmModal from './KinetixConfirmModal.vue';

const props = defineProps<{
    group: KinetixAction;
    /** Row context — grouped record actions forward it on every item click. */
    record?: KinetixTableRecord;
}>();

const emit = defineEmits<{
    (
        e: 'action-click',
        action: KinetixAction,
        record?: KinetixTableRecord,
    ): void;
}>();

const { t } = useI18n();
const {
    pendingAction,
    isConfirmOpen,
    processing,
    requestAction,
    confirm,
    cancel,
} = useActionConfirmation();

const isOpen = ref(false);

// A host that listens owns execution: record modals (view/edit/delete),
// the surface-wide processing guard, and its own confirm modal. The internal
// confirm-and-run path only serves standalone use (no listener bound) —
// otherwise a grouped modal action would die here silently, since this
// component has no access to useKinetixRecordModals. The instance is captured
// during setup: getCurrentInstance() is null once handlers run.
const instance = getCurrentInstance();
const hasHostHandler = computed(() => !!instance?.vnode.props?.onActionClick);

// No name → the dropdown trigger's default (vertical ellipsis); unknown → Circle.
const resolveIcon = (name?: string | null) =>
    name ? (resolveKinetixIcon(name) ?? Circle) : MoreVertical;

const getItemColorClass = (color?: string | null) =>
    statusInteractiveTextClass(color);

const onItemClick = (action: KinetixAction) => {
    if (hasHostHandler.value) {
        emit('action-click', action, props.record);

        return;
    }

    requestAction(action, props.record ? { record: props.record } : {});
};
</script>

<template>
    <div class="inline-block">
        <DropdownMenuRoot v-model:open="isOpen">
            <DropdownMenuTrigger
                type="button"
                :class="
                    group.label
                        ? buttonVariants({ variant: 'outline', size: 'sm' })
                        : buttonVariants({ variant: 'ghost', size: 'icon-sm' })
                "
                :aria-label="group.label || t('kinetix.more_actions')"
            >
                <component :is="resolveIcon(group.icon)" class="h-4 w-4" />
                <span v-if="group.label">{{ group.label }}</span>
            </DropdownMenuTrigger>

            <DropdownMenuPortal>
                <DropdownMenuContent
                    align="end"
                    :side-offset="4"
                    class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] min-w-[11rem] border border-border bg-popover outline-none"
                >
                    <DropdownMenuItem
                        v-for="(action, i) in group.actions || []"
                        :key="action.name ?? i"
                        class="gap-2 px-3 py-2 text-sm flex w-full cursor-default items-center rounded-md text-left transition-colors outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
                        :class="getItemColorClass(action.color)"
                        @select="onItemClick(action)"
                    >
                        <component
                            :is="resolveIcon(action.icon)"
                            v-if="action.icon"
                            class="h-4 w-4"
                        />
                        {{ action.label }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenuPortal>
        </DropdownMenuRoot>

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
