import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import type {
    KinetixAction,
    KinetixInfolistData,
    KinetixRecordModals,
    KinetixRecordReference,
    KinetixTableRecord,
} from '@/types/kinetix';

interface RecordModalOptions {
    /** The table's recordModals descriptor (token, source, blueprint). */
    config: () => KinetixRecordModals | null | undefined;
    /** Kinetix route prefix (already team-scoped), e.g. `_kinetix`. */
    routePrefix: () => string;
}

/**
 * In-table modal CRUD for a "simple" resource. KinetixTable delegates any action
 * flagged `modal: 'create'|'edit'|'view'|'delete'` here, so a page can render
 * just `<KinetixTable :table>` and get full create/edit/view/delete.
 *
 * Data flow (Kinetix-owned):
 *  - Create opens instantly from the shipped blueprint (no round-trip).
 *  - Edit fetches a FRESH copy from the server by default (so a concurrent edit
 *    is never silently overwritten); `source: 'row'` prefills from the loaded
 *    row instead.
 *  - View always fetches the server-resolved infolist.
 *  - Store/update/destroy go through the signed Kinetix record endpoint as
 *    Inertia visits, so validation errors surface in KinetixForm and the table
 *    reloads with fresh data on success.
 *
 * All state is plain refs (no watchers/bindings) so nothing leaks.
 */
