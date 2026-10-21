<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import type { KinetixChannelUpdate } from '@/composables/useKinetixChannels';
import { useKinetixChannels } from '@/composables/useKinetixChannels';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type { KinetixChannel, KinetixChannelLink } from '@/types/kinetix';
import KinetixChannelAgentsDialog from './Channels/KinetixChannelAgentsDialog.vue';
import KinetixChannelCard from './Channels/KinetixChannelCard.vue';
import KinetixChannelCreateDialog from './Channels/KinetixChannelCreateDialog.vue';
import KinetixChannelLinker from './Channels/KinetixChannelLinker.vue';
import KinetixChannelProviderIcon from './Channels/KinetixChannelProviderIcon.vue';
import { providerCopyKey } from './Channels/providerCopy';
import KinetixButton from './KinetixButton.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixLabel from './KinetixLabel.vue';
import KinetixModal from './primitives/KinetixModal.vue';

/**
 * Drop-in line manager for a SaaS: a team adds WhatsApp numbers (linked by
 * QR or pairing code) and Telegram bots (by token), sees where each stands,
 * sends a test message, restarts, unlinks, renames and deletes them. Mount it
 * where teams manage their lines, behind the `channels.manage` ability.
 */
withDefaults(
    defineProps<{
        /** Hide the built-in title (when the page already has one); the line count and Add line stay. */
        hideHeader?: boolean;
    }>(),
    { hideHeader: false },
);

const emit = defineEmits<{
    /** A line finished linking — e.g. to move an onboarding step forward. */
    (e: 'linked', channel: KinetixChannel): void;
}>();

const { t } = useI18n();
const {
    channels,
    providers,
    limit,
    agentsEnabled,
    phoneField,
    loaded,
    load,
    rename,
    remove,
    disconnect,
    restart,
    test,
    listen,
} = useKinetixChannels();

/** The providers on offer: the copy names only those. */
const providerKeys = computed(() => providers.value.map((p) => p.key));

const creating = ref(false);
const linking = ref<KinetixChannel | null>(null);
const linkerOpen = ref(false);
const firstLink = ref<KinetixChannelLink | null>(null);
const lastUpdate = ref<KinetixChannelUpdate | null>(null);

const renaming = ref<KinetixChannel | null>(null);
const staffing = ref<KinetixChannel | null>(null);
const agentsOpen = ref(false);
const newName = ref('');
const testing = ref<KinetixChannel | null>(null);
const testTo = ref('');
const testText = ref('');
const confirming = ref<{
    channel: KinetixChannel;
    action: 'disconnect' | 'remove';
} | null>(null);
const processing = ref(false);

const atLimit = computed(
    () => limit.value !== null && channels.value.length >= limit.value,
);

/** Which providers link by scanning (and offer a pairing code). */
const methodsOf = (provider: string): string[] =>
    providers.value.find((p) => p.key === provider)?.methods ?? [];

onMounted(async () => {
    await load();
    listen((update) => {
        lastUpdate.value = update;
    });
});

function openLinker(
    channel: KinetixChannel,
    link: KinetixChannelLink | null = null,
): void {
    linking.value = channel;
    firstLink.value = link;
    linkerOpen.value = true;
}

function onCreated(
    channel: KinetixChannel,
    link: KinetixChannelLink | null,
): void {
    channels.value = [...channels.value, channel];

    if (methodsOf(channel.provider).includes('qr')) {
        openLinker(channel, link);
    } else {
        toast.success(t('kinetix.channel_created', { name: channel.name }));
    }
}

function onLinked(channel: KinetixChannel): void {
    emit('linked', channel);
}

// The linker talks to the server on its own: the list catches up when it closes.
watch(linkerOpen, (open) => {
    if (!open) {
        void load();
    }
});

/** Run a line action with a toast on failure (the server's own message). */
async function run(
    work: () => Promise<void>,
    success?: string,
): Promise<boolean> {
    processing.value = true;

    try {
        await work();

        if (success) {
            toast.success(success);
        }

        return true;
    } catch (e) {
        toast.error(e instanceof Error ? e.message : String(e));

        return false;
    } finally {
        processing.value = false;
    }
}

function startRename(channel: KinetixChannel): void {
    renaming.value = channel;
    newName.value = channel.name;
}

async function saveRename(): Promise<void> {
    const channel = renaming.value;

    if (
        channel &&
        (await run(() => rename(channel, newName.value), t('kinetix.saved')))
    ) {
        renaming.value = null;
    }
}

function startTest(channel: KinetixChannel): void {
    testing.value = channel;
    testTo.value = '';
    testText.value = t('kinetix.channel_test_default', { name: channel.name });
}

