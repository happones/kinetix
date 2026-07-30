<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useKinetixAccessibility } from '@/composables/useKinetixAccessibility';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixAccessibility } from '@/types/kinetix';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixLabel from './KinetixLabel.vue';
import { cn } from './primitives/cn';

/**
 * Drop-in accessibility preferences panel. Each change is applied to the
 * document immediately and persisted to the user's profile. Mount it on an
 * account / settings page.
 */
const { t } = useI18n();
const { prefs, set } = useKinetixAccessibility();

const toggles: {
    key: keyof KinetixAccessibility;
    label: string;
    help: string;
}[] = [
    {
        key: 'reducedMotion',
        label: 'a11y_reduced_motion',
        help: 'a11y_reduced_motion_help',
    },
    {
        key: 'highContrast',
        label: 'a11y_high_contrast',
        help: 'a11y_high_contrast_help',
    },
    {
        key: 'underlineLinks',
        label: 'a11y_underline_links',
        help: 'a11y_underline_links_help',
    },
    {
        key: 'enhancedFocus',
        label: 'a11y_enhanced_focus',
        help: 'a11y_enhanced_focus_help',
    },
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
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-foreground">
                {{ t('kinetix.a11y_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('kinetix.a11y_description') }}
            </p>
        </div>

        <!-- Text size -->
        <fieldset class="space-y-2">
            <KinetixLabel>{{ t('kinetix.a11y_text_size') }}</KinetixLabel>
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
                        )
                    "
                    @click="set('textSize', size)"
                >
                    {{ t(`kinetix.${textSizeLabel[size]}`) }}
                </button>
            </div>
        </fieldset>

        <!-- Toggles -->
        <div class="space-y-3">
            <label
                v-for="item in toggles"
                :key="item.key"
                class="gap-3 flex items-start"
            >
                <KinetixCheckbox
                    :checked="!!prefs[item.key]"
                    class="mt-0.5"
                    @change="set(item.key, $event as never)"
                />
                <span class="min-w-0">
                    <span class="text-sm font-medium block text-foreground">{{
                        t(`kinetix.${item.label}`)
                    }}</span>
                    <span class="text-sm block text-muted-foreground">{{
                        t(`kinetix.${item.help}`)
                    }}</span>
                </span>
            </label>
        </div>
    </div>
</template>
