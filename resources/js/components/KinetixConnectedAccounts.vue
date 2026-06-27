<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useKinetixConnectedAccounts } from "@/composables/useKinetixConnectedAccounts";
import { buttonVariants, inputClass } from "@/composables/useShadcnVariants";
import type {
  KinetixConnectedAccount,
  KinetixConnectedProvider,
} from "@/types";
import KinetixLabel from "./KinetixLabel.vue";

/**
 * Drop-in connected-accounts manager: link/unlink OAuth providers and, for a
 * social-only user without a password, set one so email + password login also
 * works. Mount on a security/account settings page.
 */
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
function accountFor(
  key: string,
): KinetixConnectedAccount | undefined {
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
    toast.success(t("kinetix.connected_account_unlinked"));
  } catch (error) {
    toast.error(
      error instanceof Error ? error.message : t("kinetix.delete_failed"),
    );
  }
}

// Set / change password (social-only users have no password yet).
const showPasswordForm = ref(false);
const currentPassword = ref("");
const password = ref("");
const passwordConfirmation = ref("");
const savingPassword = ref(false);

async function onSetPassword(): Promise<void> {
  savingPassword.value = true;
  try {
    await setPassword({
      password: password.value,
      password_confirmation: passwordConfirmation.value,
      ...(hasPassword.value ? { current_password: currentPassword.value } : {}),
    });
    currentPassword.value = "";
    password.value = "";
    passwordConfirmation.value = "";
    showPasswordForm.value = false;
    toast.success(t("kinetix.password_updated"));
  } catch (error) {
    toast.error(
      error instanceof Error ? error.message : t("kinetix.save_failed"),
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
        {{ t("kinetix.connected_accounts_title") }}
      </h2>
      <p class="text-sm text-muted-foreground">
        {{ t("kinetix.connected_accounts_description") }}
      </p>
    </div>

    <!-- Provider list -->
    <ul class="divide-y divide-border rounded-lg border border-border">
      <li
        v-for="provider in providers"
        :key="provider.key"
        class="flex items-center gap-3 p-4"
      >
        <span
          class="flex size-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 text-foreground"
          :style="provider.color ? { color: provider.color } : undefined"
        >
          <!-- GitHub -->
          <svg
            v-if="provider.icon === 'github'"
            viewBox="0 0 24 24"
            class="size-5"
            aria-hidden="true"
          >
            <path
              fill="currentColor"
              d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"
            />
          </svg>
          <!-- Google -->
          <svg
            v-else-if="provider.icon === 'google'"
            viewBox="0 0 24 24"
            class="size-5"
            aria-hidden="true"
          >
            <path
              fill="currentColor"
              d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z"
            />
          </svg>
          <!-- Fallback: provider initial -->
          <span v-else class="text-sm font-semibold uppercase">{{
            provider.label.charAt(0)
          }}</span>
        </span>

        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-foreground">
            {{ provider.label }}
          </p>
          <p
            v-if="provider.linked"
            class="truncate text-xs text-muted-foreground"
          >
            {{
              accountFor(provider.key)?.email ||
              accountFor(provider.key)?.nickname ||
              t("kinetix.connected_account_connected")
            }}
          </p>
          <p v-else class="text-xs text-muted-foreground">
            {{ t("kinetix.connected_account_not_connected") }}
          </p>
        </div>

        <!-- Unlink (with inline confirm) -->
        <template v-if="provider.linked">
          <div
            v-if="confirmingUnlink === provider.key"
            class="flex items-center gap-2"
          >
            <button
              type="button"
              :class="buttonVariants({ variant: 'destructive', size: 'sm' })"
              @click="onDisconnect(provider)"
            >
              {{ t("kinetix.confirm") }}
            </button>
            <button
              type="button"
              :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
              @click="confirmingUnlink = null"
            >
              {{ t("kinetix.cancel") }}
            </button>
          </div>
          <button
            v-else
            type="button"
            :class="buttonVariants({ variant: 'outline', size: 'sm' })"
            @click="confirmingUnlink = provider.key"
          >
            {{ t("kinetix.connected_account_disconnect") }}
          </button>
        </template>

        <!-- Link (full-page OAuth redirect) -->
        <a
          v-else
          :href="connectUrl(provider.key)"
          :class="buttonVariants({ variant: 'outline', size: 'sm' })"
        >
          {{ t("kinetix.connected_account_connect") }}
        </a>
      </li>

      <li
        v-if="!loading && providers.length === 0"
        class="p-4 text-sm text-muted-foreground"
      >
        {{ t("kinetix.connected_account_none_available") }}
      </li>
    </ul>

    <!-- Password — set one when signing in via a provider only -->
    <div class="space-y-3 rounded-lg border border-border bg-card p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="space-y-1">
          <p class="text-sm font-medium text-foreground">
            {{
              hasPassword
                ? t("kinetix.password_change_title")
                : t("kinetix.password_set_title")
            }}
          </p>
          <p class="text-xs text-muted-foreground">
            {{
              hasPassword
                ? t("kinetix.password_change_description")
                : t("kinetix.password_set_description")
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
              ? t("kinetix.password_change_title")
              : t("kinetix.password_set_title")
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
            t("kinetix.password_current")
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
            t("kinetix.password_new")
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
            t("kinetix.password_confirm")
          }}</KinetixLabel>
          <input
            id="kx-confirm-password"
            v-model="passwordConfirmation"
            type="password"
            autocomplete="new-password"
            :class="inputClass"
          />
        </div>
        <div class="flex justify-end gap-2">
          <button
            type="button"
            :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
            @click="showPasswordForm = false"
          >
            {{ t("kinetix.cancel") }}
          </button>
          <button
            type="submit"
            :disabled="savingPassword"
            :class="buttonVariants({ size: 'sm' })"
          >
            {{ t("kinetix.save") }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
