<script setup lang="ts">
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import type {
    KinetixImportMode,
    KinetixImportModeValue,
} from '@/types/kinetix';
import KinetixAlert from '../KinetixAlert.vue';

/**
 * What the import does with the records already there — one card per mode the
 * importer offers, each saying what it does in a line. A single mode still
 * shows (checked), so the user knows what the import is about to do. A mode
 * that deactivates records (Replace) repeats its consequence in a warning once
 * chosen, so it is never picked on a label alone.
 *
 * The cards pair up by the picker's own width (a container query), not the
 * viewport's: the dialog it sits in doesn't follow the screen.
 */
const props = defineProps<{
    modes: KinetixImportMode[];
    value: KinetixImportModeValue | null;
}>();

const emit = defineEmits<{
    (e: 'update:value', value: KinetixImportModeValue): void;
}>();

const { t } = useI18n();

const headingId = `kinetix-import-mode-${useId()}`;

const destructive = computed(
    () =>
        props.modes.find((mode) => mode.value === props.value)?.destructive ??
        false,
);
</script>

<template>
    <div class="gap-2 @container flex flex-col">
        <p :id="headingId" class="text-sm font-medium text-foreground">
            {{ t('kinetix.import_mode_heading') }}
        </p>

        <RadioGroupRoot
            :model-value="value ?? ''"
            class="gap-2 @md:grid-cols-2 grid grid-cols-1"
            :aria-labelledby="headingId"
            @update:model-value="
                emit('update:value', $event as KinetixImportModeValue)
            "
        >
            <RadioGroupItem
                v-for="mode in modes"
                :key="mode.value"
                :value="mode.value"
                class="gap-3 p-3 rounded-lg min-h-11 flex w-full cursor-pointer items-start border text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[state=unchecked]:border-border data-[state=unchecked]:hover:bg-accent/50"
                :class="
                    mode.destructive
                        ? 'data-[state=checked]:border-destructive data-[state=checked]:bg-destructive/5'
                        : 'data-[state=checked]:border-primary data-[state=checked]:bg-primary/5'
                "
                :data-test="`import-mode-${mode.value}`"
            >
                <span
                    aria-hidden="true"
                    class="mt-0.5 size-4 flex shrink-0 items-center justify-center rounded-full border border-input"
                >
                    <span
                        v-if="mode.value === value"
                        class="size-2 rounded-full"
                        :class="
                            mode.destructive ? 'bg-destructive' : 'bg-primary'
                        "
                    />
                </span>
                <span class="min-w-0">
                    <span class="text-sm font-medium block text-foreground">
                        {{ mode.label }}
                    </span>
                    <span class="text-xs block text-muted-foreground">
                        {{ mode.description }}
                    </span>
                </span>
            </RadioGroupItem>
        </RadioGroupRoot>

        <KinetixAlert v-if="destructive" color="warning">
            {{ t('kinetix.import_mode_replace_warning') }}
        </KinetixAlert>
    </div>
</template>