async function sendTest(): Promise<void> {
    const channel = testing.value;

    if (
        channel &&
        (await run(
            () => test(channel, testTo.value, testText.value),
            t('kinetix.channel_test_sent'),
        ))
    ) {
        testing.value = null;
    }
}

async function onRestart(channel: KinetixChannel): Promise<void> {
    await run(
        () => restart(channel),
        t('kinetix.channel_restarted', { name: channel.name }),
    );
}

async function confirm(): Promise<void> {
    const pending = confirming.value;

    if (!pending) {
        return;
    }

    const ok = await run(
        () =>
            pending.action === 'remove'
                ? remove(pending.channel)
                : disconnect(pending.channel),
        pending.action === 'remove' ? t('kinetix.deleted') : undefined,
    );

    if (ok) {
        confirming.value = null;
    }
}

const confirmCopy = computed(() => {
    const pending = confirming.value;

    if (!pending) {
        return { heading: '', description: '', submit: '' };
    }

    return pending.action === 'remove'
        ? {
              heading: t('kinetix.channel_delete_heading'),
              description: t('kinetix.channel_delete_confirm', {
                  name: pending.channel.name,
              }),
              submit: t('kinetix.delete'),
          }
        : {
              heading: t('kinetix.channel_unlink_heading'),
              description: t('kinetix.channel_unlink_confirm', {
                  name: pending.channel.name,
              }),
              submit: t('kinetix.channel_unlink'),
          };
});
</script>

