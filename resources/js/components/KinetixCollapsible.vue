<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import {
    CollapsibleContent,
    CollapsibleRoot,
    CollapsibleTrigger,
} from 'reka-ui';
import { useId } from 'vue';

/**
 * A disclosure section — shadcn-vue new-york-v4's `Collapsible`, built on Reka
 * UI's `Collapsible*`, with the registry's height animation.
 *
 * Reka publishes the measured content height as
 * `--reka-collapsible-content-height` on the content element, which is what
 * makes an actual height animation possible (`height: auto` is not animatable).
 * The keyframes live in this component's own scoped style rather than in the
 * host's CSS, so the animation ships with the component instead of depending on
 * the consumer having declared shadcn's `collapsible-down/up` keyframes.
 *
 * The trigger is a real `<button>` carrying `aria-expanded`, so it is
 * keyboard-operable and announced as a disclosure. (Reka also wires
 * `aria-controls`, but its `contentId` is a non-reactive context value the
 * content assigns during setup, so the trigger only reflects it from its next
 * render — the state itself always rides `aria-expanded`.) Motion respects
 * `prefers-reduced-motion` and the Kinetix `kx-reduce-motion` preference class
 * through the `kinetixAccessibility` plugin's global guard.
 *
 * Slots: `default` (the content), `title`, `summary` (a line that stays visible
 * while collapsed — folded should never mean unknown), and `trigger` to replace
 * the whole header.
 */
const props = withDefaults(
    defineProps<{
        /** Controlled state. Omit to let the component own it. */
        open?: boolean;
        /** Initial state when uncontrolled. */
        defaultOpen?: boolean;
        title?: string | null;
        /** Stays visible while collapsed — the current values, in one line. */
        summary?: string | null;
        disabled?: boolean;
        /** Drop the bordered card chrome and render the trigger + content bare. */
        bare?: boolean;
    }>(),
    {
        open: undefined,
        defaultOpen: false,
        title: null,
        summary: null,
        disabled: false,
        bare: false,
    },
);

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const headingId = `kinetix-collapsible-${useId()}`;
</script>

<template>
    <CollapsibleRoot
        v-slot="{ open: isOpen }"
        :open="props.open"
        :default-open="props.defaultOpen"
        :disabled="props.disabled"
        :class="bare ? '' : 'rounded-xl border border-border'"
        @update:open="emit('update:open', $event)"
    >
        <CollapsibleTrigger
            class="gap-3 flex w-full items-center text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
            :class="bare ? 'rounded-md' : 'px-4 py-3 rounded-xl'"
        >
            <slot name="trigger" :open="isOpen">
                <ChevronDown
                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 motion-reduce:transition-none"
                    :class="isOpen ? 'rotate-0' : '-rotate-90'"
                    aria-hidden="true"
                />
                <span class="min-w-0 flex-1">
                    <span
                        v-if="title"
                        :id="headingId"
                        class="text-sm font-medium block text-foreground"
                    >
                        {{ title }}
                    </span>
                    <span
                        v-if="summary"
                        class="text-xs block truncate text-muted-foreground"
                    >
                        {{ summary }}
                    </span>
                </span>
            </slot>
        </CollapsibleTrigger>

        <CollapsibleContent class="kx-collapsible-content overflow-hidden">
            <div :class="bare ? '' : 'px-4 pt-1 pb-4'">
                <slot />
            </div>
        </CollapsibleContent>
    </CollapsibleRoot>
</template>

<style scoped>
/* shadcn-vue new-york-v4's collapsible animation. `height: auto` cannot be
   animated, so it interpolates to the height Reka measures and publishes as
   `--reka-collapsible-content-height`. */
@keyframes kx-collapsible-down {
    from {
        height: 0;
        opacity: 0;
    }
    to {
        height: var(--reka-collapsible-content-height);
        opacity: 1;
    }
}

@keyframes kx-collapsible-up {
    from {
        height: var(--reka-collapsible-content-height);
        opacity: 1;
    }
    to {
        height: 0;
        opacity: 0;
    }
}

.kx-collapsible-content[data-state='open'] {
    animation: kx-collapsible-down 200ms ease-out;
}

.kx-collapsible-content[data-state='closed'] {
    animation: kx-collapsible-up 200ms ease-out;
}

/* Motion is escapable through the shipped mechanism, not a rule duplicated
   here: the `kinetixAccessibility` plugin injects one `!important` guard that
   collapses every animation's duration under BOTH the OS
   `prefers-reduced-motion` setting and the user's `kx-reduce-motion`
   preference — the same thing that already covers the dialog shells. */
</style>
