<script setup lang="ts">
import { Hand, MessageCircle, MessagesSquare, Search } from '@lucide/vue';
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type { KinetixFlow, KinetixSelectOption } from '@/types/kinetix';
import KinetixChannelProviderIcon from '../Channels/KinetixChannelProviderIcon.vue';
import KinetixCheckbox from '../KinetixCheckbox.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixSelect from '../KinetixSelect.vue';
import KinetixTagsInput from '../KinetixTagsInput.vue';

/**
 * What starts a flow and where it runs: a keyword, a contact's first
 * message, any message, or only by hand from the inbox; on every line or
 * some; and which flow wins when several match (priority).
 */
const props = defineProps<{
    trigger: KinetixFlow['trigger'];
    channelIds: (number | string)[] | null;
    priority: number;
    lines: { id: number | string; name: string; provider: string }[];
}>();

const emit = defineEmits<{
    (e: 'update:trigger', value: KinetixFlow['trigger']): void;
    (e: 'update:channelIds', value: (number | string)[] | null): void;
    (e: 'update:priority', value: number): void;
}>();

const { t } = useI18n();

const TYPES = [
    { key: 'keyword', icon: Search },
    { key: 'first_message', icon: MessageCircle },
    { key: 'any_message', icon: MessagesSquare },
    { key: 'manual', icon: Hand },
] as const;

const MATCHES = ['contains', 'exact', 'regex'] as const;

const matchOptions = computed<KinetixSelectOption[]>(() =>
    MATCHES.map((match) => ({
        value: match,
        label: t(`kinetix.flow_keyword_${match}`),
    })),
);

const allLines = computed(
    () => props.channelIds === null || props.channelIds.length === 0,
);

function setType(type: KinetixFlow['trigger']['type']): void {
    emit('update:trigger', { ...props.trigger, type });
}

function setKeywords(keywords: string[]): void {
    emit('update:trigger', { ...props.trigger, keywords });
}

function setMatch(match: string): void {
    emit('update:trigger', {
        ...props.trigger,
        match: match as (typeof MATCHES)[number],
    });
}

function toggleLine(id: number | string, on: boolean): void {
    const current = new Set((props.channelIds ?? []).map(String));

    if (on) {
        current.add(String(id));
    } else {
        current.delete(String(id));
    }

    const next = props.lines
        .filter((line) => current.has(String(line.id)))
        .map((line) => line.id);

    emit('update:channelIds', next.length ? next : null);
}
</script>

<template>
    <div class="space-y-5" data-test="flow-settings">
        <div>
            <h3 class="text-sm font-semibold text-foreground">
                {{ t('kinetix.flow_settings') }}
            </h3>
            <p class="text-xs text-muted-foreground">
                {{ t('kinetix.flow_settings_help') }}
            </p>
        </div>

        <div class="space-y-2">
            <p
                id="flow-trigger-label"
                class="text-sm font-medium mb-2 text-foreground"
            >
                {{ t('kinetix.flow_trigger') }}
            </p>
            <RadioGroupRoot
                :model-value="trigger.type"
                class="space-y-2"
                aria-labelledby="flow-trigger-label"
                @update:model-value="
                    setType($event as KinetixFlow['trigger']['type'])
                "
            >
                <RadioGroupItem
                    v-for="type in TYPES"
                    :key="type.key"
                    :value="type.key"
                    class="gap-3 rounded-lg p-2.5 flex w-full cursor-pointer items-start border text-left transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[state=checked]:border-primary data-[state=checked]:bg-primary/5 data-[state=unchecked]:border-border data-[state=unchecked]:hover:bg-accent/50"
                    :data-test="`flow-trigger-${type.key}`"
                >
                    <component
                        :is="type.icon"
                        class="size-4 mt-0.5 shrink-0 text-muted-foreground"
                    />
                    <span class="min-w-0">
                        <span
                            class="text-sm font-medium block text-foreground"
                            >{{ t(`kinetix.flow_trigger_${type.key}`) }}</span
                        >
                        <span class="text-xs block text-muted-foreground">{{
                            t(`kinetix.flow_trigger_${type.key}_help`)
                        }}</span>
                    </span>
                </RadioGroupItem>
            </RadioGroupRoot>
        </div>

        <template v-if="trigger.type === 'keyword'">
            <div class="space-y-2">
                <KinetixLabel for="flow-keywords">{{
                    t('kinetix.flow_keywords')
                }}</KinetixLabel>
                <KinetixTagsInput
                    id="flow-keywords"
                    :value="trigger.keywords ?? []"
                    ignore-case
                    :placeholder="t('kinetix.flow_keywords_placeholder')"
                    aria-describedby="flow-keywords-help"
                    data-test="flow-keywords"
                    @update:value="setKeywords"
                />
                <p
                    id="flow-keywords-help"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('kinetix.flow_keywords_help') }}
                </p>
            </div>
            <div class="space-y-2">
                <KinetixLabel for="flow-match">{{
                    t('kinetix.flow_keyword_match')
                }}</KinetixLabel>
                <KinetixSelect
                    id="flow-match"
                    :value="trigger.match ?? 'contains'"
                    :options="matchOptions"
                    @update:value="setMatch"
                />
            </div>
        </template>

        <fieldset v-if="lines.length" class="space-y-2">
            <legend class="text-sm font-medium mb-2 text-foreground">
                {{ t('kinetix.flow_lines') }}
            </legend>
            <label class="gap-2 text-sm flex items-center text-foreground">
                <KinetixCheckbox
                    :checked="allLines"
                    @change="
                        emit('update:channelIds', $event ? null : [lines[0].id])
                    "
                />
                {{ t('kinetix.flow_lines_all') }}
            </label>
            <template v-if="!allLines">
                <label
                    v-for="line in lines"
                    :key="line.id"
                    class="gap-2 pl-6 text-sm flex items-center text-foreground"
                >
                    <KinetixCheckbox
                        :checked="
                            (channelIds ?? [])
                                .map(String)
                                .includes(String(line.id))
                        "
                        @change="toggleLine(line.id, $event)"
                    />
                    <KinetixChannelProviderIcon
                        :provider="line.provider"
                        size="sm"
                    />
                    {{ line.name }}
                </label>
            </template>
        </fieldset>

        <div class="space-y-2">
            <KinetixLabel for="flow-priority">{{
                t('kinetix.flow_priority')
            }}</KinetixLabel>
            <input
                id="flow-priority"
                :value="priority"
                type="number"
                min="0"
                max="1000"
                :class="[inputClass, 'w-28']"
                aria-describedby="flow-priority-help"
                @input="
                    emit(
                        'update:priority',
                        Number(($event.target as HTMLInputElement).value) || 0,
                    )
                "
            />
            <p id="flow-priority-help" class="text-xs text-muted-foreground">
                {{ t('kinetix.flow_priority_help') }}
            </p>
        </div>
    </div>
</template>
