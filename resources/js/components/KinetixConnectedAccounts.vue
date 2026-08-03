<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixConnectedAccounts } from '@/composables/useKinetixConnectedAccounts';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import { brandFor } from '@/icons/kinetixBrands';
import type {
    KinetixConnectedAccount,
    KinetixConnectedProvider,
} from '@/types/kinetix';
import KinetixLabel from './KinetixLabel.vue';

/**
 * Drop-in connected-accounts manager: link/unlink OAuth providers and, for a
 * social-only user without a password, set one so email + password login also
 * works. Mount on a security/account settings page.
 */
withDefaults(
    defineProps<{
        /** Tint provider icons with their true brand color (default: theme contrast). */
        colorized?: boolean;
    }>(),
    { colorized: false },
);

const { t } = useI18n();
const {
    accounts,
    providers,
    hasPassword,
    loading,
    load,
    connectUrl,
    disconnect,
    setPassword,
} = useKinetixConnectedAccounts();

onMounted(load);

const confirmingUnlink = ref<string | null>(null);

/** The linked account for a provider key, if any. */
function accountFor(key: string): KinetixConnectedAccount | undefined {
    return accounts.value.find((a) => a.provider === key);
}

async function onDisconnect(provider: KinetixConnectedProvider): Promise<void> {
    const account = accountFor(provider.key);

    if (!account) {
        return;
    }

    try {
        await disconnect(account);
        await load();
        confirmingUnlink.value = null;
        toast.success(t('kinetix.connected_account_unlinked'));
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : t('kinetix.delete_failed'),
        );
    }
}

// Set / change password (social-only users have no password yet).
const showPasswordForm = ref(false);
const currentPassword = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const savingPassword = ref(false);

async function onSetPassword(): Promise<void> {
    savingPassword.value = true;

    try {
        await setPassword({
            password: password.value,
            password_confirmation: passwordConfirmation.value,
            ...(hasPassword.value
                ? { current_password: currentPassword.value }
                : {}),
        });
        currentPassword.value = '';
        password.value = '';
        passwordConfirmation.value = '';
        showPasswordForm.value = false;
        toast.success(t('kinetix.password_updated'));
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : t('kinetix.save_failed'),
        );
    } finally {
        savingPassword.value = false;
    }
}
</script>

<template>
    <div class="space-y-6">
        <div class="space-y-1">
            <h2 class="text-lg font-semibold text-foreground">
                {{ t('kinetix.connected_accounts_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('kinetix.connected_accounts_description') }}
            </p>
        </div>

        <!-- Provider list -->
        <ul class="rounded-lg divide-y divide-border border border-border">
            <li
                v-for="provider in providers"
                :key="provider.key"
                class="gap-3 p-4 flex items-center"
            >
                <span
                    class="size-9 flex shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 text-foreground"
                    :style="
                        colorized && provider.color
                            ? { color: provider.color }
                            : undefined
                    "
                >
                    <component
                        :is="brandFor(provider.icon ?? provider.key).icon"
                        class="size-5"
                    />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium truncate text-foreground">
                        {{ provider.label }}
                    </p>
                    <p
                        v-if="provider.linked"
                        class="text-xs truncate text-muted-foreground"
                    >
                        {{
                            accountFor(provider.key)?.email ||
                            accountFor(provider.key)?.nickname ||
                            t('kinetix.connected_account_connected')
                        }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        {{ t('kinetix.connected_account_not_connected') }}
                    </p>
                </div>

                <!-- Unlink (with inline confirm) -->
                <template v-if="provider.linked">
                    <div
                        v-if="confirmingUnlink === provider.key"
                        class="gap-2 flex items-center"
                    >
                        <button
                            type="button"
                            :class="
                                buttonVariants({
                                    variant: 'destructive',
                                    size: 'sm',
                                })
                            "
                            @click="onDisconnect(provider)"
                        >
                            {{ t('kinetix.confirm') }}
                        </button>
                        <button
                            type="button"
                            :class="
                                buttonVariants({ variant: 'ghost', size: 'sm' })
                            "
                            @click="confirmingUnlink = null"
                        >
                            {{ t('kinetix.cancel') }}
                        </button>
                    </div>
                    <button
                        v-else
                        type="button"
                        :class="
                            buttonVariants({ variant: 'outline', size: 'sm' })
                        "
                        @click="confirmingUnlink = provider.key"
                    >
                        {{ t('kinetix.connected_account_disconnect') }}
                    </button>
                </template>

                <!-- Link (full-page OAuth redirect) -->
                <a
                    v-else
                    :href="connectUrl(provider.key)"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                >
                    {{ t('kinetix.connected_account_connect') }}
                </a>
            </li>

            <li
                v-if="!loading && providers.length === 0"
                class="p-4 text-sm text-muted-foreground"
            >
                {{ t('kinetix.connected_account_none_available') }}
            </li>
        </ul>

        <!-- Password — set one when signing in via a provider only -->
        <div class="space-y-3 rounded-lg p-4 border border-border bg-card">
            <div class="gap-3 flex items-start justify-between">
                <div class="space-y-1">
                    <p class="text-sm font-medium text-foreground">
                        {{
                            hasPassword
                                ? t('kinetix.password_change_title')
                                : t('kinetix.password_set_title')
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            hasPassword
                                ? t('kinetix.password_change_description')
                                : t('kinetix.password_set_description')
                        }}
                    </p>
                </div>
                <button
                    v-if="!showPasswordForm"
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    @click="showPasswordForm = true"
                >
                    {{
                        hasPassword
                            ? t('kinetix.password_change_title')
                            : t('kinetix.password_set_title')
                    }}
                </button>
            </div>

            <form
                v-if="showPasswordForm"
                class="space-y-3"
                @submit.prevent="onSetPassword"
            >
                <div v-if="hasPassword" class="space-y-1.5">
                    <KinetixLabel for="kx-current-password">{{
                        t('kinetix.password_current')
                    }}</KinetixLabel>
                    <input
                        id="kx-current-password"
                        v-model="currentPassword"
                        type="password"
                        autocomplete="current-password"
                        :class="inputClass"
                    />
                </div>
                <div class="space-y-1.5">
                    <KinetixLabel for="kx-new-password">{{
                        t('kinetix.password_new')
                    }}</KinetixLabel>
                    <input
                        id="kx-new-password"
                        v-model="password"
                        type="password"
                        autocomplete="new-password"
                        :class="inputClass"
                    />
                </div>
                <div class="space-y-1.5">
                    <KinetixLabel for="kx-confirm-password">{{
                        t('kinetix.password_confirm')
                    }}</KinetixLabel>
                    <input
                        id="kx-confirm-password"
                        v-model="passwordConfirmation"
                        type="password"
                        autocomplete="new-password"
                        :class="inputClass"
                    />
                </div>
                <div class="gap-2 flex justify-end">
                    <button
                        type="button"
                        :class="
                            buttonVariants({ variant: 'ghost', size: 'sm' })
                        "
                        @click="showPasswordForm = false"
                    >
                        {{ t('kinetix.cancel') }}
                    </button>
                    <button
                        type="submit"
                        :disabled="savingPassword"
                        :class="buttonVariants({ size: 'sm' })"
                    >
                        {{ t('kinetix.save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
