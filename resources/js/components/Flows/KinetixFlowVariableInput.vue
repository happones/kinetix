<script setup lang="ts">
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxInput,
    ComboboxItem,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxViewport,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { inputClass } from '@/composables/useKinetixShadcnVariants';

/**
 * A variable's name, with the flow's variables suggested as you type (arrows
 * and Enter pick one). Any other name can be typed too: letters, digits, `_`
 * and `.`.
 */
const props = defineProps<{
    value: string;
    variables: string[];
    placeholder?: string;
}>();

const emit = defineEmits<{
    (e: 'update:value', value: string): void;
}>();

defineOptions({ inheritAttrs: false });

const open = ref(false);
const term = ref(props.value);

watch(
    () => props.value,
    (value) => {
        term.value = value;
    },
);

const matches = computed(() => {
    const query = term.value.trim().toLowerCase();

    return props.variables
        .filter(
            (variable) =>
                variable !== term.value &&
                (!query || variable.toLowerCase().includes(query)),
        )
        .slice(0, 8);
});

function type(value: string): void {
    term.value = value.replace(/[^\w.]/g, '');
    emit('update:value', term.value);
}

function pick(value: unknown): void {
    if (typeof value === 'string' && value !== '') {
        term.value = value;
        emit('update:value', value);
        open.value = false;
    }
}
</script>

<template>
    <ComboboxRoot
        v-model:open="open"
        :model-value="value"
        ignore-filter
        open-on-focus
        :reset-search-term-on-blur="false"
        :reset-search-term-on-select="false"
        class="min-w-0 flex-1"
        @update:model-value="pick"
    >
        <ComboboxAnchor as-child>
            <ComboboxInput
                v-bind="$attrs"
                :model-value="term"
                :class="[inputClass, 'font-mono']"
                :placeholder="placeholder"
                autocomplete="off"
                spellcheck="false"
                @update:model-value="type"
            />
        </ComboboxAnchor>
        <ComboboxPortal>
            <ComboboxContent
                v-if="matches.length"
                position="popper"
                :side-offset="4"
                class="max-h-56 p-1 shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] w-(--reka-combobox-trigger-width) overflow-y-auto rounded-md border border-border bg-popover text-popover-foreground outline-none"
            >
                <ComboboxViewport>
                    <ComboboxItem
                        v-for="variable in matches"
                        :key="variable"
                        :value="variable"
                        class="px-2 py-1.5 font-mono text-xs rounded-sm flex w-full cursor-default items-center outline-none select-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                    >
                        {{ variable }}
                    </ComboboxItem>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
