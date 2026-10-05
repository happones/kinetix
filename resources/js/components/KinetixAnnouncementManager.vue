<script setup lang="ts">
import { Globe, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    useKinetixAnnouncementFormat,
    useKinetixAnnouncementManager,
} from '@/composables/useKinetixAnnouncements';
import {
    buttonVariants,
    inputClass,
    textareaClass,
} from '@/composables/useKinetixShadcnVariants';
import type { KinetixEditableAnnouncement } from '@/types/kinetix';
import KinetixButton from './KinetixButton.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixEmptyState from './KinetixEmptyState.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';
import KinetixModal from './primitives/KinetixModal.vue';
import KinetixSwitch from './primitives/KinetixSwitch.vue';

/**
 * Author announcements from the app instead of from a deploy step: write,
 * schedule, edit and delete. Gated server-side by
 * `manageKinetixAnnouncements` — mount it on an admin page.
 *
 * A draft is an entry with no publish date; a future date schedules it. Neither
 * reaches a reader's feed until its moment arrives.
 */
const { t } = useI18n();
const { levelLabel, levelColor, formatDate } = useKinetixAnnouncementFormat();
const { announcements, teamScoped, levels, loading, load, save, remove } =
    useKinetixAnnouncementManager();

/** The built-in levels, for a server that predates the configurable ones. */
const LEVELS = ['info', 'feature', 'fix'];

const blank = (): KinetixEditableAnnouncement => ({
    id: null,
    title: '',
    body: '',
    level: 'info',
    publishedAt: new Date().toISOString(),
    expiresAt: null,
    dismissible: true,
    actionLabel: '',
    actionUrl: '',
});

const draft = ref<KinetixEditableAnnouncement>(blank());
const editing = ref(false);
const saving = ref(false);
const error = ref<string | null>(null);
const removing = ref<KinetixEditableAnnouncement | null>(null);
const deleting = ref(false);

/** `datetime-local` speaks "YYYY-MM-DDTHH:mm" in local time, not ISO/UTC. */
const localInput = (iso: string | null | undefined): string => {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
};

const publishAt = computed({
    get: (): string => localInput(draft.value.publishedAt),
    set: (value: string): void => {
        draft.value.publishedAt =
            value === '' ? null : new Date(value).toISOString();
    },
});

const expiresAt = computed({
    get: (): string => localInput(draft.value.expiresAt),
    set: (value: string): void => {
        draft.value.expiresAt =
            value === '' ? null : new Date(value).toISOString();
    },
});

/**
 * The configured levels — plus the entry's own when it was published from
 * code with a level the config doesn't list, so editing never changes it.
 */
const levelOptions = computed<string[]>(() => {
    const configured = levels.value.length
        ? levels.value.map((level) => level.value)
        : LEVELS;

    return configured.includes(draft.value.level)
        ? configured
        : [...configured, draft.value.level];
});

/** A level's configured color; the built-in levels are the fallback. */
const colorOf = (level: string) =>
    levelColor(
        level,
        levels.value.find((option) => option.value === level)?.color,
    );

const dismissible = computed({
    get: (): boolean => draft.value.dismissible !== false,
    set: (value: boolean): void => {
        draft.value.dismissible = value;
    },
});

const statusLabel = (announcement: KinetixEditableAnnouncement): string =>
    t(`kinetix.announcements_status_${announcement.status ?? 'published'}`);

/** A platform-wide entry belongs to every team, so no single team may edit it. */
const isReadOnly = (announcement: KinetixEditableAnnouncement): boolean =>
    teamScoped.value && announcement.isGlobal === true;

function create(): void {
    draft.value = blank();
    error.value = null;
    editing.value = true;
}

function edit(announcement: KinetixEditableAnnouncement): void {
    draft.value = {
        ...announcement,
        actionLabel: announcement.actionLabel ?? '',
        actionUrl: announcement.actionUrl ?? '',
    };
    error.value = null;
    editing.value = true;
}

async function submit(): Promise<void> {
    saving.value = true;
    error.value = null;

    try {
        await save(draft.value);
        editing.value = false;
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        saving.value = false;
    }
}

