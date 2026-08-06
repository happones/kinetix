<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixRelationManagerData,
    KinetixSharedProps,
} from '@/types/kinetix';
import KinetixButton from './KinetixButton.vue';
import KinetixCheckbox from './KinetixCheckbox.vue';
import KinetixForm from './KinetixForm.vue';
import KinetixTable from './KinetixTable.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';
import KinetixModal from './primitives/KinetixModal.vue';

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

// --- Attach pivot form (AttachAction::form()) --------------------------------

// Cloned per open so edits/errors never leak into the shipped blueprint.
const attachFormDto = ref<any>(null);

// Unique per manager: the footer submit button targets it via the native
// `form` attribute (the button lives outside the <form> element).
const attachFormId = computed(
    () => `kinetix-attach-pivot-${props.manager.relationship}`,
);

const showPivotForm = computed(
    () => pickerMode.value === 'attach' && !!attachFormDto.value,
);

/**
 * KinetixForm renders `page.props.errors`; the attach endpoint is a fetch, so
 * its 422 bag never lands there on its own. Written manually on failure —
 * and cleared on open so a cancelled attempt doesn't haunt the next one.
 */
const setPageErrors = (errors: Record<string, string[]>): void => {
    // `page?.props?.` — outside a mounted Inertia app (component tests, SSR
    // edges) usePage yields no page; there is no bag to write then.
    const props = page?.props as
        | { errors?: Record<string, string> }
        | undefined;

    if (!props) {
        return;
    }

    const bag = (props.errors ??= {});

    for (const [field, messages] of Object.entries(errors)) {
        bag[field] = Array.isArray(messages) ? messages[0] : String(messages);
    }
};

const clearPageErrors = (): void => {
    const bag = page?.props?.errors as Record<string, string> | undefined;

    for (const key of Object.keys(bag ?? {})) {
        delete bag![key];
    }
};

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

async function submitAttach(pivot?: Record<string, any>): Promise<void> {
    if (!props.manager.descriptor || attachSelected.value.size === 0) {
        return;
    }

    attaching.value = true;
    clearPageErrors();

    try {
        await kinetixFetch(
            `${relationsUrl.value}/${picker[pickerMode.value].submit}`,
            {
                method: 'POST',
                body: {
                    descriptor: props.manager.descriptor,
                    ids: Array.from(attachSelected.value),
                    ...(pivot ? { pivot } : {}),
                },
            },
        );

        toast.success(t(picker[pickerMode.value].done));
        isAttachOpen.value = false;
        router.reload();
    } catch (e) {
        // Validation problems render inline under the pivot fields; anything
        // else (expired descriptor, forbidden) only has a message to show.
        const errors = (e as { errors?: Record<string, string[]> }).errors;

        if (errors && Object.keys(errors).length > 0) {
            setPageErrors(errors);
        } else {
            toast.error(
                e instanceof Error && e.message
                    ? e.message
                    : t('kinetix.action_failed'),
            );
        }
    } finally {
        attaching.value = false;
    }
}

