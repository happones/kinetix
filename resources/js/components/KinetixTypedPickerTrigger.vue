<script setup lang="ts">
import { CalendarIcon } from '@lucide/vue';
import { PopoverAnchor, PopoverTrigger } from 'reka-ui';
import type { Component } from 'vue';
import type { KinetixDateKind } from '@/composables/kinetixDateMask';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import KinetixMaskedDateInput from './KinetixMaskedDateInput.vue';
import { cn } from './primitives/cn';

/**
 * A picker's typed trigger: the masked input the reader types the value
 * into, anchoring the picker's popover, with the button that opens it on the
 * right. Rendered inside the picker's `PopoverRoot`.
 */
withDefaults(
    defineProps<{
        id?: string;
        value?: string | null;
        kind: KinetixDateKind;
        format?: string | null;
        locale?: string | null;
        hour12?: boolean | null;
        disabled?: boolean;
        minValue?: string | null;
        maxValue?: string | null;
        /** The button's icon. */
        icon?: Component;
        /** The button's accessible name. */
        buttonLabel: string;
    }>(),
    {
        id: undefined,
        value: null,
        format: null,
        locale: null,
        hour12: null,
        disabled: false,
        minValue: null,
        maxValue: null,
        icon: () => CalendarIcon,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

defineOptions({ inheritAttrs: false });
</script>

<template>
    <PopoverAnchor class="min-w-0 w-full">
        <KinetixMaskedDateInput
            v-bind="$attrs"
            :id="id"
            :value="value"
            :kind="kind"
            :format="format"
            :locale="locale"
            :hour12="hour12"
            :disabled="disabled"
            :min-value="minValue"
            :max-value="maxValue"
            @update:value="emit('update:value', $event)"
        >
            <template #button>
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        :disabled="disabled"
                        :aria-label="buttonLabel"
                        :title="buttonLabel"
                        :class="
                            cn(
                                buttonVariants({
                                    variant: 'outline',
                                    size: 'icon',
                                }),
                                'shrink-0 touch-manipulation rounded-l-none border-l-0',
                            )
                        "
                    >
                        <component :is="icon" class="h-4 w-4" />
                    </button>
                </PopoverTrigger>
            </template>
        </KinetixMaskedDateInput>
    </PopoverAnchor>
</template>
