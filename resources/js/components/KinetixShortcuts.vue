<script setup lang="ts">
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    listHotkeys,
    useKinetixHotkeys,
} from '@/composables/useKinetixHotkeys';

/**
 * Press `?` to open a cheat-sheet of the active keyboard shortcuts (the labelled
 * ones). Mount once in your authenticated layout. Renders nothing until opened.
 */
const { t } = useI18n();
const { register } = useKinetixHotkeys();

const open = ref(false);

onMounted(() => {
    register({
        keys: '?',
        label: t('kinetix.shortcuts_title'),
        handler: () => (open.value = true),
    });
});

const shortcuts = computed(() => listHotkeys());

/** Render a combo/sequence as separate key chips: "g i" → [g][i], "mod+k" → [mod][k]. */
function chips(keys: string): string[] {
    return keys.split(/\s+/).flatMap((step) => step.split('+'));
}
</script>

<template>
    <DialogRoot :open="open" @update:open="open = $event">
        <DialogPortal>
            <DialogOverlay
                class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 inset-0 bg-black/80 fixed z-[var(--kinetix-z-overlay,100)]"
            />
            <DialogContent
                class="max-w-md rounded-lg p-4 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 fixed top-1/4 left-1/2 z-[var(--kinetix-z-modal,100)] w-full -translate-x-1/2 border bg-background text-foreground duration-200"
            >
                <DialogTitle
                    class="text-lg font-semibold leading-none text-foreground"
                >
                    {{ t('kinetix.shortcuts_title') }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    {{ t('kinetix.shortcuts_title') }}
                </DialogDescription>

                <ul class="mt-3 space-y-2">
                    <li
                        v-for="shortcut in shortcuts"
                        :key="shortcut.id"
                        class="gap-4 text-sm flex items-center justify-between"
                    >
                        <span class="text-foreground">{{
                            shortcut.label
                        }}</span>
                        <span class="gap-1 flex items-center">
                            <kbd
                                v-for="(chip, i) in chips(shortcut.keys)"
                                :key="i"
                                class="rounded px-1.5 py-0.5 font-mono text-xs border border-border bg-muted text-muted-foreground"
                            >
                                {{ chip }}
                            </kbd>
                        </span>
                    </li>
                </ul>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