export function useKinetixRecordModals(options: RecordModalOptions) {
    const page = usePage();

    const isFormOpen = ref(false);
    const isInfolistOpen = ref(false);
    const isDeleteOpen = ref(false);
    const isEditing = ref(false);
    const isLoading = ref(false);
    const processing = ref(false);

    // Form DTOs are dynamic (schema/data/rules/operation, matching KinetixForm's
    // prop); kept `any` like the rest of the form layer. Infolist is fully typed.
    const activeForm = ref<any>(null);
    const activeInfolist = ref<KinetixInfolistData | null>(null);
    const activeRecordId = ref<string | number | null>(null);
    const activeLabel = ref<string>('');
    /** The open record's discreet reference (server-fetched view/edit only). */
    const activeReference = ref<KinetixRecordReference | null>(null);

    /**
     * Bumped by every open and close: a resolve that lands after the user
     * moved on (closed, or opened another record) is dropped, so a modal never
     * shows, saves over or copies the id of a record it isn't editing.
     */
    let currentRequest = 0;
    const pendingDelete = ref<KinetixAction | null>(null);

    const enabled = computed(() => !!options.config()?.enabled);

    // Relation-scoped modals (a relation manager's table) go through the
    // parent-bound endpoints; everything else uses the simple-resource ones.
    const base = () =>
        options.config()?.scope === 'relation'
            ? `/${options.routePrefix()}/tables/relations/record`
            : `/${options.routePrefix()}/tables/record`;

    const resolveUrl = () => `${base()}/resolve`;
    const submitUrl = () => base();

    const token = () => options.config()?.token ?? '';

    type ResolvedRecord = {
        form?: any;
        infolist?: any;
        reference?: KinetixRecordReference | null;
    };

    /** Fetch a fresh { form } / { infolist } (+ reference) for a record. */
    const fetchRecord = async (
        mode: 'edit' | 'view',
        id: string | number,
    ): Promise<ResolvedRecord | null> => {
        return kinetixFetch<ResolvedRecord>(resolveUrl(), {
            method: 'POST',
            body: { token: token(), mode, id },
        });
    };

    /**
     * Drop validation errors left over from a previous modal submit. KinetixForm
     * renders `page.props.errors`, which Inertia only replaces on the next
     * visit — without this, a cancelled-then-reopened modal would show the old
     * error bag on a pristine form.
     */
    const clearStaleErrors = () => {
        const errors = page.props.errors as Record<string, string> | undefined;

        if (!errors) {
            return;
        }

        for (const key of Object.keys(errors)) {
            delete errors[key];
        }
    };

    const openCreate = (action?: KinetixAction) => {
        const blueprint = options.config()?.createForm;

        if (!blueprint) {
            return;
        }

        clearStaleErrors();
        currentRequest++;
        isLoading.value = false;
        isEditing.value = false;
        activeRecordId.value = null;
        activeReference.value = null;
        activeLabel.value = action?.label ?? '';
        // Clone so the blueprint (and its default data) is never mutated.
        activeForm.value = {
            ...blueprint,
            data: { ...(blueprint.data ?? {}) },
        };
        isFormOpen.value = true;
    };

    const openEdit = async (
        record: KinetixTableRecord,
        action?: KinetixAction,
    ) => {
        clearStaleErrors();
        const request = ++currentRequest;
        isEditing.value = true;
        activeRecordId.value = record.id;
        activeLabel.value = action?.label ?? '';
        activeReference.value = null;

        // 'row' mode: prefill from the already-loaded row (no round-trip). The
        // row carries displayed values, so heavily formatted columns may need
        // the resource's form/table aligned — server mode avoids that entirely.
        if (options.config()?.source === 'row') {
            isLoading.value = false;
            const blueprint = options.config()?.createForm ?? { data: {} };
            activeForm.value = {
                ...blueprint,
                data: { ...(record.values ?? {}) },
            };
            isFormOpen.value = true;

            return;
        }

        isLoading.value = true;
        isFormOpen.value = true;

        try {
            const data = await fetchRecord('edit', record.id);

            if (request !== currentRequest) {
                return;
            }

            if (data?.form) {
                activeForm.value = data.form;
            }

            activeReference.value = data?.reference ?? null;
        } catch (e) {
            if (request !== currentRequest) {
                return;
            }

            isFormOpen.value = false;
            toast.error(e instanceof Error ? e.message : String(e));
        } finally {
            if (request === currentRequest) {
                isLoading.value = false;
            }
        }
    };

    const openView = async (
        record: KinetixTableRecord,
        action?: KinetixAction,
    ) => {
        const request = ++currentRequest;
        activeRecordId.value = record.id;
        activeLabel.value = action?.label ?? '';
        activeReference.value = null;
        isLoading.value = true;
        isInfolistOpen.value = true;

        try {
            const data = await fetchRecord('view', record.id);

            if (request !== currentRequest) {
                return;
            }

            if (data?.infolist) {
                activeInfolist.value = data.infolist;
            }

            activeReference.value = data?.reference ?? null;
        } catch (e) {
            if (request !== currentRequest) {
                return;
            }

            isInfolistOpen.value = false;
            toast.error(e instanceof Error ? e.message : String(e));
        } finally {
            if (request === currentRequest) {
                isLoading.value = false;
            }
        }
    };

    const submitForm = (values: Record<string, any>) => {
        if (processing.value) {
            return;
        }

        processing.value = true;

        const shared = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                isFormOpen.value = false;
                activeForm.value = null;
            },
            onFinish: () => {
                processing.value = false;
            },
        };

        if (isEditing.value && activeRecordId.value !== null) {
            router.put(
                submitUrl(),
                { token: token(), id: activeRecordId.value, data: values },
                shared,
            );

            return;
        }

        router.post(submitUrl(), { token: token(), data: values }, shared);
    };

    const requestDelete = (
        action: KinetixAction,
        record: KinetixTableRecord,
    ) => {
        pendingDelete.value = action;
        activeRecordId.value = record.id;
        isDeleteOpen.value = true;
    };

    const confirmDelete = () => {
        if (processing.value || activeRecordId.value === null) {
            return;
        }

        processing.value = true;

        router.delete(submitUrl(), {
            data: { token: token(), id: activeRecordId.value },
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                isDeleteOpen.value = false;
                pendingDelete.value = null;
            },
            onFinish: () => {
                processing.value = false;
            },
        });
    };

    const cancelDelete = () => {
        if (processing.value) {
            return;
        }

        isDeleteOpen.value = false;
        pendingDelete.value = null;
    };

    /**
     * Cancel/close the form modal: hides it and drops the form DTO so nothing
     * stale flashes on the next open (each open rebuilds it from the blueprint
     * or a fresh server fetch).
     */
    const closeForm = () => {
        if (processing.value) {
            return;
        }

        currentRequest++;
        isLoading.value = false;
        isFormOpen.value = false;
        activeForm.value = null;
        activeReference.value = null;
    };

    const closeInfolist = () => {
        currentRequest++;
        isLoading.value = false;
        isInfolistOpen.value = false;
        activeInfolist.value = null;
        activeReference.value = null;
    };

    /**
     * Entry point: handle a modal action. Returns true when the action was a
     * modal action (and was handled), so KinetixTable can skip normal execution.
     */
    const handleModalAction = (
        action: KinetixAction,
        record?: KinetixTableRecord,
    ): boolean => {
        if (!enabled.value || !action.modal) {
            return false;
        }

        switch (action.modal) {
            case 'create':
                openCreate(action);
                break;
            case 'edit':
                if (record) {
                    void openEdit(record, action);
                }

                break;
            case 'view':
                if (record) {
                    void openView(record, action);
                }

                break;
            case 'delete':
                if (record) {
                    requestDelete(action, record);
                }

                break;
            default:
                return false;
        }

        return true;
    };

    return {
        enabled,
        isFormOpen,
        isInfolistOpen,
        isDeleteOpen,
        isEditing,
        isLoading,
        processing,
        activeForm,
        activeInfolist,
        activeLabel,
        activeReference,
        pendingDelete,
        handleModalAction,
        submitForm,
        confirmDelete,
        cancelDelete,
        closeForm,
        closeInfolist,
    };
}
