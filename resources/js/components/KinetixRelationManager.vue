<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Loader2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { kinetixComponentOf } from '@/composables/kinetixLazy';
import { clearKinetixSearchCache } from '@/composables/kinetixSearchCache';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import {
    useKinetixSearch,
    useKinetixSearchAnnouncer,
} from '@/composables/useKinetixSearch';
import type { KinetixSearchSettled } from '@/composables/useKinetixSearch';
import { useKinetixSearchLimits } from '@/composables/useKinetixSearchLimits';
import type {
    KinetixLookupMeta,
    KinetixRelationManagerData,
    KinetixSharedProps,
} from '@/types/kinetix';
import KinetixButton from './KinetixButton.vue';
import KinetixCheckbox from './KinetixCheckbox.vue';
import type KinetixFormComponent from './KinetixForm.vue';
import {
    KINETIX_LAZY_FORM,
    kinetixModalFormHooks,
    preloadKinetixModalForm,
} from './kinetixModalBodyLoaders';
import KinetixTable from './KinetixTable.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';
import KinetixModal from './primitives/KinetixModal.vue';
import ScrollArea from './primitives/ScrollArea.vue';

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
        /**
         * Inertia prop names to refresh. When set, attach/detach/associate/
         * dissociate AND the manager table's own refreshes (search, sort,
         * filters, paging, polling, inline edits, Retry) are PARTIAL reloads
         * (`router.reload({ only })`) instead of full ones. Record modals and
         * bulk actions stay full. Name the prop(s) the host page feeds this
         * manager from. Omit to keep the full reload.
         */
        reloadOnly?: string[];
    }>(),
    {
        hideTitle: false,
    },
);

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();

// Partial reload when the host named the props to refresh, else a full reload
// (the safe default — this component can't know the host's prop names).
const reloadOptions = () =>
    props.reloadOnly?.length ? { only: props.reloadOnly } : {};

// --- Record picker modal (BelongsToMany attach / HasMany associate) ---------

type AttachOption = { id: number | string; label: string };
type PickerMode = 'attach' | 'associate';

const pickerMode = ref<PickerMode>('attach');
const isAttachOpen = ref(false);
const attachSelected = ref<Set<number | string>>(new Set());
const attachSearch = ref('');
const searchLimits = useKinetixSearchLimits();
const attachHintId = `kinetix-attach-hint-${useId()}`;
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

// Answers are filed under this manager's own namespace (route prefix + signed
// descriptor), one sub-namespace per picker mode; a write clears the manager's.
const searchNamespace = computed(
    () => `${kinetixRoutePrefix(page)}:relations:${props.manager.descriptor}`,
);

const announceSettled = useKinetixSearchAnnouncer<AttachOption[]>({
    count: (list) => list.length,
    tooShort: false,
});

const attachSearcher = useKinetixSearch<AttachOption[]>({
    fetcher: async (term, { signal }) => {
        const response = await kinetixFetch<{
            options: AttachOption[];
            meta?: KinetixLookupMeta;
        }>(`${relationsUrl.value}/${picker[pickerMode.value].options}`, {
            method: 'POST',
            body: { descriptor: props.manager.descriptor, search: term },
            signal,
        });

        return { value: response?.options ?? [], meta: response?.meta };
    },
    minChars: () => searchLimits.minChars.value,
    cacheKey: () =>
        props.manager.descriptor
            ? `${searchNamespace.value}:${pickerMode.value}`
            : null,
    onSettled: (state: KinetixSearchSettled<AttachOption[]>) => {
        announceSettled(state);

        // The failure the user must hear about: not a superseded request, and
        // not a throttle that is still going to retry.
        if (
            state.status === 'error' ||
            state.status === 'expired' ||
            (state.status === 'throttled' && !state.retrying)
        ) {
            toast.error(state.message ?? t('kinetix.action_failed'));
        }
    },
});

const attachLoading = attachSearcher.loading;
const attachOptions = computed<AttachOption[]>(
    () => attachSearcher.results.value ?? [],
);
const attachHintCount = computed<number | null>(() =>
    attachSearcher.status.value === 'tooShort'
        ? searchLimits.hintCount(attachSearcher.meta.value)
        : null,
);
const attachStatusNote = computed<string | null>(() =>
    attachSearcher.status.value === 'throttled' && attachSearcher.retrying.value
        ? t('kinetix.search_throttled_retrying')
        : attachSearcher.status.value === 'timedOut'
          ? t('kinetix.search_timed_out')
          : null,
);

