<script setup lang="ts">
import {
    AlertCircle,
    CheckCircle2,
    QrCode,
    RefreshCw,
    Smartphone,
} from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixChannelUpdate } from '@/composables/useKinetixChannels';
import { useKinetixChannels } from '@/composables/useKinetixChannels';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixChannel, KinetixChannelLink } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixCopyable from '../primitives/KinetixCopyable.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

/**
 * Linking a line by scanning a QR — or, on a phone that can't scan this
 * screen, by typing a pairing code — until the provider reports it linked.
 * The QR refreshes itself before it expires (a few times, then waits for a
 * click); the status is polled while the dialog is open, and a broadcast
 * `update` lands at once.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        channel: KinetixChannel | null;
        /** Whether the provider offers a pairing code besides the QR. */
        pairing?: boolean;
        /** The first QR, when the line was just created with one. */
        initialLink?: KinetixChannelLink | null;
        /** The latest broadcast for this line (a new QR, a status). */
        update?: KinetixChannelUpdate | null;
    }>(),
    { pairing: true, initialLink: null, update: null },
);

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'linked', channel: KinetixChannel): void;
}>();

const { t } = useI18n();
const { link, status } = useKinetixChannels();

/** Auto-refreshes before the QR waits for a click: nobody may be looking. */
const MAX_REFRESHES = 4;
const POLL_MS = 3000;

const mode = ref<'qr' | 'phone'>('qr');
const qr = ref<string | null>(null);
const pairingCode = ref<string | null>(null);
const phone = ref('');
const loading = ref(false);
const error = ref<string | null>(null);
const remaining = ref(0);
const total = ref(0);
const refreshes = ref(0);
const linked = ref(false);
const handle = ref<string | null>(null);

let tick: ReturnType<typeof setInterval> | null = null;
let poll: ReturnType<typeof setTimeout> | null = null;

const expired = computed(
    () => mode.value === 'qr' && !!qr.value && remaining.value <= 0,
);
const progress = computed(() =>
    total.value > 0 ? Math.max(0, remaining.value / total.value) * 100 : 0,
);
const formattedCode = computed(() =>
    pairingCode.value && pairingCode.value.length === 8
        ? `${pairingCode.value.slice(0, 4)}-${pairingCode.value.slice(4)}`
        : pairingCode.value,
);

function startCountdown(seconds: number | null): void {
    stopCountdown();
    total.value = seconds ?? 0;
    remaining.value = seconds ?? 0;

    if (!seconds) {
        return;
    }

    tick = setInterval(() => {
        remaining.value -= 1;

        if (remaining.value <= 0) {
            stopCountdown();

            if (mode.value === 'qr' && refreshes.value < MAX_REFRESHES) {
                refreshes.value += 1;
                void fetchLink();
            }
        }
    }, 1000);
}

function stopCountdown(): void {
    if (tick) {
        clearInterval(tick);
        tick = null;
    }
}

function apply(next: KinetixChannelLink | null): void {
    if (!next) {
        return;
    }

    if (next.status === 'connected') {
        markLinked(props.channel?.handle ?? null);

        return;
    }

    qr.value = next.qr ?? qr.value;
    pairingCode.value = next.pairingCode ?? pairingCode.value;
    startCountdown(next.expiresIn);
}

async function fetchLink(withPhone?: string): Promise<void> {
    if (!props.channel) {
        return;
    }

    loading.value = true;
    error.value = null;

    try {
        apply(await link(props.channel, withPhone ?? null));
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        loading.value = false;
    }
}

/** A new code on demand: also restarts the automatic refreshes. */
function refresh(): void {
    refreshes.value = 0;
    void fetchLink();
}

function requestCode(): void {
    pairingCode.value = null;
    void fetchLink(phone.value);
}

function markLinked(linkedHandle: string | null): void {
    linked.value = true;
    handle.value = linkedHandle;
    stopCountdown();
    stopPolling();
}

function schedulePoll(): void {
    stopPolling();
    poll = setTimeout(async () => {
        // A hidden tab doesn't need the answer yet.
        if (props.channel && !document.hidden) {
            try {
                const latest = await status(props.channel);

                if (latest?.status === 'connected') {
                    markLinked(latest.handle);
                    emit('linked', latest);

                    return;
                }
            } catch {
                // A missed poll is retried on the next one.
            }
        }

        schedulePoll();
    }, POLL_MS);
}

