<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixCookieConsent } from '@/composables/useKinetixCookieConsent';
import { buttonVariants } from '@/composables/useShadcnVariants';

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
</script>

<template>
    <Transition
        enter-active-class="transition-all duration-300"
        enter-from-class="opacity-0 translate-y-4"
        leave-active-class="transition-all duration-200"
        leave-to-class="opacity-0 translate-y-4"
    >
        <div
            v-if="visible"
            class="inset-x-0 p-4 fixed z-40 flex justify-center"
            :class="config.position === 'top' ? 'top-0' : 'bottom-0'"
            role="region"
            :aria-label="t('kinetix.cookie_consent_message')"
        >
            <div
                class="gap-4 p-4 rounded-xl shadow-2xl max-w-2xl sm:flex-row sm:items-center flex w-full flex-col border border-border bg-popover"
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
