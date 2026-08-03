<script setup lang="ts">
import { Accessibility } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { useI18n } from 'vue-i18n';
import { useKinetixAccessibility } from '@/composables/useKinetixAccessibility';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixAccessibility } from '@/types/kinetix';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixLabel from './KinetixLabel.vue';
import { cn } from './primitives/cn';

/**
 * Compact accessibility quick-menu: an icon button that opens a popover with the
 * same preference controls as <KinetixAccessibilityPanel>, but usable anywhere —
 * the header, the login page, the account-setup wizard. Server persistence is
 * best-effort, so it works for guests (preferences are kept client-side).
 */
const { t } = useI18n();
const { prefs, set } = useKinetixAccessibility();

const toggles: { key: keyof KinetixAccessibility; label: string }[] = [
    { key: 'reducedMotion', label: 'a11y_reduced_motion' },
    { key: 'highContrast', label: 'a11y_high_contrast' },
    { key: 'underlineLinks', label: 'a11y_underline_links' },
    { key: 'enhancedFocus', label: 'a11y_enhanced_focus' },
];

const textSizes: KinetixAccessibility['textSize'][] = [
    'normal',
    'large',
    'x-large',
];
const textSizeLabel = {
    normal: 'a11y_text_normal',
    large: 'a11y_text_large',
    'x-large': 'a11y_text_x_large',
} as const;
</script>

<template>
    <PopoverRoot>
        <PopoverTrigger
            :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
            :aria-label="t('kinetix.a11y_title')"
        >
            <Accessibility class="size-[1.2rem]" />
            <span class="sr-only">{{ t('kinetix.a11y_title') }}</span>
        </PopoverTrigger>

        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="8"
                class="w-72 rounded-lg p-4 shadow-lg data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 z-50 border border-border bg-popover text-popover-foreground outline-none"
            >
                <div class="space-y-4">
                    <p class="text-sm font-semibold text-foreground">
                        {{ t('kinetix.a11y_title') }}
                    </p>

                    <!-- Text size -->
                    <fieldset class="space-y-2">
                        <KinetixLabel>{{
                            t('kinetix.a11y_text_size')
                        }}</KinetixLabel>
                        <div
                            class="gap-2 flex"
                            role="radiogroup"
                            :aria-label="t('kinetix.a11y_text_size')"
                        >
                            <button
                                v-for="size in textSizes"
                                :key="size"
                                type="button"
                                role="radio"
                                :aria-checked="prefs.textSize === size"
                                :class="
                                    cn(
                                        buttonVariants({
                                            variant:
                                                prefs.textSize === size
                                                    ? 'default'
                                                    : 'outline',
                                            size: 'sm',
                                        }),
                                        'flex-1',
                                    )
                                "
                                @click="set('textSize', size)"
                            >
                                {{ t(`kinetix.${textSizeLabel[size]}`) }}
                            </button>
                        </div>
                    </fieldset>

                    <!-- Toggles -->
                    <div class="space-y-2.5">
                        <label
                            v-for="item in toggles"
                            :key="item.key"
                            class="gap-3 text-sm flex items-center text-foreground"
                        >
                            <KinetixCheckbox
                                :checked="!!prefs[item.key]"
                                @change="set(item.key, $event as never)"
                            />
                            {{ t(`kinetix.${item.label}`) }}
                        </label>
                    </div>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