function stopPolling(): void {
    if (poll) {
        clearTimeout(poll);
        poll = null;
    }
}

/**
 * On a phone, the screen showing the QR is usually the phone to link — it
 * can't scan itself — so linking starts on the pairing code there.
 */
function startingMode(): 'qr' | 'phone' {
    const onPhone =
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(pointer: coarse) and (max-width: 640px)').matches;

    return props.pairing && onPhone ? 'phone' : 'qr';
}

function reset(): void {
    mode.value = startingMode();
    qr.value = null;
    pairingCode.value = null;
    error.value = null;
    refreshes.value = 0;
    linked.value = false;
    handle.value = null;
}

function stop(): void {
    stopCountdown();
    stopPolling();
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            stop();

            return;
        }

        reset();

        if (props.channel?.status === 'connected') {
            markLinked(props.channel.handle);

            return;
        }

        if (mode.value === 'phone') {
            // Nothing to fetch until the number is typed.
        } else if (props.initialLink?.qr) {
            apply(props.initialLink);
        } else {
            void fetchLink();
        }

        schedulePoll();
    },
    { immediate: true },
);

watch(
    () => props.update,
    (update) => {
        if (!props.open || !update || update.id !== props.channel?.id) {
            return;
        }

        if (update.status === 'connected') {
            markLinked(update.handle);

            if (props.channel) {
                emit('linked', {
                    ...props.channel,
                    status: 'connected',
                    handle: update.handle,
                });
            }
        } else if (update.qr && mode.value === 'qr') {
            qr.value = update.qr;
            startCountdown(total.value || 30);
        }
    },
);

watch(mode, (next) => {
    error.value = null;

    // Opening the dialog fetches its own first code (loading by now).
    if (next === 'qr' && !qr.value && !loading.value) {
        refresh();
    }

    if (next === 'phone') {
        stopCountdown();
    }
});

onUnmounted(stop);

const steps = computed(() => [
    t('kinetix.channel_link_step_open'),
    t('kinetix.channel_link_step_devices'),
    t('kinetix.channel_link_step_link'),
    mode.value === 'qr'
        ? t('kinetix.channel_link_step_scan')
        : t('kinetix.channel_link_step_phone'),
]);
</script>

