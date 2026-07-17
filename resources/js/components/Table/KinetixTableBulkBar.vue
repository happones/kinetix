<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import {
    actionButtonVariant,
    buttonVariants,
} from '@/composables/useShadcnVariants';
import type { KinetixAction } from '@/types';

defineProps<{
    bulkActions: KinetixAction[];
    selectionCount: number;
    /** True while a bulk action is running — disables the buttons. */
    processing?: boolean;
}>();

const emit = defineEmits<{
    (e: 'run-action', action: KinetixAction): void;
    (e: 'clear'): void;
}>();

const { t } = useI18n();

const actionClass = (action: { color?: string | null }): string =>
    buttonVariants({
        variant: action.color ? actionButtonVariant(action.color) : 'default',
        size: 'sm',
    });
</script>

<template>
    <div
        class="gap-3 px-6 py-3 flex flex-wrap items-center border-b border-border bg-muted/40"
    >
        <span class="text-xs font-semibold text-muted-foreground">
            {{ t('kinetix.selected', { count: selectionCount }) }}
        </span>
        <div class="gap-2 flex flex-wrap items-center">
            <button
                v-for="(action, i) in bulkActions"
                :key="i"
                type="button"
                :disabled="processing"
                :class="actionClass(action)"
                @click="emit('run-action', action)"
            >
                <component :is="resolveIcon(action.icon)" v-if="action.icon" />
                {{ action.label }}
            </button>
        </div>
        <button
            type="button"
            class="text-xs ml-auto text-muted-foreground underline-offset-4 transition-colors outline-none hover:text-foreground focus-visible:underline"
            @click="emit('clear')"
        >
            {{ t('kinetix.clear_selection') }}
        </button>
    </div>
</template>
