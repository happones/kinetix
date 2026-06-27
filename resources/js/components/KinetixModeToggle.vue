<script setup lang="ts">
import { Monitor, Moon, Sun } from "@lucide/vue";
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuTrigger,
} from "reka-ui";
import { useI18n } from "vue-i18n";
import { useKinetixAppearance, type KinetixAppearance } from "@/composables/useKinetixAppearance";
import { buttonVariants } from "@/composables/useShadcnVariants";

/**
 * Dark-mode toggle button (Sun/Moon icon) with a Light / Dark / System dropdown.
 * Drop it in your header. Stays in sync with the Laravel starter kit's
 * Appearance settings (shared `appearance` storage).
 */
const { t } = useI18n();
const { appearance, setAppearance } = useKinetixAppearance();

const options: { value: KinetixAppearance; label: string; icon: unknown }[] = [
  { value: "light", label: "appearance_light", icon: Sun },
  { value: "dark", label: "appearance_dark", icon: Moon },
  { value: "system", label: "appearance_system", icon: Monitor },
];
</script>

<template>
  <DropdownMenuRoot>
    <DropdownMenuTrigger
      :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
      :aria-label="t('kinetix.toggle_theme')"
    >
      <Sun
        class="size-[1.2rem] rotate-0 scale-100 transition-all dark:-rotate-90 dark:scale-0"
      />
      <Moon
        class="absolute size-[1.2rem] rotate-90 scale-0 transition-all dark:rotate-0 dark:scale-100"
      />
      <span class="sr-only">{{ t("kinetix.toggle_theme") }}</span>
    </DropdownMenuTrigger>

    <DropdownMenuPortal>
      <DropdownMenuContent
        align="end"
        :side-offset="6"
        class="z-50 min-w-[8rem] rounded-lg border border-border bg-popover p-1 shadow-lg outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95"
      >
        <DropdownMenuItem
          v-for="opt in options"
          :key="opt.value"
          class="flex w-full cursor-default items-center gap-2 rounded-md px-3 py-2 text-left text-sm outline-none transition-colors select-none hover:bg-accent focus:bg-accent focus:text-accent-foreground"
          :class="appearance === opt.value ? 'text-foreground' : 'text-muted-foreground'"
          @click="setAppearance(opt.value)"
        >
          <component :is="opt.icon" class="size-4" />
          {{ t(`kinetix.${opt.label}`) }}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