<template>
    <KinetixModal
        :open="open"
        :title="
            linked
                ? null
                : t('kinetix.channel_link_title', { name: channel?.name ?? '' })
        "
        :description="linked ? null : t('kinetix.channel_link_description')"
        max-width="sm:max-w-2xl"
        @update:open="emit('update:open', $event)"
    >
        <!-- Linked -->
        <div
            v-if="linked"
            class="gap-3 py-6 flex flex-col items-center text-center"
            role="status"
            data-test="channel-linked"
        >
            <span
                class="size-14 motion-safe:animate-in motion-safe:zoom-in-50 inline-flex items-center justify-center rounded-full bg-success/15 text-success"
            >
                <CheckCircle2 class="size-8" />
            </span>
            <h3 class="text-lg font-semibold text-foreground">
                {{ t('kinetix.channel_linked_title') }}
            </h3>
            <p class="text-sm max-w-sm text-muted-foreground">
                {{
                    t('kinetix.channel_linked_description', {
                        handle: handle ?? channel?.name ?? '',
                    })
                }}
            </p>
            <button
                type="button"
                :class="buttonVariants({ size: 'sm' })"
                class="mt-2"
                @click="emit('update:open', false)"
            >
                {{ t('kinetix.channel_done') }}
            </button>
        </div>

        <div v-else class="gap-6 sm:grid-cols-[1fr_auto] grid grid-cols-1">
            <!-- How to -->
            <div class="space-y-4">
                <ol class="space-y-3">
                    <li
                        v-for="(step, index) in steps"
                        :key="index"
                        class="gap-3 text-sm flex items-start text-foreground"
                    >
                        <span
                            class="size-6 text-xs font-semibold inline-flex shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                            aria-hidden="true"
                            >{{ index + 1 }}</span
                        >
                        <span class="pt-0.5">{{ step }}</span>
                    </li>
                </ol>

                <button
                    v-if="pairing"
                    type="button"
                    class="gap-1.5 text-sm font-medium inline-flex items-center text-primary underline-offset-4 hover:underline focus-visible:underline focus-visible:outline-none"
                    data-test="channel-link-mode"
                    @click="mode = mode === 'qr' ? 'phone' : 'qr'"
                >
                    <component
                        :is="mode === 'qr' ? Smartphone : QrCode"
                        class="size-4"
                    />
                    {{
                        mode === 'qr'
                            ? t('kinetix.channel_use_phone')
                            : t('kinetix.channel_use_qr')
                    }}
                </button>
            </div>

            <!-- QR -->
            <div v-if="mode === 'qr'" class="gap-3 flex flex-col items-center">
                <div
                    class="size-60 rounded-xl p-3 bg-white shadow-xs relative flex items-center justify-center border border-border"
                >
                    <img
                        v-if="qr"
                        :src="qr"
                        :alt="
                            t('kinetix.channel_qr_alt', {
                                name: channel?.name ?? '',
                            })
                        "
                        class="size-full object-contain transition-[filter,opacity] duration-200"
                        :class="{ 'opacity-30 blur-[2px]': expired || loading }"
                        data-test="channel-qr"
                    />
                    <div
                        v-else-if="!error"
                        class="motion-safe:animate-pulse size-full rounded-md bg-muted"
                        aria-hidden="true"
                    />

                    <button
                        v-if="expired && !loading"
                        type="button"
                        :class="buttonVariants({ size: 'sm' })"
                        class="absolute"
                        @click="refresh"
                    >
                        <RefreshCw class="size-4" />
                        {{ t('kinetix.channel_qr_refresh') }}
                    </button>
                </div>

                <!-- Time left on this code: a draining bar, read out only on demand. -->
                <div v-if="qr && !expired" class="w-60 space-y-1.5">
                    <div
                        class="h-1 overflow-hidden rounded-full bg-muted"
                        aria-hidden="true"
                    >
                        <div
                            class="h-full rounded-full bg-primary transition-[width] duration-1000 ease-linear"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                    <p
                        class="text-xs text-center text-muted-foreground tabular-nums"
                    >
                        {{
                            t('kinetix.channel_qr_refresh_in', {
                                seconds: remaining,
                            })
                        }}
                    </p>
                </div>

                <p
                    v-if="qr && !expired"
                    class="gap-2 text-xs inline-flex items-center text-muted-foreground"
                    role="status"
                >
                    <span
                        class="size-2 motion-safe:animate-pulse rounded-full bg-warning"
                        aria-hidden="true"
                    />
                    {{ t('kinetix.channel_waiting') }}
                </p>
            </div>

            <!-- Pairing code -->
            <div v-else class="sm:w-64 space-y-3">
                <form class="space-y-2" @submit.prevent="requestCode">
                    <KinetixLabel for="kinetix-channel-phone">{{
                        t('kinetix.channel_phone')
                    }}</KinetixLabel>
                    <input
                        id="kinetix-channel-phone"
                        v-model="phone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        placeholder="+52 55 1234 5678"
                        aria-describedby="kinetix-channel-phone-help"
                        :class="inputClass"
                    />
                    <p
                        id="kinetix-channel-phone-help"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('kinetix.channel_phone_help') }}
                    </p>
                    <KinetixButton
                        type="submit"
                        size="sm"
                        class="w-full"
                        :loading="loading"
                        :disabled="phone.replace(/\D/g, '').length < 8"
                    >
                        {{
                            pairingCode
                                ? t('kinetix.channel_qr_refresh')
                                : t('kinetix.channel_pairing_get')
                        }}
                    </KinetixButton>
                </form>

                <div
                    v-if="formattedCode"
                    class="rounded-xl p-4 space-y-2 border border-border bg-muted/40 text-center"
                    role="status"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        {{ t('kinetix.channel_pairing_code') }}
                    </p>
                    <KinetixCopyable :value="pairingCode ?? ''">
                        <span
                            class="font-mono text-2xl font-semibold tracking-[0.2em] text-foreground"
                            data-test="channel-pairing-code"
                            >{{ formattedCode }}</span
                        >
                    </KinetixCopyable>
                </div>
            </div>

            <p
                v-if="error"
                class="sm:col-span-2 gap-2 text-sm flex items-center text-destructive"
                role="alert"
            >
                <AlertCircle class="size-4 shrink-0" />
                {{ error }}
            </p>
        </div>
    </KinetixModal>
</template>
