<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixCookieConsent } from '@/composables/useKinetixCookieConsent';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';

/**
 * A shadcn-styled cookie consent bar. Mount once in your layout — it shows
 * until the visitor accepts or declines, then remembers the choice via a
 * plain browser cookie (no server round-trip) and stays hidden. Configured
 * via `config('kinetix.cookie_consent')`. A simple accept/decline bar, not a
 * granular per-category consent manager.
 */
const { t } = useI18n();
const { config, visible, checkConsent, accept, decline } =
    useKinetixCookieConsent();

onMounted(checkConsent);

/*
 * While the bar shows it sits over the edge of the page, where a form's last
 * button usually is ("Delete team" was out of reach until the visitor chose).
 * Its height is reserved as padding on <body> so everything can still be
 * scrolled clear of it, and given back when it goes.
 */
const bar = ref<HTMLElement | null>(null);
let observer: ResizeObserver | null = null;
let reserved: 'paddingTop' | 'paddingBottom' | null = null;
let previous = '';

function reserve(height: number): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (reserved !== null) {
        document.body.style[reserved] = previous;
        reserved = null;
    }

    if (height > 0) {
        reserved =
            config.value.position === 'top' ? 'paddingTop' : 'paddingBottom';
        previous = document.body.style[reserved];
        document.body.style[reserved] = `${height}px`;
    }
}

watch(bar, (element) => {
    observer?.disconnect();
    observer = null;

    if (element === null) {
        reserve(0);

        return;
    }

    reserve(element.offsetHeight);

    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(() => reserve(element.offsetHeight));
        observer.observe(element);
    }
});

onBeforeUnmount(() => {
    observer?.disconnect();
    reserve(0);
});
</script>

<template>
    <Transition
        enter-active-class="transition-all duration-300"
        enter-from-class="opacity-0 translate-y-4"
        leave-active-class="transition-all duration-200"
        leave-to-class="opacity-0 translate-y-4"
    >
        <!-- The strip spans the viewport but lets clicks through to the page;
             only the card itself takes them. -->
        <div
            v-if="visible"
            ref="bar"
            class="inset-x-0 p-4 pointer-events-none fixed z-40 flex justify-center"
            :class="config.position === 'top' ? 'top-0' : 'bottom-0'"
            role="region"
            :aria-label="t('kinetix.cookie_consent_message')"
        >
            <div
                class="gap-4 p-4 rounded-xl shadow-2xl max-w-2xl sm:flex-row sm:items-center pointer-events-auto flex w-full flex-col border border-border bg-popover"
            >
                <p class="text-sm flex-1 text-popover-foreground">
                    {{ t('kinetix.cookie_consent_message') }}
                    <a
                        v-if="config.policyUrl"
                        :href="config.policyUrl"
                        class="ml-1 text-popover-foreground underline underline-offset-2 transition-colors hover:text-primary"
                    >
                        {{ t('kinetix.cookie_consent_policy_link') }}
                    </a>
                </p>

                <div class="gap-2 flex shrink-0 items-center justify-end">
                    <button
                        type="button"
                        :class="
                            buttonVariants({ variant: 'outline', size: 'sm' })
                        "
                        @click="decline"
                    >
                        {{ t('kinetix.cookie_consent_decline') }}
                    </button>
                    <button
                        type="button"
                        :class="buttonVariants({ size: 'sm' })"
                        @click="accept"
                    >
                        {{ t('kinetix.cookie_consent_accept') }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>
