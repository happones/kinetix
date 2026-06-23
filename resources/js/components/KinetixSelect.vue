<script setup lang="ts">
import { Check, ChevronDown } from "@lucide/vue";
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
} from "reka-ui";
import { computed } from "vue";

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
  (e: "update:value", value: string): void;
}>();

defineOptions({
  inheritAttrs: false,
});

const hasEmptyOption = computed(() => {
  return props.options ? "" in props.options : false;
});

const selectValue = computed(() => {
  if (props.value === null || props.value === undefined || props.value === "") {
    return hasEmptyOption.value ? "__EMPTY__" : "";
  }

  return String(props.value);
});
</script>

<template>
  <SelectRoot
    :model-value="selectValue"
    :disabled="disabled"
    @update:model-value="
      emit('update:value', $event === '__EMPTY__' ? '' : ($event as string))
    "
  >
    <SelectTrigger
      v-bind="$attrs"
      :id="id"
      class="flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-2 text-sm whitespace-nowrap shadow-xs transition-[color,box-shadow] outline-none data-[placeholder]:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 dark:bg-input/30 dark:hover:bg-input/50 disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
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
        class="relative z-50 max-h-96 min-w-[8rem] overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-md"
      >
        <SelectViewport class="p-1">
          <SelectItem
            v-for="(label, val) in options"
            :key="val"
            :value="val === '' ? '__EMPTY__' : String(val)"
            :disabled="disabledKeys?.includes(String(val))"
            class="relative flex w-full cursor-default select-none items-center gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm outline-none focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
          >
            <span
              class="absolute right-2 flex size-3.5 items-center justify-center"
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
