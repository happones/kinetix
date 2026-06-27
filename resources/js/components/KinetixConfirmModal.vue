<script setup lang="ts">
import { AlertTriangle, X } from '@lucide/vue';
import { onBeforeUnmount, watch, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    statusButtonClass,
    statusSoftClass,
} from '@/composables/useStatusColor';

const props = withDefaults(
    defineProps<{
        open: boolean;
        heading?: string | null;
        description?: string | null;
        icon?: string | null;
        color?: string | null;
        submitLabel?: string | null;
        cancelLabel?: string | null;
    }>(),
    {
        heading: null,
        description: null,
        icon: null,
        color: 'danger',
        submitLabel: null,
        cancelLabel: null,
    },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'confirm'): void;
    (e: 'cancel'): void;
}>();

const { t } = useI18n();

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});

const cancel = () => {
    emit('update:open', false);
    emit('cancel');
};

const confirm = () => {
    emit('update:open', false);
    emit('confirm');
};

// Escape-to-cancel. The listener is added only while the modal is open and is
// always removed on unmount, so no handler leaks across the component lifecycle.
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        cancel();
    }
};

watch(
    () => props.open,
    (isOpen) => {
        if (typeof window === 'undefined') {
            return;
        }

        if (isOpen) {
            window.addEventListener('keydown', onKeydown);

            return;
        }

        window.removeEventListener('keydown', onKeydown);
    },
);

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', onKeydown);
    }
});

const getConfirmButtonClass = (color?: string | null) =>
    statusButtonClass(color);

const getIconWrapperClass = (color?: string | null) => statusSoftClass(color);
</script>

<template>
    <Teleport v-if="isMounted" to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="inset-0 p-4 fixed z-[100] flex items-center justify-center"
                role="dialog"
                aria-modal="true"
            >
                <!-- Overlay -->
                <div
                    class="inset-0 bg-black/50 backdrop-blur-sm absolute"
                    @click="cancel"
                />

                <!-- Dialog -->
                <div
                    class="max-w-md rounded-xl shadow-2xl relative w-full border border-border bg-popover"
                >
                    <button
                        type="button"
                        class="right-4 top-4 absolute text-muted-foreground transition-colors hover:text-foreground"
                        :aria-label="cancelLabel ?? t('kinetix.cancel')"
                        @click="cancel"
                    >
                        <X class="h-4 w-4" />
                    </button>

                    <div class="p-6">
                        <div class="gap-4 flex items-start">
                            <span
                                class="h-10 w-10 flex shrink-0 items-center justify-center rounded-full"
                                :class="getIconWrapperClass(color)"
                            >
                                <AlertTriangle class="h-5 w-5" />
                            </span>

                            <div class="pt-1 flex-1">
                                <h2
                                    class="text-base font-semibold tracking-tight leading-none text-foreground"
                                >
                                    {{
                                        heading ?? t('kinetix.confirm_heading')
                                    }}
                                </h2>
                                <p
                                    v-if="description"
                                    class="mt-2 text-sm text-muted-foreground"
                                >
                                    {{ description }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-6 gap-3 flex justify-end">
                            <button
                                type="button"
                                class="gap-2 text-sm font-medium [&_svg:not([class*='size-'])]:size-4 shadow-xs h-9 px-4 py-2 has-[>svg]:px-3 inline-flex shrink-0 items-center justify-center rounded-md border bg-background whitespace-nowrap text-foreground transition-all outline-none hover:bg-accent hover:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 dark:border-input dark:bg-input/30 dark:hover:bg-input/50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                                @click="cancel"
                            >
                                {{ cancelLabel ?? t('kinetix.cancel') }}
                            </button>
                            <button
                                type="button"
                                class="gap-2 text-sm font-medium [&_svg:not([class*='size-'])]:size-4 h-9 px-4 py-2 has-[>svg]:px-3 inline-flex shrink-0 items-center justify-center rounded-md whitespace-nowrap transition-all outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                                :class="getConfirmButtonClass(color)"
                                @click="confirm"
                            >
                                {{ submitLabel ?? t('kinetix.confirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
