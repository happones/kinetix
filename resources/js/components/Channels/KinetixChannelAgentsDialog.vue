<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { useKinetixChannels } from '@/composables/useKinetixChannels';
import type { KinetixChannel } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixCheckbox from '../KinetixCheckbox.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

/**
 * Who answers a line in the inbox. Agents see only the conversations of the
 * lines they're given here; members who see every line (admins, line
 * managers) are shown ticked and can't be taken off.
 */
const props = defineProps<{
    open: boolean;
    channel: KinetixChannel | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const { t } = useI18n();
const { agents, saveAgents } = useKinetixChannels();

const members = ref<
    { id: number | string; name: string | null; seesAll: boolean }[]
>([]);
const selected = ref<Set<string>>(new Set());
const loading = ref(false);
const processing = ref(false);

const assignable = computed(() => members.value.filter((m) => !m.seesAll));

watch(
    () => props.open,
    async (open) => {
        if (!open || !props.channel) {
            return;
        }

        loading.value = true;

        try {
            const data = await agents(props.channel);
            members.value = data?.members ?? [];
            selected.value = new Set((data?.agents ?? []).map(String));
        } finally {
            loading.value = false;
        }
    },
);

function toggle(id: number | string, on: boolean): void {
    const next = new Set(selected.value);

    if (on) {
        next.add(String(id));
    } else {
        next.delete(String(id));
    }

    selected.value = next;
}

async function save(): Promise<void> {
    if (!props.channel) {
        return;
    }

    processing.value = true;

    try {
        await saveAgents(
            props.channel,
            assignable.value
                .filter((m) => selected.value.has(String(m.id)))
                .map((m) => m.id),
        );
        toast.success(t('kinetix.saved'));
        emit('update:open', false);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : String(error));
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <KinetixModal
        :open="open"
        :title="
            t('kinetix.channel_agents_title', { name: channel?.name ?? '' })
        "
        :description="t('kinetix.channel_agents_description')"
        :processing="processing"
        max-width="sm:max-w-md"
        @update:open="emit('update:open', $event)"
    >
        <div v-if="loading" class="space-y-2" aria-hidden="true">
            <div
                v-for="n in 4"
                :key="n"
                class="h-9 motion-safe:animate-pulse rounded-md bg-muted"
            />
        </div>
        <p
            v-else-if="members.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ t('kinetix.channel_agents_empty') }}
        </p>
        <ul
            v-else
            class="max-h-80 -mx-2 overflow-y-auto"
            data-test="channel-agents-list"
        >
            <li v-for="member in members" :key="member.id">
                <label
                    class="gap-3 px-2 py-2 flex items-center rounded-md hover:bg-accent/60"
                    :class="{ 'cursor-pointer': !member.seesAll }"
                >
                    <KinetixCheckbox
                        :checked="
                            member.seesAll || selected.has(String(member.id))
                        "
                        :disabled="member.seesAll"
                        @change="toggle(member.id, $event)"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="text-sm block truncate text-foreground">{{
                            member.name
                        }}</span>
                        <span
                            v-if="member.seesAll"
                            class="text-xs block text-muted-foreground"
                            >{{ t('kinetix.channel_agents_sees_all') }}</span
                        >
                    </span>
                </label>
            </li>
        </ul>

        <template #footer>
            <KinetixButton
                variant="outline"
                :disabled="processing"
                @click="emit('update:open', false)"
            >
                {{ t('kinetix.cancel') }}
            </KinetixButton>
            <KinetixButton
                :loading="processing"
                :disabled="loading"
                data-test="channel-agents-save"
                @click="save"
            >
                {{ t('kinetix.save') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