async function confirmRemove(): Promise<void> {
    if (removing.value?.id == null) {
        return;
    }

    deleting.value = true;

    try {
        await remove(removing.value.id);
        removing.value = null;
    } finally {
        deleting.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="gap-4 flex flex-col">
        <div class="gap-2 flex items-center justify-between">
            <h2 class="text-base font-semibold text-foreground">
                {{ t('kinetix.announcements_manage_title') }}
            </h2>
            <KinetixButton size="sm" @click="create">
                <Plus class="size-4" />
                {{ t('kinetix.announcements_new_entry') }}
            </KinetixButton>
        </div>

        <div
            v-if="loading && announcements.length === 0"
            class="p-6 rounded-xl text-sm border border-border text-center text-muted-foreground"
        >
            {{ t('kinetix.relation_loading') }}
        </div>

        <KinetixEmptyState
            v-else-if="announcements.length === 0"
            icon="info"
            :title="t('kinetix.announcements_empty')"
            :description="t('kinetix.announcements_empty_hint')"
        />

        <ul
            v-else
            class="rounded-xl divide-y divide-border border border-border"
        >
            <li
                v-for="a in announcements"
                :key="String(a.id)"
                class="gap-3 p-4 flex items-start"
            >
                <div class="min-w-0 flex-1">
                    <div class="gap-2 flex flex-wrap items-center">
                        <span class="text-sm font-medium text-foreground">
                            {{ a.title }}
                        </span>
                        <KinetixBadge
                            :color="colorOf(a.level)"
                            size="sm"
                            class="shrink-0"
                        >
                            {{ levelLabel(a.level) }}
                        </KinetixBadge>
                        <span
                            class="px-2 py-0.5 font-medium shrink-0 rounded-full border border-border text-[10px] text-muted-foreground"
                        >
                            {{ statusLabel(a) }}
                        </span>
                        <Globe
                            v-if="a.isGlobal"
                            class="size-3.5 text-muted-foreground"
                            :aria-label="t('kinetix.announcements_global')"
                        />
                    </div>
                    <p
                        class="mt-1 text-sm line-clamp-2 whitespace-pre-line text-muted-foreground"
                    >
                        {{ a.body }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground/70">
                        {{ formatDate(a.publishedAt) }}
                    </p>
                </div>

                <div class="gap-1 flex shrink-0 items-center">
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :disabled="isReadOnly(a)"
                        :title="
                            isReadOnly(a)
                                ? t('kinetix.announcements_global_readonly')
                                : t('kinetix.edit')
                        "
                        :aria-label="t('kinetix.edit')"
                        @click="edit(a)"
                    >
                        <Pencil class="size-4" />
                    </button>
                    <button
                        type="button"
                        :class="
                            buttonVariants({
                                variant: 'ghost',
                                size: 'icon-sm',
                            })
                        "
                        :disabled="isReadOnly(a)"
                        :aria-label="t('kinetix.delete')"
                        @click="removing = a"
                    >
                        <Trash2 class="size-4 text-destructive" />
                    </button>
                </div>
            </li>
        </ul>

        <KinetixModal
            :open="editing"
            :title="
                draft.id === null
                    ? t('kinetix.announcements_new_entry')
                    : t('kinetix.edit')
            "
            :processing="saving"
            scroll-body
            placement="top"
            @update:open="editing = $event"
        >
            <form
                id="kinetix-announcement-form"
                class="gap-4 flex flex-col"
                @submit.prevent="submit"
            >
                <p
                    v-if="error"
                    role="alert"
                    class="px-3 py-2 text-sm rounded-md bg-destructive/10 text-destructive"
                >
                    {{ error }}
                </p>

                <div class="gap-1.5 flex flex-col">
                    <label
                        for="kinetix-announcement-title"
                        class="text-sm font-medium text-foreground"
                    >
                        {{ t('kinetix.announcements_field_title') }}
                    </label>
                    <input
                        id="kinetix-announcement-title"
                        v-model="draft.title"
                        type="text"
                        required
                        maxlength="255"
                        :class="inputClass"
                    />
                </div>

                <div class="gap-1.5 flex flex-col">
                    <label
                        for="kinetix-announcement-body"
                        class="text-sm font-medium text-foreground"
                    >
                        {{ t('kinetix.announcements_field_body') }}
                    </label>
                    <textarea
                        id="kinetix-announcement-body"
                        v-model="draft.body"
                        rows="4"
                        required
                        :class="textareaClass"
                    />
                </div>

                <div class="gap-4 sm:grid-cols-2 grid">
                    <div class="gap-1.5 flex flex-col">
                        <label
                            for="kinetix-announcement-level"
                            class="text-sm font-medium text-foreground"
                        >
                            {{ t('kinetix.announcements_field_level') }}
                        </label>
                        <select
                            id="kinetix-announcement-level"
                            v-model="draft.level"
                            :class="inputClass"
                        >
                            <option
                                v-for="level in levelOptions"
                                :key="level"
                                :value="level"
                            >
                                {{ levelLabel(level) }}
                            </option>
                        </select>
                    </div>

                    <div class="gap-1.5 flex flex-col">
                        <label
                            for="kinetix-announcement-published"
                            class="text-sm font-medium text-foreground"
                        >
                            {{ t('kinetix.announcements_field_published_at') }}
                        </label>
                        <input
                            id="kinetix-announcement-published"
                            v-model="publishAt"
                            type="datetime-local"
                            :class="inputClass"
                            :aria-describedby="'kinetix-announcement-published-hint'"
                        />
                        <p
                            id="kinetix-announcement-published-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t('kinetix.announcements_field_published_hint')
                            }}
                        </p>
                    </div>
                </div>

                <div class="gap-1.5 flex flex-col">
                    <label
                        for="kinetix-announcement-expires"
                        class="text-sm font-medium text-foreground"
                    >
                        {{ t('kinetix.announcements_field_expires_at') }}
                    </label>
                    <input
                        id="kinetix-announcement-expires"
                        v-model="expiresAt"
                        type="datetime-local"
                        :class="inputClass"
                        aria-describedby="kinetix-announcement-expires-hint"
                    />
                    <p
                        id="kinetix-announcement-expires-hint"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('kinetix.announcements_field_expires_hint') }}
                    </p>
                </div>

                <div class="gap-3 flex items-start">
                    <KinetixSwitch
                        id="kinetix-announcement-dismissible"
                        v-model="dismissible"
                        class="mt-0.5"
                        aria-describedby="kinetix-announcement-dismissible-hint"
                    />
                    <div class="gap-1 flex flex-col">
                        <label
                            for="kinetix-announcement-dismissible"
                            class="text-sm font-medium text-foreground"
                        >
                            {{ t('kinetix.announcements_field_dismissible') }}
                        </label>
                        <p
                            id="kinetix-announcement-dismissible-hint"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(
                                    'kinetix.announcements_field_dismissible_hint',
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div class="gap-4 sm:grid-cols-2 grid">
                    <div class="gap-1.5 flex flex-col">
                        <label
                            for="kinetix-announcement-action-label"
                            class="text-sm font-medium text-foreground"
                        >
                            {{ t('kinetix.announcements_field_action_label') }}
                        </label>
                        <input
                            id="kinetix-announcement-action-label"
                            v-model="draft.actionLabel"
                            type="text"
                            maxlength="80"
                            :class="inputClass"
                            aria-describedby="kinetix-announcement-action-hint"
                        />
                    </div>

                    <div class="gap-1.5 flex flex-col">
                        <label
                            for="kinetix-announcement-action-url"
                            class="text-sm font-medium text-foreground"
                        >
                            {{ t('kinetix.announcements_field_action_url') }}
                        </label>
                        <input
                            id="kinetix-announcement-action-url"
                            v-model="draft.actionUrl"
                            type="text"
                            inputmode="url"
                            maxlength="2048"
                            :class="inputClass"
                            aria-describedby="kinetix-announcement-action-hint"
                        />
                    </div>
                </div>
                <p
                    id="kinetix-announcement-action-hint"
                    class="-mt-2 text-xs text-muted-foreground"
                >
                    {{ t('kinetix.announcements_field_action_hint') }}
                </p>
            </form>

            <template #footer>
                <KinetixButton
                    variant="outline"
                    :disabled="saving"
                    @click="editing = false"
                >
                    {{ t('kinetix.cancel') }}
                </KinetixButton>
                <KinetixButton
                    type="submit"
                    form="kinetix-announcement-form"
                    :loading="saving"
                >
                    {{ t('kinetix.save') }}
                </KinetixButton>
            </template>
        </KinetixModal>

        <KinetixConfirmModal
            :open="removing !== null"
            color="danger"
            :heading="t('kinetix.announcements_delete_confirm')"
            :description="removing?.title"
            :submit-label="t('kinetix.delete')"
            :processing="deleting"
            @confirm="confirmRemove"
            @update:open="removing = $event ? removing : null"
        />
    </div>
</template>
