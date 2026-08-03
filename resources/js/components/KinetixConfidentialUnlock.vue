<script setup lang="ts">
import { Lock, LockOpen } from '@lucide/vue';
import {
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixConfidential } from '@/composables/useKinetixConfidential';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import KinetixLabel from './KinetixLabel.vue';

/**
 * Header-mountable reveal-gate widget: shows locked/unlocked state and a
 * live countdown. Mount once anywhere in the layout — a masked Table/Infolist
 * cell's lock affordance opens the same dialog via `requestConfidentialUnlock()`.
 */
const { t } = useI18n();
const { config, isUnlocked, remainingSeconds, unlock, lock, dialogOpen } =
    useKinetixConfidential();

const password = ref('');
const error = ref<string | null>(null);
const unlocking = ref(false);

function minutes(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;

    return `${m}:${s.toString().padStart(2, '0')}`;
}

async function onUnlock(): Promise<void> {
    error.value = null;
    unlocking.value = true;

    try {
        await unlock(password.value);
        dialogOpen.value = false;
        password.value = '';
    } catch (e) {
        error.value =
            e instanceof Error
                ? e.message
                : t('kinetix.confidential_password_incorrect');
    } finally {
        unlocking.value = false;
    }
}

async function onLock(): Promise<void> {
    await lock();
}
</script>

<template>
    <div v-if="config.enabled" class="contents">
        <button
            v-if="isUnlocked"
            type="button"
            :class="buttonVariants({ variant: 'outline', size: 'sm' })"
            :aria-label="t('kinetix.confidential_lock')"
            @click="onLock"
        >
            <LockOpen class="size-4" />
            {{ minutes(remainingSeconds) }}
        </button>
        <button
            v-else
            type="button"
            :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
            :aria-label="t('kinetix.confidential_unlock')"
            @click="dialogOpen = true"
        >
            <Lock class="size-4" />
        </button>

        <DialogRoot v-model:open="dialogOpen">
            <DialogPortal>
                <DialogOverlay
                    class="inset-0 bg-black/50 data-[state=open]:animate-in data-[state=open]:fade-in fixed z-50"
                />
                <DialogContent
                    class="max-w-sm rounded-lg p-6 shadow-lg fixed top-1/2 left-1/2 z-50 w-[calc(100vw-2rem)] -translate-x-1/2 -translate-y-1/2 border border-border bg-card"
                >
                    <DialogTitle class="text-lg font-semibold text-foreground">
                        {{ t('kinetix.confidential_unlock') }}
                    </DialogTitle>

                    <div class="mt-4 space-y-2">
                        <KinetixLabel for="kinetix-confidential-password">{{
                            t('kinetix.confidential_password_label')
                        }}</KinetixLabel>
                        <input
                            id="kinetix-confidential-password"
                            v-model="password"
                            type="password"
                            autocomplete="current-password"
                            :class="inputClass"
                            @keyup.enter="onUnlock"
                        />
                    </div>

                    <p
                        v-if="error"
                        class="mt-2 text-xs font-semibold text-destructive"
                    >
                        {{ error }}
                    </p>

                    <div class="mt-6 gap-2 flex justify-end">
                        <button
                            type="button"
                            :class="
                                buttonVariants({
                                    variant: 'outline',
                                    size: 'sm',
                                })
                            "
                            @click="dialogOpen = false"
                        >
                            {{ t('kinetix.cancel') }}
                        </button>
                        <button
                            type="button"
                            :class="buttonVariants({ size: 'sm' })"
                            :disabled="unlocking || !password"
                            @click="onUnlock"
                        >
                            {{ t('kinetix.confidential_unlock') }}
                        </button>
                    </div>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>
    </div>
</template>
