<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type {
    KinetixRelationManagerData,
    KinetixSharedProps,
} from '@/types/kinetix';
import KinetixButton from './KinetixButton.vue';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixTable from './KinetixTable.vue';

/**
 * One relation manager: heading (+ optional badge) and the related-records
 * table. When the manager ships a signed `descriptor` it also hosts the
 * record-picker modal (BelongsToMany attach / HasMany associate) and the
 * detach/dissociate listeners — the actions dispatch
 * `kinetix:open-attach|open-associate|detach-relation|dissociate-relation`
 * events carrying this manager's relationship, so several managers on one
 * page never cross. Modal CRUD (create/edit/view/delete) needs nothing here:
 * the table's own record modals post to the relation-scoped endpoint.
 *
 * For SEVERAL managers prefer `<KinetixRelationManagers :managers="…" />`
 * (auto-tabs); it renders this component per tab with `hideTitle`.
 */
const props = withDefaults(
    defineProps<{
        manager: KinetixRelationManagerData;
        /** The tabs host shows the title on the tab — skip the heading. */
        hideTitle?: boolean;
    }>(),
    {
        hideTitle: false,
    },
);

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();

const badgeClass = (color?: string | null): string =>
    statusBadgeClass((color ?? 'gray') as KinetixStatusColor);

// --- Record picker modal (BelongsToMany attach / HasMany associate) ---------

type AttachOption = { id: number | string; label: string };
type PickerMode = 'attach' | 'associate';

const pickerMode = ref<PickerMode>('attach');
const isAttachOpen = ref(false);
const attachOptions = ref<AttachOption[]>([]);
const attachSelected = ref<Set<number | string>>(new Set());
const attachSearch = ref('');
const attachLoading = ref(false);
const attaching = ref(false);
const detaching = ref(false);

// Endpoint + copy per picker mode; the modal itself is shared.
const picker = {
    attach: {
        options: 'attachable',
        submit: 'attach',
        title: 'kinetix.attach',
        done: 'kinetix.attached',
        empty: 'kinetix.attach_none_found',
    },
    associate: {
        options: 'associable',
        submit: 'associate',
        title: 'kinetix.associate',
        done: 'kinetix.associated',
        empty: 'kinetix.associate_none_found',
    },
} as const;

const relationsUrl = computed(
    () => `/${kinetixRoutePrefix(page)}/tables/relations`,
);

let searchTimer: ReturnType<typeof setTimeout> | null = null;

async function loadAttachable(): Promise<void> {
    if (!props.manager.descriptor) {
        return;
    }

    attachLoading.value = true;

    try {
        const response = await kinetixFetch<{ options: AttachOption[] }>(
            `${relationsUrl.value}/${picker[pickerMode.value].options}`,
            {
                method: 'POST',
                body: {
                    descriptor: props.manager.descriptor,
                    search: attachSearch.value,
                },
            },
        );

        attachOptions.value = response?.options ?? [];
    } catch (e) {
        attachOptions.value = [];
        toast.error(
            e instanceof Error && e.message
                ? e.message
                : t('kinetix.action_failed'),
        );
    } finally {
        attachLoading.value = false;
    }
}

function onAttachSearch(): void {
    if (searchTimer) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => void loadAttachable(), 300);
}