/** A write changed what the picker can offer: forget what it has cached. */
const forgetAttachable = (): void =>
    clearKinetixSearchCache(searchNamespace.value);

// --- Attach pivot form (AttachAction::form()) --------------------------------

// The pivot form downloads when the picker first opens with one. Read once
// per instance, so the loading wrapper is never swapped mid-edit.
const KinetixForm = kinetixComponentOf(
    KINETIX_LAZY_FORM,
) as typeof KinetixFormComponent;

// The footer submit targets the pivot form by id: busy until it's mounted.
const pivotFormMounted = ref(false);
const pivotFormHooks = kinetixModalFormHooks(pivotFormMounted);

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

function onAttachSearch(event: Event): void {
    if (!props.manager.descriptor) {
        return;
    }

    void attachSearcher.search((event.target as HTMLInputElement).value);
}

// Closing the picker drops the pending keystroke and the in-flight lookup.
watch(isAttachOpen, (isOpen) => {
    if (!isOpen) {
        attachSearcher.cancel();
    }
});

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

        forgetAttachable();
        toast.success(t(picker[pickerMode.value].done));
        isAttachOpen.value = false;
        router.reload(reloadOptions());
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
    // A mode switch must not let the previous list answer into the new one.
    attachSearcher.reset();

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

    if (attachFormDto.value) {
        void preloadKinetixModalForm(attachFormDto.value.schema);
    }

    isAttachOpen.value = true;

    if (props.manager.descriptor) {
        void attachSearcher.search('', { immediate: true });
    }
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

        forgetAttachable();
        toast.success(
            t(
                endpoint === 'detach'
                    ? 'kinetix.detached'
                    : 'kinetix.dissociated',
            ),
        );
        router.reload(reloadOptions());
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
    attachSearcher.cancel();
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

            <KinetixTable
                v-else-if="manager.table"
                :table="manager.table"
                :reload-only="reloadOnly"
            />
        </div>

        <!-- Record picker modal (AttachAction / AssociateAction events) on the
             shared KinetixModal shell (shadcn v4 dialog line). -->
        <KinetixModal
            :open="isAttachOpen"
            :title="`${t(picker[pickerMode].title)} — ${manager.title}`"
            max-width="sm:max-w-md"
            :processing="attaching"
            scroll-body
            placement="top"
            @update:open="(value) => !value && (isAttachOpen = false)"
        >
            <div class="space-y-3">
                <input
                    v-model="attachSearch"
                    type="search"
                    :placeholder="t('kinetix.search_records')"
                    :aria-label="t('kinetix.search_records')"
                    :maxlength="searchLimits.maxLength.value"
                    :aria-describedby="attachHintId"
                    class="px-3 py-2 text-sm w-full rounded-md border border-border bg-muted/40 text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @input="onAttachSearch"
                />

                <!-- Polite hint (not an error): the term is below the minimum. -->
                <p
                    :id="attachHintId"
                    role="status"
                    class="text-xs text-muted-foreground"
                    :class="{ 'sr-only': attachHintCount === null }"
                >
                    <template v-if="attachHintCount !== null">
                        {{
                            t('kinetix.search_min_chars', {
                                count: attachHintCount,
                            })
                        }}
                    </template>
                </p>

                <!-- Visual only: the announcer tells screen readers. -->
                <p
                    v-if="attachStatusNote"
                    aria-hidden="true"
                    class="text-xs text-muted-foreground"
                >
                    {{ attachStatusNote }}
                </p>

                <ScrollArea type="auto" viewport-class="max-h-64">
                    <div class="space-y-1" :aria-busy="attachLoading">
                        <p
                            v-if="attachLoading"
                            aria-hidden="true"
                            class="py-4 text-center text-muted-foreground"
                        >
                            <Loader2
                                class="size-4 animate-spin mx-auto motion-reduce:animate-none"
                            />
                        </p>
                        <p
                            v-else-if="
                                attachOptions.length === 0 &&
                                attachHintCount === null
                            "
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
                </ScrollArea>

                <!-- Pivot fields (AttachAction::form()) — written to the pivot
                     row of every attached record. The footer's submit button
                     targets this form via the native `form` attribute. -->
                <KinetixForm
                    v-if="showPivotForm"
                    :id="attachFormId"
                    :form="attachFormDto"
                    class="pt-3 border-t border-border"
                    flat
                    @vue:mounted="pivotFormHooks.mounted"
                    @vue:unmounted="pivotFormHooks.unmounted"
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
                    :loading="attaching || !pivotFormMounted"
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
