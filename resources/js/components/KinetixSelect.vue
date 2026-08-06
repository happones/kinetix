<script setup lang="ts">
import { Check, ChevronDown } from '@lucide/vue';
import {
    SelectContent,
    SelectIcon,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectValue,
    SelectViewport,
} from 'reka-ui';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        value?: string | number | null;
        options?: Record<string, string> | null;
        disabled?: boolean;
        placeholder?: string | null;
        id?: string;
        disabledKeys?: string[];
    }>(),
    {
        value: null,
        options: () => ({}),
        disabled: false,
        placeholder: null,
        disabledKeys: () => [],
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: string): void;
}>();

defineOptions({
    inheritAttrs: false,
});

const hasEmptyOption = computed(() => {
    return props.options ? '' in props.options : false;
});

const selectValue = computed(() => {
    if (
        props.value === null ||
        props.value === undefined ||
        props.value === ''
    ) {
        return hasEmptyOption.value ? '__EMPTY__' : '';
    }

    return String(props.value);
});
</script>

<template>
    <SelectRoot
        :model-value="selectValue"
        :disabled="disabled"
        @update:model-value="
            emit(
                'update:value',
                $event === '__EMPTY__' ? '' : ($event as string),
            )
        "
    >
        <SelectTrigger
            v-bind="$attrs"
            :id="id"
            class="h-9 gap-2 px-3 py-2 text-sm shadow-xs [&_svg:not([class*='size-'])]:size-4 flex w-full items-center justify-between rounded-md border border-input bg-transparent whitespace-nowrap transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 data-[placeholder]:text-muted-foreground dark:bg-input/30 dark:hover:bg-input/50 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0"
        >
            <SelectValue :placeholder="placeholder ?? ''" />
            <SelectIcon as-child>
                <ChevronDown class="h-4 w-4 opacity-50" />
            </SelectIcon>
        </SelectTrigger>

        <SelectPortal>
            <SelectContent
                position="popper"
                :side-offset="4"
                class="shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 relative z-[var(--kinetix-z-popover,120)] max-h-(--reka-select-content-available-height) min-w-[8rem] origin-(--reka-select-content-transform-origin) overflow-hidden rounded-md border border-border bg-popover text-popover-foreground"
            >
                <SelectViewport class="p-1">
                    <SelectItem
                        v-for="(label, val) in options"
                        :key="val"
                        :value="val === '' ? '__EMPTY__' : String(val)"
                        :disabled="disabledKeys?.includes(String(val))"
                        class="gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm [&_svg:not([class*='size-'])]:size-4 relative flex w-full cursor-default items-center outline-none select-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
                    >
                        <span
                            class="right-2 size-3.5 absolute flex items-center justify-center"
                        >
                            <SelectItemIndicator>
                                <Check class="size-4" />
                            </SelectItemIndicator>
                        </span>
                        <SelectItemText>{{ label }}</SelectItemText>
                    </SelectItem>
                </SelectViewport>
            </SelectContent>
        </SelectPortal>
    </SelectRoot>
</template>
