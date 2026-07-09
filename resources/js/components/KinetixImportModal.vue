<script setup lang="ts">
import { X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useShadcnVariants';
import KinetixImporter from './KinetixImporter.vue';
import { cn } from './primitives/cn';

/**
 * Global import dialog. Mount once in your layout:
 *   <KinetixImportModal />
 *
 * Opens on the `kinetix:open-importer` window event (dispatched by
 * `ImportAction::make()->importer(...)`), showing `KinetixImporter` for the
 * importer carried in the event detail.
 */

const { t } = useI18n();
const open = ref(false);
const token = ref<string | null>(null);
const template = ref<string | null>(null);

function onOpen(event: Event): void {
    const detail = (
        event as CustomEvent<{ importer?: string; template?: string | null }>
    ).detail;

    if (!detail?.importer) {
        return;
    }

    token.value = detail.importer;
    template.value = detail.template ?? null;
    open.value = true;
}

onMounted(() => window.addEventListener('kinetix:open-importer', onOpen));
onBeforeUnmount(() =>
    window.removeEventListener('kinetix:open-importer', onOpen),
);
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="inset-0 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed z-50"
            />
            <DialogContent
                class="max-w-3xl rounded-xl p-6 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-1/2 left-1/2 z-50 max-h-[90vh] w-[92vw] -translate-x-1/2 -translate-y-1/2 overflow-auto border border-border bg-card text-card-foreground outline-none"
            >
                <div class="mb-4 flex items-center justify-between">
                    <DialogTitle
                        class="text-lg font-semibold tracking-tight leading-none"
                    >
                        {{ t('kinetix.import') }}
                    </DialogTitle>
                    <DialogClose
                        :class="
                            cn(
                                buttonVariants({
                                    variant: 'ghost',
                                    size: 'icon-sm',
                                }),
                            )
                        "
                    >
                        <X class="h-4 w-4" />
                    </DialogClose>
                </div>

                <KinetixImporter
                    v-if="token"
                    :importer="token"
                    :template="template"
                />
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
