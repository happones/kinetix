<script setup lang="ts">
import { X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixAction,
    KinetixCalendarEvent,
    KinetixCalendarEventDisplay,
    KinetixSheetSide,
} from '@/types/kinetix';
import KinetixSheet from '../KinetixSheet.vue';
import { cn } from '../primitives/cn';

defineProps<{
    isMounted: boolean;
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
</script>

<template>
    <div>
        <!-- ===== Event details: modal ===== -->
        <Teleport v-if="isMounted && eventDisplay === 'modal'" to="body">
            <Transition
                enter-active-class="transition-opacity duration-150"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-150"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="open && event"
                    class="inset-0 p-4 fixed z-[100] flex items-center justify-center"
                    role="dialog"
                    aria-modal="true"
                >
                    <div
                        class="inset-0 bg-black/50 backdrop-blur-sm absolute"
                        @click="emit('close')"
                    />

                    <div
                        class="max-w-sm rounded-xl shadow-2xl p-6 relative w-full border border-border bg-popover"
                    >
                        <button
                            type="button"
                            class="right-4 top-4 absolute text-muted-foreground transition-colors hover:text-foreground"
                            :aria-label="t('kinetix.close')"
                            @click="emit('close')"
                        >
                            <X class="size-4" />
                        </button>

                        <div class="gap-2 flex items-start">
                            <span
                                class="mt-1.5 size-2.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor: event.color ?? '#3b82f6',
                                }"
                            />
                            <div class="min-w-0">
                                <h2
                                    class="text-base font-semibold tracking-tight text-foreground"
                                >
                                    {{ event.title }}
                                </h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ rangeLabel }}
                                </p>
                                <p
                                    v-if="event.description"
                                    class="mt-3 text-sm text-foreground"
                                >
                                    {{ event.description }}
                                </p>
                                <a
                                    v-if="event.url"
                                    :href="event.url"
                                    :class="
                                        cn(
                                            buttonVariants({ size: 'sm' }),
                                            'mt-4',
                                        )
                                    "
                                >
                                    {{ t('kinetix.calendar_view_event') }}
                                </a>

                                <div
                                    v-if="actions.length"
                                    class="gap-2 mt-4 flex flex-wrap items-center"
                                >
                                    <button
                                        v-for="(action, idx) in actions"
                                        :key="idx"
                                        type="button"
                                        :class="actionClass(action)"
                                        :title="
                                            action.isIconButton
                                                ? action.label
                                                : undefined
                                        "
                                        :aria-label="
                                            action.isIconButton
                                                ? action.label
                                                : undefined
                                        "
                                        @click="emit('run-action', action)"
                                    >
                                        <component
                                            :is="resolveIcon(action.icon)"
                                            v-if="action.icon"
                                        />
                                        <span v-if="!action.isIconButton">{{
                                            action.label
                                        }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- ===== Event details: sheet ===== -->
        <KinetixSheet
            v-else
            :open="open"
            :side="sheetSide"
            :title="event?.title"
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
                        :title="action.isIconButton ? action.label : undefined"
                        :aria-label="
                            action.isIconButton ? action.label : undefined
                        "
                        @click="emit('run-action', action)"
                    >
                        <component
                            :is="resolveIcon(action.icon)"
                            v-if="action.icon"
                        />
                        <span v-if="!action.isIconButton">{{
                            action.label
                        }}</span>
                    </button>
                </div>
            </div>
        </KinetixSheet>
    </div>
</template>
