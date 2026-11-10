<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { notificationRoutingErrors } from '@/composables/useKinetixNotificationRouting';
import type {
    KinetixNotificationAudiences,
    KinetixNotificationAudienceType,
    KinetixNotificationRoute,
    KinetixNotificationRoutingType,
} from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixCheckboxList from '../KinetixCheckboxList.vue';
import KinetixCombobox from '../KinetixCombobox.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixSelect from '../KinetixSelect.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

/**
 * Adding an audience to a notification type, or changing the channels of an
 * existing one: pick who (a member, a role, a group, or everyone who can see
 * the notification), then which of the type's channels. The save itself is the
 * parent's `save`: a rejected one (a 422) shows its messages inline, and the
 * dialog stays open.
 */
const props = defineProps<{
    open: boolean;
    type: KinetixNotificationRoutingType | null;
    /** The rule being edited; null to add one. */
    route: KinetixNotificationRoute | null;
    audiences: KinetixNotificationAudiences;
    /** Channel key → label. */
    channelLabels: Record<string, string>;
    /** Server-side member search (debounced by the picker); without it the members are filtered locally. */
    searchMembers?: (
        query: string,
    ) => Promise<Array<{ id: number | string; name: string }>>;
    save: (input: {
        audienceType: KinetixNotificationAudienceType;
        audienceId: string | null;
        channels: string[];
    }) => Promise<void>;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const { t } = useI18n();

const kind = ref<KinetixNotificationAudienceType>('member');
const audienceId = ref('');
const selectedChannels = ref<string[]>([]);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const editing = computed(() => props.route !== null);

const kinds = computed(() => {
    const list: Array<{
        value: KinetixNotificationAudienceType;
        label: string;
    }> = [
        {
            value: 'member',
            label: t('kinetix.notification_routing_kind_member'),
        },
    ];

    if (props.audiences.roles.length > 0) {
        list.push({
            value: 'role',
            label: t('kinetix.notification_routing_kind_role'),
        });
    }

    list.push(
        { value: 'group', label: t('kinetix.notification_routing_kind_group') },
        {
            value: 'eligible',
            label: t('kinetix.notification_routing_kind_eligible'),
        },
    );

    return list;
});

const options = computed(() => {
    const source =
        kind.value === 'role'
            ? props.audiences.roles
            : kind.value === 'group'
              ? props.audiences.groups
              : props.audiences.members;

    return source.map((a) => ({ value: String(a.id), label: a.name }));
});

/** The member picker asks the server as the admin types; roles and groups are short lists. */
const fetchMembers = computed(() => {
    const search = props.searchMembers;

    return kind.value === 'member' && search
        ? async (query: string) =>
              (await search(query)).map((a) => ({
                  value: String(a.id),
                  label: a.name,
              }))
        : null;
});

const channelOptions = computed(() =>
    (props.type?.channels ?? []).map((key) => ({
        value: key,
        label: props.channelLabels[key] ?? key,
    })),
);

const audienceName = computed(() => {
    if (!props.route) {
        return '';
    }

    return props.route.audienceType === 'eligible'
        ? t('kinetix.notification_routing_kind_eligible')
        : (props.route.audienceLabel ??
              t('kinetix.notification_routing_removed'));
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errors.value = {};
        audienceId.value = '';
        kind.value = props.route?.audienceType ?? 'member';
        selectedChannels.value = props.route
            ? [...props.route.channels]
            : [...(props.type?.channels ?? [])];
    },
    { immediate: true },
);

// Another kind lists other people: the pick no longer applies.
watch(kind, () => {
    audienceId.value = '';
    delete errors.value.audience_id;
});

const emptyHint = computed(() => {
    // A remote member picker has its own empty state: the local list is empty by design.
    if (kind.value === 'member' && fetchMembers.value) {
        return null;
    }

    if (options.value.length > 0 || kind.value === 'eligible') {
        return null;
    }

    return kind.value === 'group'
        ? t('kinetix.notification_routing_no_groups')
        : t('kinetix.notification_routing_no_audience_options');
});

async function submit(): Promise<void> {
    errors.value = {};

    if (
        !editing.value &&
        kind.value !== 'eligible' &&
        audienceId.value === ''
    ) {
        errors.value = {
            audience_id: t('kinetix.notification_routing_audience_required'),
        };

        return;
    }

    if (selectedChannels.value.length === 0) {
        errors.value = {
            channels: t('kinetix.notification_routing_channels_required'),
        };

        return;
    }

    processing.value = true;

    try {
        await props.save({
            audienceType: kind.value,
            audienceId: kind.value === 'eligible' ? null : audienceId.value,
            channels: selectedChannels.value,
        });
        emit('update:open', false);
    } catch (e) {
        errors.value = notificationRoutingErrors(
            e,
            t('kinetix.notification_routing_save_failed'),
        );
    } finally {
        processing.value = false;
    }
}

/** A 422 can name the type or the audience kind too: show it once, with the form's own. */
const formError = computed(
    () =>
        errors.value.form ??
        errors.value.type ??
        errors.value.audience_type ??
        null,
);
</script>

<template>
    <KinetixModal
        :open="open"
        :title="
            editing
                ? t('kinetix.notification_routing_edit_title')
                : t('kinetix.notification_routing_add_title')
        "
        :description="
            t('kinetix.notification_routing_dialog_description', {
                type: type?.label ?? '',
            })
        "
        :processing="processing"
        placement="top"
        @update:open="emit('update:open', $event)"
    >
        <form
            id="kinetix-notification-route"
            class="space-y-5"
            novalidate
            @submit.prevent="submit"
        >
            <div v-if="editing" class="space-y-1">
                <p class="text-sm font-medium text-foreground">
                    {{ t('kinetix.notification_routing_audience') }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ audienceName }}
                </p>
            </div>

            <template v-else>
                <div class="space-y-2">
                    <KinetixLabel for="kinetix-notification-route-kind">{{
                        t('kinetix.notification_routing_audience')
                    }}</KinetixLabel>
                    <KinetixSelect
                        id="kinetix-notification-route-kind"
                        :value="kind"
                        :options="kinds"
                        @update:value="
                            kind = $event as KinetixNotificationAudienceType
                        "
                    />
                    <p
                        v-if="kind === 'eligible'"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('kinetix.notification_routing_eligible_hint') }}
                    </p>
                </div>

                <div v-if="kind !== 'eligible'" class="space-y-2">
                    <KinetixLabel for="kinetix-notification-route-audience">{{
                        t(`kinetix.notification_routing_kind_${kind}`)
                    }}</KinetixLabel>
                    <KinetixCombobox
                        id="kinetix-notification-route-audience"
                        :value="audienceId"
                        :options="options"
                        :fetch-options="fetchMembers"
                        :placeholder="
                            t(`kinetix.notification_routing_choose_${kind}`)
                        "
                        :aria-invalid="!!errors.audience_id"
                        :aria-describedby="
                            errors.audience_id
                                ? 'kinetix-notification-route-audience-error'
                                : undefined
                        "
                        @update:value="audienceId = $event"
                    />
                    <p v-if="emptyHint" class="text-xs text-muted-foreground">
                        {{ emptyHint }}
                    </p>
                    <p
                        v-if="errors.audience_id"
                        id="kinetix-notification-route-audience-error"
                        class="text-sm text-destructive"
                        role="alert"
                    >
                        {{ errors.audience_id }}
                    </p>
                </div>
            </template>

            <div
                class="space-y-2"
                role="group"
                aria-labelledby="kinetix-notification-route-channels"
                :aria-describedby="
                    errors.channels
                        ? 'kinetix-notification-route-channels-error'
                        : undefined
                "
            >
                <p
                    id="kinetix-notification-route-channels"
                    class="text-sm font-medium text-foreground"
                >
                    {{ t('kinetix.notification_routing_channels') }}
                </p>
                <KinetixCheckboxList
                    :value="selectedChannels"
                    :options="channelOptions"
                    inline
                    @update:value="selectedChannels = $event as string[]"
                />
                <p
                    v-if="errors.channels"
                    id="kinetix-notification-route-channels-error"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.channels }}
                </p>
            </div>

            <p v-if="formError" class="text-sm text-destructive" role="alert">
                {{ formError }}
            </p>
        </form>

        <template #footer>
            <KinetixButton
                variant="outline"
                :disabled="processing"
                @click="emit('update:open', false)"
            >
                {{ t('kinetix.cancel') }}
            </KinetixButton>
            <KinetixButton
                type="submit"
                form="kinetix-notification-route"
                :loading="processing"
                data-test="notification-route-submit"
            >
                {{ t('kinetix.save') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
