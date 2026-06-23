<script setup lang="ts">
import { Toaster, type ToasterProps } from "vue-sonner";

/**
 * Toaster pre-styled with shadcn semantic tokens, so Kinetix toasts (export /
 * import notifications, etc.) read correctly in both light and dark mode.
 *
 * It does NOT fight vue-sonner over CSS specificity (class overrides lose when
 * `vue-sonner/style.css` is loaded after Tailwind). Instead it redefines the
 * very CSS variables vue-sonner reads — `--normal-bg`/`--normal-text`/
 * `--normal-border` — pointing them at shadcn tokens (`--popover`, etc.) that
 * already flip with `.dark`, so the toast follows the host theme automatically.
 *
 * Mount once in your layout: <KinetixToaster />. Forwards all vue-sonner
 * Toaster props (position, richColors, duration, …).
 */
defineProps<ToasterProps>();
</script>

<template>
  <Toaster
    class="toaster group"
    style="
      --normal-bg: var(--popover);
      --normal-text: var(--popover-foreground);
      --normal-border: var(--border);
    "
    v-bind="$props"
  />
</template>