<template>
    <section class="space-y-4" data-test="channel-manager">
        <!-- The heading is optional; the count and Add line always show
             once there are lines (the empty state has its own button). -->
        <header
            v-if="!hideHeader || channels.length > 0"
            class="gap-3 sm:flex-row sm:items-center flex flex-col justify-between"
        >
            <div v-if="!hideHeader" class="min-w-0">
                <h2 class="text-lg font-semibold text-foreground">
                    {{ t('kinetix.channels_title') }}
                </h2>
                <p v-if="loaded" class="text-sm text-muted-foreground">
                    {{
                        t(
                            providerCopyKey(
                                'kinetix.channels_description',
                                providerKeys,
                            ),
                        )
                    }}
                </p>
                <span
                    v-else
                    class="mt-1.5 h-4 w-72 rounded motion-safe:animate-pulse block max-w-full bg-muted"
                    aria-hidden="true"
                />
            </div>
            <div class="gap-3 sm:ml-auto flex shrink-0 items-center">
                <span
                    v-if="limit !== null"
                    class="text-sm text-muted-foreground tabular-nums"
                >
                    {{
                        t('kinetix.channel_limit', {
                            used: channels.length,
                            limit,
                        })
                    }}
                </span>
                <KinetixButton
                    v-if="channels.length > 0"
                    size="sm"
                    :disabled="atLimit || providers.length === 0"
                    data-test="channel-add"
                    @click="creating = true"
                >
                    <Plus class="size-4" />
                    {{ t('kinetix.channel_add') }}
                </KinetixButton>
            </div>
        </header>

        <!-- Loading -->
        <ul
            v-if="!loaded"
            class="gap-3 sm:grid-cols-2 lg:grid-cols-3 grid grid-cols-1"
            aria-busy="true"
        >
            <li
                v-for="n in 3"
                :key="n"
                class="h-32 rounded-xl motion-safe:animate-pulse border border-border bg-muted/40"
            />
        </ul>

        <!-- Empty: the providers on offer, then the one way forward. -->
        <div
            v-else-if="channels.length === 0"
            class="rounded-xl px-6 py-12 flex flex-col items-center justify-center border border-dashed border-border text-center"
        >
            <div class="mb-4 -space-x-2 flex" aria-hidden="true">
                <KinetixChannelProviderIcon
                    v-for="p in providers"
                    :key="p.key"
                    :provider="p.key"
                    size="lg"
                    class="ring-4 ring-background"
                />
            </div>
            <h3 class="text-sm font-semibold text-foreground">
                {{ t('kinetix.channels_empty_title') }}
            </h3>
            <p class="mt-1 text-sm max-w-sm text-muted-foreground">
                {{
                    t(
                        providerCopyKey(
                            'kinetix.channels_empty_description',
                            providerKeys,
                        ),
                    )
                }}
            </p>
            <KinetixButton
                size="sm"
                class="mt-4"
                :disabled="providers.length === 0"
                data-test="channel-add"
                @click="creating = true"
            >
                <Plus class="size-4" />
                {{ t('kinetix.channel_add') }}
            </KinetixButton>
        </div>

        <!-- Lines -->
        <ul v-else class="gap-3 sm:grid-cols-2 lg:grid-cols-3 grid grid-cols-1">
            <KinetixChannelCard
                v-for="channel in channels"
                :key="channel.id"
                :channel="channel"
                :scannable="methodsOf(channel.provider).includes('qr')"
                @link="openLinker(channel)"
                @test="startTest(channel)"
                @restart="onRestart(channel)"
                @disconnect="confirming = { channel, action: 'disconnect' }"
                :agents="agentsEnabled"
                @agents="
                    staffing = channel;
                    agentsOpen = true;
                "
                @rename="startRename(channel)"
                @remove="confirming = { channel, action: 'remove' }"
            />
        </ul>

        <KinetixChannelCreateDialog
            v-model:open="creating"
            :providers="providers"
            @created="onCreated"
        />

        <KinetixChannelLinker
            v-model:open="linkerOpen"
            :channel="linking"
            :pairing="
                linking
                    ? methodsOf(linking.provider).includes('pairing_code')
                    : false
            "
            :initial-link="firstLink"
            :update="lastUpdate"
            :phone-field="phoneField"
            @linked="onLinked"
        />

        <KinetixChannelAgentsDialog
            v-model:open="agentsOpen"
            :channel="staffing"
        />

        <!-- Rename -->
        <KinetixModal
            :open="renaming !== null"
            :title="t('kinetix.channel_rename')"
            :processing="processing"
            max-width="sm:max-w-md"
            @update:open="renaming = $event ? renaming : null"
        >
            <form
                id="kinetix-channel-rename"
                class="space-y-2"
                @submit.prevent="saveRename"
            >
                <KinetixLabel for="kinetix-channel-rename-name">{{
                    t('kinetix.channel_name')
                }}</KinetixLabel>
                <input
                    id="kinetix-channel-rename-name"
                    v-model="newName"
                    type="text"
                    maxlength="100"
                    required
                    :class="inputClass"
                />
            </form>
            <template #footer>
                <KinetixButton
                    variant="outline"
                    :disabled="processing"
                    @click="renaming = null"
                >
                    {{ t('kinetix.cancel') }}
                </KinetixButton>
                <KinetixButton
                    type="submit"
                    form="kinetix-channel-rename"
                    :loading="processing"
                >
                    {{ t('kinetix.save') }}
                </KinetixButton>
            </template>
        </KinetixModal>

        <!-- Test message -->
        <KinetixModal
            :open="testing !== null"
            :title="t('kinetix.channel_test')"
            :description="testing?.name"
            :processing="processing"
            max-width="sm:max-w-md"
            @update:open="testing = $event ? testing : null"
        >
            <form
                id="kinetix-channel-test"
                class="space-y-4"
                @submit.prevent="sendTest"
            >
                <div class="space-y-2">
                    <KinetixLabel for="kinetix-channel-test-to">{{
                        t('kinetix.channel_test_to')
                    }}</KinetixLabel>
                    <input
                        id="kinetix-channel-test-to"
                        v-model="testTo"
                        type="text"
                        required
                        aria-describedby="kinetix-channel-test-to-help"
                        :inputmode="
                            testing?.provider === 'whatsapp' ? 'tel' : 'text'
                        "
                        :class="inputClass"
                    />
                    <p
                        id="kinetix-channel-test-to-help"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            testing?.provider === 'telegram'
                                ? t('kinetix.channel_test_to_help_telegram')
                                : t('kinetix.channel_test_to_help_phone')
                        }}
                    </p>
                </div>
                <div class="space-y-2">
                    <KinetixLabel for="kinetix-channel-test-text">{{
                        t('kinetix.channel_test_text')
                    }}</KinetixLabel>
                    <textarea
                        id="kinetix-channel-test-text"
                        v-model="testText"
                        rows="3"
                        required
                        :class="[inputClass, 'py-2 h-auto']"
                    />
                </div>
            </form>
            <template #footer>
                <KinetixButton
                    variant="outline"
                    :disabled="processing"
                    @click="testing = null"
                >
                    {{ t('kinetix.cancel') }}
                </KinetixButton>
                <KinetixButton
                    type="submit"
                    form="kinetix-channel-test"
                    :loading="processing"
                >
                    {{ t('kinetix.channel_send') }}
                </KinetixButton>
            </template>
        </KinetixModal>

        <KinetixConfirmModal
            :open="confirming !== null"
            :heading="confirmCopy.heading"
            :description="confirmCopy.description"
            :submit-label="confirmCopy.submit"
            color="danger"
            :processing="processing"
            @update:open="confirming = $event ? confirming : null"
            @confirm="confirm"
        />
    </section>
</template>
