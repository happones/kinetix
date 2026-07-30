<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ComboboxContent,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxRoot,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    VisuallyHidden,
} from 'reka-ui';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixSpotlight } from '@/composables/useKinetixSpotlight';
import type {
    KinetixSpotlightGroup,
    KinetixSpotlightItem,
} from '@/types/kinetix';

/**
 * Global Cmd/Ctrl+K command palette. Built on Reka Dialog + Combobox (keyboard
 * nav + selection for free). Searches the spotlight endpoint (debounced); server
 * results are already authorization-filtered. Mount once in your layout.
 */
const { t } = useI18n();
const { search } = useKinetixSpotlight();

const open = ref(false);
const query = ref('');
const groups = ref<KinetixSpotlightGroup[]>([]);

let debounce: ReturnType<typeof setTimeout> | undefined;

function onKeydown(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = true;
    }
}

// Allow an external trigger (e.g. <KinetixSpotlightTrigger> in the header) to
// open the palette without coupling components.
function onExternalOpen(): void {
    open.value = true;
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('kinetix:spotlight', onExternalOpen);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('kinetix:spotlight', onExternalOpen);
    clearTimeout(debounce);
});

function onOpenChange(value: boolean): void {
    open.value = value;

    if (!value) {
        query.value = '';
        groups.value = [];
    }
}

function onInput(event: Event): void {
    query.value = (event.target as HTMLInputElement).value;
    clearTimeout(debounce);
    debounce = setTimeout(async () => {
        groups.value = await search(query.value);
    }, 200);
}

function onSelect(item: unknown): void {
    const selected = item as KinetixSpotlightItem | undefined;

    if (!selected) {
        return;
    }

    onOpenChange(false);

    if (selected.url) {
        router.visit(selected.url);
    } else if (selected.event) {
        window.dispatchEvent(
            new CustomEvent(selected.event, { detail: selected }),
        );
    }
}
</script>

<template>
    <DialogRoot :open="open" @update:open="onOpenChange">
        <DialogPortal>
            <DialogOverlay class="inset-0 bg-black/50 fixed z-50" />
            <DialogContent
                class="top-24 max-w-lg rounded-xl shadow-lg fixed left-1/2 z-50 w-full -translate-x-1/2 overflow-hidden border border-border bg-popover text-popover-foreground"
            >
                <VisuallyHidden>
                    <DialogTitle>{{
                        t('kinetix.spotlight_placeholder')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t('kinetix.spotlight_placeholder')
                    }}</DialogDescription>
                </VisuallyHidden>

                <ComboboxRoot
                    :open="true"
                    :ignore-filter="true"
                    @update:model-value="onSelect"
                >
                    <ComboboxInput
                        auto-focus
                        :placeholder="t('kinetix.spotlight_placeholder')"
                        class="px-4 py-3 text-sm w-full border-b border-border bg-transparent text-foreground outline-none placeholder:text-muted-foreground"
                        @input="onInput"
                    />

                    <ComboboxContent class="max-h-80 p-2 overflow-y-auto">
                        <ComboboxEmpty
                            class="px-2 py-6 text-sm text-center text-muted-foreground"
                        >
                            {{ t('kinetix.spotlight_empty') }}
                        </ComboboxEmpty>

                        <ComboboxGroup
                            v-for="group in groups"
                            :key="group.label"
                            class="mb-1"
                        >
                            <div
                                class="px-2 py-1 text-xs font-medium text-muted-foreground"
                            >
                                {{ group.label }}
                            </div>

                            <ComboboxItem
                                v-for="(item, i) in group.items"
                                :key="`${group.label}-${item.id ?? i}`"
                                :value="item"
                                class="gap-0.5 px-2 py-2 text-sm flex cursor-default flex-col rounded-md text-foreground outline-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                            >
                                <span>{{ item.title }}</span>
                                <span
                                    v-if="item.subtitle"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ item.subtitle }}
                                </span>
                            </ComboboxItem>
                        </ComboboxGroup>
                    </ComboboxContent>
                </ComboboxRoot>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
