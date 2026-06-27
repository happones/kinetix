<script setup lang="ts">
import { Monitor, ShieldCheck, Smartphone, Tablet } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixSessions } from '@/composables/useKinetixSessions';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import KinetixLabel from './KinetixLabel.vue';

/**
 * Drop-in browser-sessions manager: list the user's active devices and log out
 * the others (password-gated when they have a password). Requires
 * SESSION_DRIVER=database for the list.
 */
const { t } = useI18n();
const {
    sessions,
    databaseDriver,
    requiresPassword,
    loading,
    load,
    logoutOthers,
} = useKinetixSessions();

onMounted(load);

const deviceIcon = (device: string) =>
    device === 'mobile' ? Smartphone : device === 'tablet' ? Tablet : Monitor;

function relativeTime(value: string | null): string {
    if (!value) {
        return '';
    }

    const diff = Date.now() - new Date(value).getTime();
    const mins = Math.round(diff / 60000);

    if (mins < 1) {
        return t('kinetix.session_active_now');
    }

    if (mins < 60) {
        return t('kinetix.minutes_ago', { minutes: mins });
    }

    const hours = Math.round(mins / 60);

    if (hours < 24) {
        return t('kinetix.hours_ago', { hours });
    }

    return new Date(value).toLocaleDateString();
}

// Log out other sessions, optionally behind a password prompt.
const confirming = ref(false);
const password = ref('');
const working = ref(false);

async function submit(): Promise<void> {
    working.value = true;

    try {
        await logoutOthers(requiresPassword.value ? password.value : undefined);
        password.value = '';
        confirming.value = false;
        await load();
        toast.success(t('kinetix.sessions_logged_out_others'));
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : t('kinetix.save_failed'),
        );
    } finally {
        working.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-1">
            <h2 class="text-lg font-semibold text-foreground">
                {{ t('kinetix.sessions_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('kinetix.sessions_description') }}
            </p>
        </div>

        <!-- Non-database driver notice -->
        <p
            v-if="!loading && !databaseDriver"
            class="rounded-lg p-4 text-sm border border-border bg-muted/40 text-muted-foreground"
        >
            {{ t('kinetix.sessions_unavailable') }}
        </p>

        <template v-else>
            <ul class="rounded-lg divide-y divide-border border border-border">
                <li
                    v-for="session in sessions"
                    :key="session.id"
                    class="gap-3 p-4 flex items-center"
                >
                    <span
                        class="size-9 flex shrink-0 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground"
                    >
                        <component
                            :is="deviceIcon(session.device)"
                            class="size-5"
                        />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p
                            class="gap-2 text-sm font-medium flex items-center text-foreground"
                        >
                            <span class="truncate"
                                >{{ session.browser }} ·
                                {{ session.platform }}</span
                            >
                            <span
                                v-if="session.isCurrentDevice"
                                class="gap-1 px-2 py-0.5 text-xs font-medium inline-flex items-center rounded-full bg-success/15 text-success"
                            >
                                <ShieldCheck class="size-3" />
                                {{ t('kinetix.session_this_device') }}
                            </span>
                        </p>
                        <p class="text-xs truncate text-muted-foreground">
                            {{ session.ipAddress }}
                            <template
                                v-if="
                                    !session.isCurrentDevice &&
                                    session.lastActive
                                "
                            >
                                · {{ relativeTime(session.lastActive) }}
                            </template>
                        </p>
                    </div>
                </li>

                <li
                    v-if="!loading && sessions.length === 0"
                    class="p-4 text-sm text-muted-foreground"
                >
                    {{ t('kinetix.sessions_empty') }}
                </li>
            </ul>

            <!-- Log out other sessions -->
            <div v-if="sessions.length > 1 || confirming">
                <form
                    v-if="confirming"
                    class="space-y-3 rounded-lg p-4 border border-border bg-card"
                    @submit.prevent="submit"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ t('kinetix.sessions_logout_others_confirm') }}
                    </p>
                    <div v-if="requiresPassword" class="space-y-1.5">
                        <KinetixLabel for="kx-sessions-password">{{
                            t('kinetix.password_current')
                        }}</KinetixLabel>
                        <input
                            id="kx-sessions-password"
                            v-model="password"
                            type="password"
                            autocomplete="current-password"
                            :class="inputClass"
                        />
                    </div>
                    <div class="gap-2 flex justify-end">
                        <button
                            type="button"
                            :class="
                                buttonVariants({ variant: 'ghost', size: 'sm' })
                            "
                            @click="confirming = false"
                        >
                            {{ t('kinetix.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="working"
                            :class="
                                buttonVariants({
                                    variant: 'destructive',
                                    size: 'sm',
                                })
                            "
                        >
                            {{ t('kinetix.sessions_logout_others') }}
                        </button>
                    </div>
                </form>
                <button
                    v-else
                    type="button"
                    :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                    @click="confirming = true"
                >
                    {{ t('kinetix.sessions_logout_others') }}
                </button>
            </div>
        </template>
    </div>
</template>
