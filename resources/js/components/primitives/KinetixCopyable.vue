<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import {
    TooltipArrow,
    TooltipContent,
    TooltipPortal,
    TooltipProvider,
    TooltipRoot,
    TooltipTrigger,
} from 'reka-ui';
import { computed, ref, useSlots, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { useKinetixClipboard } from '@/composables/useKinetixClipboard';

/**
 * The click-to-copy trigger — the single home of the copyable recipe; build on
 * THIS, never a re-copied class string. With content in the default slot the
 * whole value is the trigger: a ghost hover surface, a copy glyph that turns
 * into a check, and a v4 tooltip that reads "Copy" and then "Copied!". With no
 * slot it renders an icon-only button, for values that are links or rich HTML
 * and must keep their own clicks. Attrs fall through to the button.
 *
 * It is a real <button>, so a clickable row never activates through it.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps<{
    /** The exact text written to the clipboard. */
    value: string;
}>();

const slots = useSlots();
const { t } = useI18n();
const { announce } = useKinetixAnnounce();
const { status, copy } = useKinetixClipboard();

const open = ref(false);

/**
 * A tap, or a click faster than the hover delay, opens the tooltip only to
 * confirm the copy — it closes again when the confirmation expires. (Focus
 * opens it for the keyboard only, so a tap's focus never pins it open.)
 */
let openedForFeedback = false;

const isIconOnly = computed<boolean>(() => !slots.default);

const label = computed<string>(() => {
    if (status.value === 'copied') {
        return t('kinetix.copied');
    }

    if (status.value === 'failed') {
        return t('kinetix.copy_failed');
    }

    return t('kinetix.copy');
});

const onOpenChange = (value: boolean): void => {
    open.value = value;

    if (!value) {
        openedForFeedback = false;
    }
};

const onClick = async (): Promise<void> => {
    openedForFeedback = !open.value;

    const copied = await copy(props.value);

    open.value = true;
    announce(t(copied ? 'kinetix.copied' : 'kinetix.copy_failed'), !copied);
};

watch(status, (value) => {
    if (value === 'idle' && openedForFeedback) {
        onOpenChange(false);
    }
});
</script>

<template>
    <TooltipProvider :delay-duration="300">
        <TooltipRoot
            :open="open"
            disable-closing-trigger
            ignore-non-keyboard-focus
            @update:open="onOpenChange"
        >
            <TooltipTrigger as-child>
                <button
                    v-bind="$attrs"
                    type="button"
                    :aria-label="isIconOnly ? label : undefined"
                    :data-copied="status === 'copied' ? '' : undefined"
                    :class="
                        isIconOnly
                            ? 'size-6 inline-flex shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-[color,background-color,opacity] outline-none hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:hover:bg-accent/50'
                            : 'group/copyable -mx-1.5 -my-0.5 gap-1.5 px-1.5 py-0.5 inline-flex max-w-full cursor-pointer items-center rounded-md [text-align:inherit] transition-colors outline-none hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:hover:bg-accent/50'
                    "
                    @click="onClick"
                >
                    <slot />
                    <span
                        class="size-3.5 relative inline-flex shrink-0"
                        aria-hidden="true"
                    >
                        <Copy
                            class="size-3.5 transition-[opacity,scale] duration-200"
                            :class="[
                                isIconOnly
                                    ? ''
                                    : 'text-muted-foreground opacity-0 group-hover/copyable:opacity-100 group-focus-visible/copyable:opacity-100 pointer-coarse:opacity-100',
                                status === 'copied'
                                    ? 'scale-50 opacity-0!'
                                    : '',
                            ]"
                        />
                        <Check
                            class="inset-0 size-3.5 absolute text-success transition-[opacity,scale] duration-200"
                            :class="
                                status === 'copied'
                                    ? 'scale-100 opacity-100'
                                    : 'scale-50 opacity-0'
                            "
                        />
                    </span>
                </button>
            </TooltipTrigger>
            <TooltipPortal>
                <TooltipContent
                    :side-offset="4"
                    class="px-3 py-1.5 text-xs data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-[var(--kinetix-z-popover,120)] w-fit rounded-md bg-foreground text-balance text-background"
                >
                    {{ label }}
                    <TooltipArrow
                        class="fill-foreground"
                        :width="10"
                        :height="5"
                    />
                </TooltipContent>
            </TooltipPortal>
        </TooltipRoot>
    </TooltipProvider>
</template>
