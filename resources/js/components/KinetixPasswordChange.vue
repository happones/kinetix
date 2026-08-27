<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import KinetixLabel from './KinetixLabel.vue';

/**
 * The change-password screen, used in both situations the policy produces:
 * a user who CHOSE to change theirs, and one the `kinetix.password` middleware
 * sent here because their password expired or was issued to them.
 *
 * Mount it from the page named by `kinetix.credentials.passwords.view`
 * (default `Kinetix/PasswordChange`) with the props the controller provides.
 *
 * The current password is not asked for on a TEMPORARY credential: an admin
 * chose that one, so repeating it back proves nothing — the server applies the
 * same rule, this only mirrors it.
 */
const props = withDefaults(
    defineProps<{
        /**
         * The URL the form posts to — provided by the server, like every other
         * Kinetix component, so the package never depends on Ziggy.
         */
        action: string;
        /** The user was handed a temporary password and must replace it. */
        mustChange?: boolean;
        /** The password passed its expiry date. */
        expired?: boolean;
        /** Days until expiry; negative once past, null when it never expires. */
        daysUntilExpiry?: number | null;
        /** How many previous passwords are refused (0 = the rule is off). */
        historyDepth?: number;
    }>(),
    {
        mustChange: false,
        expired: false,
        daysUntilExpiry: null,
        historyDepth: 0,
    },
);

const { t } = useI18n();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

/** A password the user never chose is not a secret worth proving. */
const asksForCurrent = computed(() => !props.mustChange);

/** Why they are here — null when they simply chose to change it. */
const reason = computed<'must-change' | 'expired' | null>(() => {
    if (props.mustChange) {
        return 'must-change';
    }

    return props.expired ? 'expired' : null;
});

function submit(): void {
    form.post(props.action, {
        preserveScroll: true,
        onFinish: () =>
            form.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <div class="max-w-sm space-y-6 mx-auto w-full">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-foreground">
                {{ t('kinetix.password_change_title') }}
            </h1>

            <p
                v-if="reason === 'must-change'"
                class="text-sm text-muted-foreground"
            >
                {{ t('kinetix.password_must_change') }}
            </p>
            <p
                v-else-if="reason === 'expired'"
                class="text-sm text-muted-foreground"
            >
                {{ t('kinetix.password_expired') }}
            </p>
            <p
                v-else-if="daysUntilExpiry !== null"
                class="text-sm text-muted-foreground"
            >
                {{
                    t('kinetix.password_expires_in', { days: daysUntilExpiry })
                }}
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="asksForCurrent" class="space-y-2">
                <KinetixLabel for="kinetix-current-password">
                    {{ t('kinetix.password_current') }}
                </KinetixLabel>
                <input
                    id="kinetix-current-password"
                    v-model="form.current_password"
                    type="password"
                    autocomplete="current-password"
                    required
                    :class="inputClass"
                />
                <p
                    v-if="form.errors.current_password"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.current_password }}
                </p>
            </div>

            <div class="space-y-2">
                <KinetixLabel for="kinetix-new-password">
                    {{ t('kinetix.password_new') }}
                </KinetixLabel>
                <input
                    id="kinetix-new-password"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    :class="inputClass"
                    :aria-describedby="
                        historyDepth > 0 ? 'kinetix-password-hint' : undefined
                    "
                />
                <p
                    v-if="historyDepth > 0"
                    id="kinetix-password-hint"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('kinetix.password_history_hint', {
                            count: historyDepth,
                        })
                    }}
                </p>
                <p v-if="form.errors.password" class="text-xs text-destructive">
                    {{ form.errors.password }}
                </p>
            </div>

            <div class="space-y-2">
                <KinetixLabel for="kinetix-confirm-password">
                    {{ t('kinetix.password_confirm') }}
                </KinetixLabel>
                <input
                    id="kinetix-confirm-password"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    :class="inputClass"
                />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                :class="[buttonVariants(), 'w-full']"
            >
                {{ t('kinetix.password_change_submit') }}
            </button>
        </form>
    </div>
</template>
