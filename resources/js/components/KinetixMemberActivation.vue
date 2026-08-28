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
 * The public set-password screen a provisioned member lands on from their
 * activation link. The identifier is fixed (it came from the provision); the
 * member only picks a name and password. `action` is the signed URL the server passed in —
 * the form posts back to it, so the signature is preserved.
 *
 * Mount it from the page named by `kinetix.membership.activation_view`
 * (default `Kinetix/MemberActivation`), passing the `identifier` and `action`
 * props the Membership controller provides.
 */
const props = withDefaults(
    defineProps<{
        /**
         * Whichever field identifies this member — an email, a username or a
         * phone. Prefer it over `email`, which is null for staff who have none.
         */
        identifier?: string | null;
        email?: string | null;
        action: string;
    }>(),
    { identifier: null, email: null },
);

/** What to show the person so they know whose account they are activating. */
const shownIdentifier = computed(() => props.identifier ?? props.email ?? '');

const { t } = useI18n();

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

function submit(): void {
    form.post(props.action);
}
</script>

<template>
    <div class="max-w-sm space-y-6 mx-auto w-full">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-foreground">
                {{ t('kinetix.activation_title') }}
            </h1>
            <p v-if="shownIdentifier" class="text-sm text-muted-foreground">
                {{ shownIdentifier }}
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-2">
                <KinetixLabel for="activation-name">
                    {{ t('kinetix.activation_name') }}
                </KinetixLabel>
                <input
                    id="activation-name"
                    v-model="form.name"
                    type="text"
                    required
                    :class="inputClass"
                />
                <p v-if="form.errors.name" class="text-xs text-destructive">
                    {{ form.errors.name }}
                </p>
            </div>

            <div class="space-y-2">
                <KinetixLabel for="activation-password">
                    {{ t('kinetix.activation_password') }}
                </KinetixLabel>
                <input
                    id="activation-password"
                    v-model="form.password"
                    type="password"
                    required
                    :class="inputClass"
                />
                <p v-if="form.errors.password" class="text-xs text-destructive">
                    {{ form.errors.password }}
                </p>
            </div>

            <div class="space-y-2">
                <KinetixLabel for="activation-password-confirm">
                    {{ t('kinetix.activation_password_confirm') }}
                </KinetixLabel>
                <input
                    id="activation-password-confirm"
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    :class="inputClass"
                />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                :class="[buttonVariants(), 'w-full']"
            >
                {{ t('kinetix.activation_submit') }}
            </button>
        </form>
    </div>
</template>
