<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { isIconOnlyAction, resolveIcon } from '@/composables/useKinetixIcons';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixAction,
    KinetixCalendarEvent,
    KinetixCalendarEventDisplay,
    KinetixSheetSide,
} from '@/types/kinetix';
import KinetixSheet from '../KinetixSheet.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

const props = defineProps<{
    eventDisplay: KinetixCalendarEventDisplay;
    sheetSide: KinetixSheetSide;
    open: boolean;
    event: KinetixCalendarEvent | null;
    rangeLabel: string;
    actions: KinetixAction[];
    actionClass: (action: KinetixAction) => string;
}>();

const emit = defineEmits<{
    (e: 'update:open', open: boolean): void;
    (e: 'close'): void;
    (e: 'run-action', action: KinetixAction): void;
}>();

const { t } = useI18n();

/**
 * DRY: both presentations ride a SHARED Kinetix shell instead of a
 * hand-rolled panel — the shell owns the focus trap, Escape, the aria wiring
 * and (the point here) the bounded panel, so a long description scrolls in
 * the body instead of pushing the event actions off screen.
 */
const shell = computed(() =>
    props.eventDisplay === 'modal' ? KinetixModal : KinetixSheet,
);

const shellProps = computed(() =>
    props.eventDisplay === 'modal'
        ? { maxWidth: 'sm:max-w-sm', scrollBody: true }
        : { side: props.sheetSide },
);
</script>

<template>
    <component
        :is="shell"
        v-bind="shellProps"
        :open="open && event !== null"
        :title="event?.title ?? null"
        @update:open="emit('update:open', $event)"
        @close="emit('close')"
    >
        <div v-if="event" class="space-y-3">
            <div class="gap-2 flex items-center">
                <span
                    class="size-2.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: event.color ?? '#3b82f6' }"
                />
                <p class="text-sm text-muted-foreground">
                    {{ rangeLabel }}
                </p>
            </div>
            <p v-if="event.description" class="text-sm text-foreground">
                {{ event.description }}
            </p>
            <a
                v-if="event.url"
                :href="event.url"
                :class="buttonVariants({ size: 'sm' })"
            >
                {{ t('kinetix.calendar_view_event') }}
            </a>

            <div
                v-if="actions.length"
                class="gap-2 flex flex-wrap items-center"
            >
                <button
                    v-for="(action, idx) in actions"
                    :key="idx"
                    type="button"
                    :class="actionClass(action)"
                    :title="isIconOnlyAction(action) ? action.label : undefined"
                    :aria-label="
                        isIconOnlyAction(action) ? action.label : undefined
                    "
                    @click="emit('run-action', action)"
                >
                    <component
                        :is="resolveIcon(action.icon)"
                        v-if="resolveIcon(action.icon)"
                    />
                    <span v-if="!isIconOnlyAction(action)">{{
                        action.label
                    }}</span>
                </button>
            </div>
        </div>
    </component>
</template>
