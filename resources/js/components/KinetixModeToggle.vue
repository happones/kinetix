<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuTrigger,
} from 'reka-ui';
import { useI18n } from 'vue-i18n';
import { useKinetixAppearance } from '@/composables/useKinetixAppearance';
import type { KinetixAppearance } from '@/composables/useKinetixAppearance';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';

/**
 * Dark-mode toggle button (Sun/Moon icon) with a Light / Dark / System dropdown.
 * Drop it in your header. Stays in sync with the Laravel starter kit's
 * Appearance settings (shared `appearance` storage).
 */
const { t } = useI18n();
const { appearance, setAppearance } = useKinetixAppearance();

const options: { value: KinetixAppearance; label: string; icon: unknown }[] = [
    { value: 'light', label: 'appearance_light', icon: Sun },
    { value: 'dark', label: 'appearance_dark', icon: Moon },
    { value: 'system', label: 'appearance_system', icon: Monitor },
];
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger
            :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
            :aria-label="t('kinetix.toggle_theme')"
        >
            <Sun
                class="size-[1.2rem] scale-100 rotate-0 transition-all dark:scale-0 dark:-rotate-90"
            />
            <Moon
                class="absolute size-[1.2rem] scale-0 rotate-90 transition-all dark:scale-100 dark:rotate-0"
            />
            <span class="sr-only">{{ t('kinetix.toggle_theme') }}</span>
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                align="end"
                :side-offset="6"
                class="rounded-lg p-1 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 z-50 min-w-[8rem] border border-border bg-popover outline-none"
            >
                <DropdownMenuItem
                    v-for="opt in options"
                    :key="opt.value"
                    class="gap-2 px-3 py-2 text-sm flex w-full cursor-default items-center rounded-md text-left transition-colors outline-none select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground"
                    :class="
                        appearance === opt.value
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                    @click="setAppearance(opt.value)"
                >
                    <component :is="opt.icon" class="size-4" />
                    {{ t(`kinetix.${opt.label}`) }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
