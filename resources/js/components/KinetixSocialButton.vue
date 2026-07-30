<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { buttonVariants } from '@/composables/useShadcnVariants';
import { brandFor } from '@/icons/brands';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * A single-provider social-auth button. Renders the provider's brand icon + a
 * label and links to the Kinetix OAuth round-trip — either `login` (guest sign
 * in / register) or `link` (attach to the current user). Pass `href` to override
 * the destination entirely.
 *
 *     <KinetixSocialButton provider="github" mode="login" />
 *     <KinetixSocialButton provider="google" />            <!-- link mode -->
 */
const props = withDefaults(
    defineProps<{
        provider: string;
        /** "login" → guest sign in/register · "link" → attach to current user. */
        mode?: 'login' | 'link';
        label?: string;
        /**
         * Tint the icon with the provider's true brand color. Off by default — the
         * icon inherits the button's text color so it contrasts with the light/dark
         * theme.
         */
        colorized?: boolean;
        /** Full-width button. */
        block?: boolean;
        variant?: 'outline' | 'default' | 'secondary' | 'ghost';
        href?: string;
    }>(),
    {
        mode: 'login',
        label: undefined,
        colorized: false,
        block: true,
        variant: 'outline',
        href: undefined,
    },
);

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();

const brand = computed(() => brandFor(props.provider));

const label = computed(
    () =>
        props.label ??
        t('kinetix.continue_with', { provider: brand.value.label }),
);

const href = computed(() => {
    if (props.href) {
        return props.href;
    }

    const base = `/${kinetixRoutePrefix(page)}/connected-accounts`;

    return props.mode === 'login'
        ? `${base}/login/redirect/${props.provider}`
        : `${base}/redirect/${props.provider}`;
});

// Opt-in true brand color; otherwise inherit currentColor (theme contrast).
// Multicolor marks (e.g. Microsoft) carry their own fills and ignore this.
const iconColor = computed(() =>
    props.colorized && brand.value.color
        ? { color: brand.value.color }
        : undefined,
);
</script>

<template>
    <a
        :href="href"
        :class="[buttonVariants({ variant }), block ? 'w-full' : '', 'gap-2']"
    >
        <span
            class="size-5 inline-flex items-center justify-center"
            :style="iconColor"
        >
            <component :is="brand.icon" class="size-5" />
        </span>
        <span>{{ label }}</span>
    </a>
</template>