function toggleAttachOption(id: number | string): void {
    const next = new Set(attachSelected.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    attachSelected.value = next;
}

async function submitAttach(): Promise<void> {
    if (!props.manager.descriptor || attachSelected.value.size === 0) {
        return;
    }

    attaching.value = true;

    try {
        await kinetixFetch(
            `${relationsUrl.value}/${picker[pickerMode.value].submit}`,
            {
                method: 'POST',
                body: {
                    descriptor: props.manager.descriptor,
                    ids: Array.from(attachSelected.value),
                },
            },
        );

        toast.success(t(picker[pickerMode.value].done));
        isAttachOpen.value = false;
        router.reload();
    } catch (e) {
        toast.error(
            e instanceof Error && e.message
                ? e.message
                : t('kinetix.action_failed'),
        );
    } finally {
        attaching.value = false;
    }
}

function openAttach(mode: PickerMode = 'attach'): void {
    pickerMode.value = mode;
    attachSelected.value = new Set();
    attachSearch.value = '';
    isAttachOpen.value = true;
    void loadAttachable();
}

// --- Detach / dissociate (row / bulk) ----------------------------------------

async function removeRelation(
    endpoint: 'detach' | 'dissociate',
    ids: Array<number | string>,
): Promise<void> {
    if (!props.manager.descriptor || ids.length === 0 || detaching.value) {
        return;
    }

    detaching.value = true;

    try {
        await kinetixFetch(`${relationsUrl.value}/${endpoint}`, {
            method: 'POST',
            body: { descriptor: props.manager.descriptor, ids },
        });

        toast.success(
            t(
                endpoint === 'detach'
                    ? 'kinetix.detached'
                    : 'kinetix.dissociated',
            ),
        );
        router.reload();
    } catch (e) {
        toast.error(
            e instanceof Error && e.message
                ? e.message
                : t('kinetix.action_failed'),
        );
    } finally {
        detaching.value = false;
    }
}

// --- Event wiring (AttachAction / DetachAction dispatch these) --------------

const forThisManager = (event: Event): boolean =>
    ((event as CustomEvent).detail ?? {}).relationship ===
    props.manager.relationship;

const eventIds = (event: Event): Array<number | string> => {
    const detail = (event as CustomEvent).detail ?? {};

    return Array.isArray(detail.ids)
        ? detail.ids
        : detail.record?.id != null
          ? [detail.record.id]
          : [];
};

const onOpenAttach = (event: Event): void => {
    if (forThisManager(event)) {
        openAttach('attach');
    }
};

const onOpenAssociate = (event: Event): void => {
    if (forThisManager(event)) {
        openAttach('associate');
    }
};

const onDetachRelation = (event: Event): void => {
    if (forThisManager(event)) {
        void removeRelation('detach', eventIds(event));
    }
};

const onDissociateRelation = (event: Event): void => {
    if (forThisManager(event)) {
        void removeRelation('dissociate', eventIds(event));
    }
};

onMounted(() => {
    window.addEventListener('kinetix:open-attach', onOpenAttach);
    window.addEventListener('kinetix:open-associate', onOpenAssociate);
    window.addEventListener('kinetix:detach-relation', onDetachRelation);
    window.addEventListener(
        'kinetix:dissociate-relation',
        onDissociateRelation,
    );
});

onBeforeUnmount(() => {
    window.removeEventListener('kinetix:open-attach', onOpenAttach);
    window.removeEventListener('kinetix:open-associate', onOpenAssociate);
    window.removeEventListener('kinetix:detach-relation', onDetachRelation);
    window.removeEventListener(
        'kinetix:dissociate-relation',
        onDissociateRelation,
    );
});
</script>

<template>
    <section class="space-y-3">
        <h2
            v-if="!hideTitle"
            class="gap-2 text-lg font-semibold tracking-tight flex items-center text-foreground"
        >
            {{ manager.title }}
            <span
                v-if="manager.badge !== null && manager.badge !== undefined"
                class="px-2 py-0.5 text-xs font-semibold inline-flex items-center rounded-full"
                :class="badgeClass(manager.badgeColor)"
            >
                {{ manager.badge }}
            </span>
        </h2>

        <KinetixTable :table="manager.table" />

        <!-- Record picker modal (AttachAction / AssociateAction events) -->
        <Teleport to="body">
            <div
                v-if="isAttachOpen"
                class="inset-0 p-4 fixed z-[var(--kinetix-z-modal,100)] flex items-center justify-center"
                role="dialog"
                aria-modal="true"
            >
                <div
                    class="inset-0 bg-black/50 backdrop-blur-sm absolute"
                    @click="isAttachOpen = false"
                />

                <div
                    class="max-w-md rounded-xl p-5 shadow-lg relative w-full border border-border bg-card text-card-foreground"
                >
                    <h3 class="text-lg font-semibold tracking-tight">
                        {{ t(picker[pickerMode].title) }} — {{ manager.title }}
                    </h3>

                    <input
                        v-model="attachSearch"
                        type="search"
                        :placeholder="t('kinetix.search_records')"
                        :aria-label="t('kinetix.search_records')"
                        class="mt-3 px-3 py-2 text-sm w-full rounded-md border border-border bg-muted/40 text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        @input="onAttachSearch"
                    />

                    <div class="mt-3 max-h-64 space-y-1 overflow-y-auto">
                        <p
                            v-if="attachLoading"
                            class="py-4 text-sm text-center text-muted-foreground"
                        >
                            …
                        </p>
                        <p
                            v-else-if="attachOptions.length === 0"
                            class="py-4 text-sm text-center text-muted-foreground"
                        >
                            {{ t(picker[pickerMode].empty) }}
                        </p>
                        <label
                            v-for="option in attachOptions"
                            :key="option.id"
                            class="gap-2 px-2 py-1.5 text-sm flex cursor-pointer items-center rounded-md hover:bg-accent"
                        >
                            <KinetixCheckbox
                                :model-value="attachSelected.has(option.id)"
                                @update:model-value="
                                    toggleAttachOption(option.id)
                                "
                            />
                            <span class="min-w-0 truncate">{{
                                option.label
                            }}</span>
                        </label>
                    </div>

                    <div class="mt-4 gap-2 flex justify-end">
                        <KinetixButton
                            variant="outline"
                            :disabled="attaching"
                            @click="isAttachOpen = false"
                        >
                            {{ t('kinetix.cancel') }}
                        </KinetixButton>
                        <KinetixButton
                            :loading="attaching"
                            :disabled="attachSelected.size === 0"
                            @click="submitAttach"
                        >
                            {{ t(picker[pickerMode].title) }}
                        </KinetixButton>
                    </div>
                </div>
            </div>
        </Teleport>
    </section>
</template>
