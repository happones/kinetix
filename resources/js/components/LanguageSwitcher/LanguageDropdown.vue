<script setup lang="ts">
import { Check, Languages } from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuTrigger,
} from 'reka-ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixLocaleOption } from '@/types/kinetix';

/**
 * Icon-triggered locale dropdown — the compact variant, for a header or toolbar
 * where a labelled field would be out of place.
 */
const props = withDefaults(
    defineProps<{
        locales: KinetixLocaleOption[];
        current: string;
        saving?: boolean;
        /** Show the active locale's code beside the icon (e.g. "EN"). */
        showLabel?: boolean;
    }>(),
    { saving: false, showLabel: false },
);

const emit = defineEmits<{
    (e: 'select', code: string): void;
}>();

const { t } = useI18n();

const currentCode = computed(() => (props.current ?? '').toUpperCase());
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger
            :class="
                buttonVariants({
                    variant: 'outline',
                    size: props.showLabel ? 'sm' : 'icon-sm',
                })
            "
            :disabled="props.saving"
            :aria-label="t('kinetix.language')"
        >
            <Languages class="size-[1.2rem]" />
            <span v-if="props.showLabel" class="text-sm font-medium">{{
                currentCode
            }}</span>
            <span v-else class="sr-only">{{ t('kinetix.language') }}</span>
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                align="end"
                :side-offset="6"
                class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 z-[var(--kinetix-z-popover,120)] min-w-[9rem] border border-border bg-popover outline-none"
            >
                <DropdownMenuItem
                    v-for="loc in props.locales"
                    :key="loc.code"
                    class="gap-2 px-3 py-2 text-sm flex w-full cursor-default items-center justify-between rounded-md text-left transition-colors outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground"
                    :class="
                        loc.code === props.current
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                    @click="emit('select', loc.code)"
                >
                    <span>{{ loc.label }}</span>
                    <Check
                        v-if="loc.code === props.current"
                        class="size-4 text-primary"
                    />
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