function openAttach(mode: PickerMode = 'attach'): void {
    pickerMode.value = mode;
    attachSelected.value = new Set();
    attachSearch.value = '';
    clearPageErrors();
    attachFormDto.value =
        mode === 'attach' && props.manager.attachForm
            ? {
                  ...props.manager.attachForm,
                  data: { ...(props.manager.attachForm.data ?? {}) },
              }
            : null;
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

// --- Lazy manager (deferred stub → load on activation) -----------------------

/**
 * A lazy manager ships only its tab stub until it is the active
 * `?relation=`: on mount we revisit with the relation param so the server
 * serializes the full payload (table + descriptor). One request per mount —
 * the tabs host keys the panel per relationship, so re-activating a tab
 * remounts (and refreshes) it. Re-armed only on error so a failed visit can
 * be retried by switching back to the tab.
 */
const deferredRequested = ref(false);

const isDeferred = computed(
    () => !!props.manager.deferred && !props.manager.table,
);

function loadDeferred(): void {
    if (deferredRequested.value) {
        return;
    }

    deferredRequested.value = true;

    router.reload({
        // A group member requests its GROUP's key, so one revisit loads every
        // lazy member of the group at once.
        data: {
            relation: props.manager.groupKey ?? props.manager.relationship,
        },
        onError: () => {
            deferredRequested.value = false;
        },
    });
}

// --- Collapsible section ------------------------------------------------------

const isSectionCollapsed = ref(!!props.manager.collapsed);

// Collapsing only applies where the heading (the toggle) renders — a plain
// tab hides the title, so a `$isCollapsed` manager must not start hidden
// with no way to expand it.
const isCollapsible = computed(
    () => !!props.manager.collapsible && !props.hideTitle,
);

const sectionContentId = computed(
    () => `kinetix-relation-content-${props.manager.relationship}`,
);

// A collapsed lazy section defers its load until it is actually expanded.
watch(isSectionCollapsed, (collapsed) => {
    if (!collapsed && isDeferred.value) {
        loadDeferred();
    }
});

const isContentHidden = computed(
    () => isCollapsible.value && isSectionCollapsed.value,
);

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
    if (isDeferred.value && !isContentHidden.value) {
        loadDeferred();
    }

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
            <!-- Collapsible: the whole heading is the toggle. -->
            <button
                v-if="isCollapsible"
                type="button"
                class="gap-2 -m-1 p-1 flex cursor-pointer items-center rounded-md focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-expanded="!isSectionCollapsed"
                :aria-controls="sectionContentId"
                @click="isSectionCollapsed = !isSectionCollapsed"
            >
                <ChevronDown
                    class="size-4 text-muted-foreground transition-transform"
                    :class="isSectionCollapsed ? '-rotate-90' : ''"
                    aria-hidden="true"
                />
                {{ manager.title }}
            </button>
            <template v-else>{{ manager.title }}</template>
            <KinetixBadge
                v-if="manager.badge !== null && manager.badge !== undefined"
                :color="manager.badgeColor"
            >
                {{ manager.badge }}
            </KinetixBadge>
        </h2>

        <div :id="sectionContentId" v-show="!isContentHidden" class="space-y-3">
            <!-- Lazy manager still loading: pulsing skeleton, replaced by the
                 real table when the ?relation= revisit lands. -->
            <div v-if="isDeferred" class="space-y-3" aria-busy="true">
                <span class="sr-only">{{ t('kinetix.relation_loading') }}</span>
                <div class="h-9 animate-pulse w-64 rounded-md bg-muted"></div>
                <div class="h-40 animate-pulse rounded-md bg-muted"></div>
            </div>

            <KinetixTable v-else-if="manager.table" :table="manager.table" />
        </div>

        <!-- Record picker modal (AttachAction / AssociateAction events) on the
             shared KinetixModal shell (shadcn v4 dialog line). -->
        <KinetixModal
            :open="isAttachOpen"
            :title="`${t(picker[pickerMode].title)} — ${manager.title}`"
            max-width="sm:max-w-md"
            :processing="attaching"
            scroll-body
            @update:open="(value) => !value && (isAttachOpen = false)"
        >
            <div class="space-y-3">
                <input
                    v-model="attachSearch"
                    type="search"
                    :placeholder="t('kinetix.search_records')"
                    :aria-label="t('kinetix.search_records')"
                    class="px-3 py-2 text-sm w-full rounded-md border border-border bg-muted/40 text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @input="onAttachSearch"
                />

                <div class="max-h-64 space-y-1 overflow-y-auto">
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
                            @update:model-value="toggleAttachOption(option.id)"
                        />
                        <span class="min-w-0 truncate">{{ option.label }}</span>
                    </label>
                </div>

                <!-- Pivot fields (AttachAction::form()) — written to the pivot
                     row of every attached record. The footer's submit button
                     targets this form via the native `form` attribute. -->
                <KinetixForm
                    v-if="showPivotForm"
                    :id="attachFormId"
                    :form="attachFormDto"
                    class="pt-3 border-t border-border"
                    @submit="(values) => submitAttach(values)"
                >
                    <template #default><span class="hidden"></span></template>
                </KinetixForm>
            </div>

            <template #footer>
                <KinetixButton
                    variant="outline"
                    :disabled="attaching"
                    @click="isAttachOpen = false"
                >
                    {{ t('kinetix.cancel') }}
                </KinetixButton>
                <KinetixButton
                    v-if="showPivotForm"
                    type="submit"
                    :form="attachFormId"
                    :loading="attaching"
                    :disabled="attachSelected.size === 0"
                >
                    {{ t(picker[pickerMode].title) }}
                </KinetixButton>
                <KinetixButton
                    v-else
                    :loading="attaching"
                    :disabled="attachSelected.size === 0"
                    @click="submitAttach()"
                >
                    {{ t(picker[pickerMode].title) }}
                </KinetixButton>
            </template>
        </KinetixModal>
    </section>
</template>
