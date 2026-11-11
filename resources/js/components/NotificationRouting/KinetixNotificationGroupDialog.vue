<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixNotificationGroupInput } from '@/composables/useKinetixNotificationRouting';
import { notificationRoutingErrors } from '@/composables/useKinetixNotificationRouting';
import {
    useKinetixSearch,
    useKinetixSearchAnnouncer,
} from '@/composables/useKinetixSearch';
import {
    inputClass,
    textareaClass,
} from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixNotificationAudience,
    KinetixNotificationGroup,
} from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixCheckboxList from '../KinetixCheckboxList.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixModal from '../primitives/KinetixModal.vue';

/**
 * Creating or editing a notification group: a name, an optional description
 * and the team members in it. The save is the parent's `save`; a rejected one
 * (a 422: a name already taken, say) shows its messages beside the field.
 */
const props = defineProps<{
    open: boolean;
    /** The group being edited; null to create one. */
    group: KinetixNotificationGroup | null;
    /** The team's people, for the member picker. */
    members: KinetixNotificationAudience[];
    /** Server-side member search; without it the picker filters `members` locally. */
    searchMembers?: (
        query: string,
        ctx?: { signal?: AbortSignal },
    ) => Promise<KinetixNotificationAudience[]>;
    save: (input: KinetixNotificationGroupInput) => Promise<void>;
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

const { t } = useI18n();

const name = ref('');
const description = ref('');
const memberIds = ref<string[]>([]);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const search = ref('');
/** The page of people the current search returned (null until one ran: `members` is the first page). */
const found = ref<KinetixNotificationAudience[] | null>(null);
/** Every name seen, so a selected member keeps its label when a search moves on. */
const known = ref(new Map<string, string>());

const remember = (list: KinetixNotificationAudience[]): void => {
    for (const person of list) {
        known.value.set(String(person.id), person.name);
    }
};

/**
 * The search page plus everyone already selected, so a member outside the
 * page stays visible and checked; a local search filters by name instead.
 */
const options = computed(() => {
    const q = search.value.trim().toLowerCase();
    const page = found.value
        ? found.value
        : props.members.filter((m) => !q || m.name.toLowerCase().includes(q));
    const list = page.map((m) => ({ value: String(m.id), label: m.name }));
    const seen = new Set(list.map((o) => o.value));

    for (const id of memberIds.value) {
        if (!seen.has(id)) {
            list.push({ value: id, label: known.value.get(id) ?? id });
        }
    }

    return list;
});

// The host's own search (it files its requests under the team); typing is
// debounced and a late answer never replaces a newer one.
const searcher = useKinetixSearch<KinetixNotificationAudience[]>({
    fetcher: async (query, { signal }) => ({
        value: (await props.searchMembers?.(query.trim(), { signal })) ?? [],
    }),
    onSettled: useKinetixSearchAnnouncer<KinetixNotificationAudience[]>({
        count: (list) => list.length,
    }),
});

// A failed search keeps the previous page: the picker still works on it.
watch(searcher.results, (result) => {
    if (result) {
        remember(result);
        found.value = result;
    }
});

function onSearch(event: Event): void {
    search.value = (event.target as HTMLInputElement).value;

    if (!props.searchMembers) {
        return;
    }

    void searcher.search(search.value);
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errors.value = {};
        name.value = props.group?.name ?? '';
        description.value = props.group?.description ?? '';
        memberIds.value = (props.group?.members ?? []).map((m) => String(m.id));
        search.value = '';
        searcher.reset();
        known.value = new Map();
        remember(props.group?.members ?? []);
        remember(props.members);
        found.value = null;
    },
    { immediate: true },
);

async function submit(): Promise<void> {
    errors.value = {};
    processing.value = true;

    try {
        await props.save({
            name: name.value.trim(),
            description: description.value.trim() || null,
            member_ids: [...memberIds.value],
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
</script>

<template>
    <KinetixModal
        :open="open"
        :title="
            group
                ? t('kinetix.notification_routing_groups_edit_title')
                : t('kinetix.notification_routing_groups_new')
        "
        :description="
            t('kinetix.notification_routing_groups_dialog_description')
        "
        :processing="processing"
        placement="top"
        @update:open="emit('update:open', $event)"
    >
        <form
            id="kinetix-notification-group"
            class="space-y-5"
            @submit.prevent="submit"
        >
            <div class="space-y-2">
                <KinetixLabel for="kinetix-notification-group-name">{{
                    t('kinetix.notification_routing_groups_name')
                }}</KinetixLabel>
                <input
                    id="kinetix-notification-group-name"
                    v-model="name"
                    type="text"
                    maxlength="120"
                    required
                    :placeholder="
                        t(
                            'kinetix.notification_routing_groups_name_placeholder',
                        )
                    "
                    :aria-invalid="!!errors.name"
                    :aria-describedby="
                        errors.name
                            ? 'kinetix-notification-group-name-error'
                            : undefined
                    "
                    :class="inputClass"
                />
                <p
                    v-if="errors.name"
                    id="kinetix-notification-group-name-error"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.name }}
                </p>
            </div>

            <div class="space-y-2">
                <KinetixLabel for="kinetix-notification-group-description">{{
                    t('kinetix.notification_routing_groups_description_label')
                }}</KinetixLabel>
                <textarea
                    id="kinetix-notification-group-description"
                    v-model="description"
                    rows="2"
                    :aria-invalid="!!errors.description"
                    :aria-describedby="
                        errors.description
                            ? 'kinetix-notification-group-description-error'
                            : undefined
                    "
                    :class="textareaClass"
                />
                <p
                    v-if="errors.description"
                    id="kinetix-notification-group-description-error"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.description }}
                </p>
            </div>

            <div
                class="space-y-2"
                role="group"
                aria-labelledby="kinetix-notification-group-members"
                :aria-describedby="
                    errors.member_ids
                        ? 'kinetix-notification-group-members-error'
                        : undefined
                "
            >
                <p
                    id="kinetix-notification-group-members"
                    class="text-sm font-medium text-foreground"
                >
                    {{ t('kinetix.notification_routing_groups_members') }}
                </p>
                <KinetixLabel for="kinetix-notification-group-search">{{
                    t('kinetix.notification_routing_groups_search')
                }}</KinetixLabel>
                <input
                    id="kinetix-notification-group-search"
                    type="search"
                    :value="search"
                    data-test="group-member-search"
                    :class="inputClass"
                    @input="onSearch"
                />
                <KinetixCheckboxList
                    :value="memberIds"
                    :options="options"
                    :aria-busy="searcher.loading.value"
                    @update:value="memberIds = $event.map(String)"
                />
                <p
                    v-if="errors.member_ids"
                    id="kinetix-notification-group-members-error"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.member_ids }}
                </p>
            </div>

            <p v-if="errors.form" class="text-sm text-destructive" role="alert">
                {{ errors.form }}
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
                form="kinetix-notification-group"
                :loading="processing"
                data-test="notification-group-submit"
            >
                {{ t('kinetix.save') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
