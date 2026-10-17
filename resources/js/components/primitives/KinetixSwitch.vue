<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui';

/**
 * The shadcn new-york-v4 switch (Reka UI Switch) — the single home of the
 * recipe. Used by the Toggle form field and the business-hours editor;
 * build on THIS, never a re-copied class string. Attrs (aria-label, id…)
 * fall through to the switch root.
 */
defineProps<{
    modelValue: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void;
}>();
</script>

<template>
    <!-- An invisible ::after extends the tap area to 32×26px (WCAG 2.5.8's 24px
         minimum) — the drawn switch is 18px tall — without changing its look,
         as on KinetixCheckbox. 5px, not 4: the ::after hangs off the padding
         box, 1px inside the border. -->
    <SwitchRoot
        :model-value="modelValue"
        :disabled="disabled"
        class="peer w-8 shadow-xs after:inset-x-0 relative inline-flex h-[1.15rem] shrink-0 cursor-pointer items-center rounded-full border border-transparent transition-all outline-none after:absolute after:-inset-y-[5px] after:content-[''] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary data-[state=unchecked]:bg-input dark:data-[state=unchecked]:bg-input/80"
        @update:model-value="emit('update:modelValue', !!$event)"
    >
        <SwitchThumb
            class="size-4 data-[state=unchecked]:translate-x-0 pointer-events-none block rounded-full bg-background ring-0 transition-transform data-[state=checked]:translate-x-[calc(100%-2px)] dark:data-[state=checked]:bg-primary-foreground dark:data-[state=unchecked]:bg-foreground"
        />
    </SwitchRoot>
</template>
