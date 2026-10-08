import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import type { KinetixAction, KinetixTableRecord } from '@/types/kinetix';

interface FormActionOptions {
    /** The table's signed form-action descriptor (name→class + scope). */
    descriptor: () => string | null | undefined;
    /** Kinetix route prefix (already team-scoped), e.g. `_kinetix`. */
    routePrefix: () => string;
}

/**
 * In-table modal form actions ({@see FormAction}). KinetixTable delegates any
 * action flagged `isFormAction` here, so a record or toolbar action can open a
 * modal hosting an arbitrary KinetixForm and run a server-side handler.
 *
 * Data flow (Kinetix-owned, mirroring useKinetixRecordModals):
 *  - Opening clones the action's shipped form schema (no round-trip) — the
 *    server serialised it from the SAME class it reconstructs to validate.
 *  - Submit POSTs { descriptor, action, recordId?, data } through the signed
 *    kinetix.tables.form-action endpoint as an Inertia visit, so validation
 *    errors surface in KinetixForm and the table reloads on success. The
 *    descriptor — not the action name or record id — is what the endpoint
 *    trusts: it resolves any record in-scope and authorizes it server-side.
 *  - A record action carries its row id; a toolbar action sends none and the
 *    handler runs record-less.
 *
 * All state is plain refs so nothing leaks between opens.
 */
export function useKinetixFormActions(options: FormActionOptions) {
    const page = usePage();

    const isOpen = ref(false);
    const processing = ref(false);
    /** The form of a row action is being fetched (the modal shows a skeleton). */
    const loading = ref(false);
    // Bumped on every open and close: a fetch only fills the modal it opened.
    let openSeq = 0;
    // The form DTO mounted in the modal (schema/data/rules/operation); kept
    // `any` like the rest of the form layer so KinetixForm's prop types match.
    const activeForm = ref<any>(null);
    const activeAction = ref<KinetixAction | null>(null);
    const activeRecordId = ref<string | number | null>(null);

    const submitUrl = () => `/${options.routePrefix()}/tables/form-action`;

    /**
     * Drop validation errors left over from a previous submit. KinetixForm
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

    /**
     * Entry point: open the modal for a form action. Returns true when the
     * action was a form action (and was handled), so KinetixTable can skip
     * normal execution — mirroring useKinetixRecordModals.handleModalAction.
     */
    const handleFormAction = (
        action: KinetixAction,
        record?: KinetixTableRecord,
    ): boolean => {
        if (!action.isFormAction || !options.descriptor()) {
            return false;
        }

        if (action.formOnOpen && record) {
            void openFetched(action, record);

            return true;
        }

        if (!action.form) {
            return false;
        }

        clearStaleErrors();
        activeAction.value = action;
        activeRecordId.value = record?.id ?? null;
        // Clone so the shipped schema (and its default data) is never mutated.
        activeForm.value = {
            ...action.form,
            data: { ...(action.form.data ?? {}) },
        };
        isOpen.value = true;

        return true;
    };

    /**
     * A row's FormAction ships without its form: fetch the form for this row
     * now, through the same checks a submission passes.
     */
    const openFetched = async (
        action: KinetixAction,
        record: KinetixTableRecord,
    ): Promise<void> => {
        clearStaleErrors();
        const opened = ++openSeq;
        activeAction.value = action;
        activeRecordId.value = record.id;
        activeForm.value = null;
        loading.value = true;
        isOpen.value = true;

        try {
            const data = await kinetixFetch<{ form?: any }>(
                `${submitUrl()}/form`,
                {
                    method: 'POST',
                    body: {
                        descriptor: options.descriptor(),
                        action: action.name,
                        recordId: record.id,
                    },
                },
            );

            // A modal closed (or another opened) meanwhile isn't refilled.
            if (opened === openSeq && data?.form) {
                activeForm.value = data.form;
            }
        } catch (e) {
            if (opened === openSeq) {
                isOpen.value = false;
                toast.error(e instanceof Error ? e.message : String(e));
            }
        } finally {
            if (opened === openSeq) {
                loading.value = false;
            }
        }
    };

    const submitForm = (values: Record<string, any>) => {
        const action = activeAction.value;
        const descriptor = options.descriptor();

        if (processing.value || !action || !descriptor) {
            return;
        }

        processing.value = true;

        router.post(
            submitUrl(),
            {
                descriptor,
                action: action.name,
                data: values,
                ...(activeRecordId.value !== null
                    ? { recordId: activeRecordId.value }
                    : {}),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    isOpen.value = false;
                    activeForm.value = null;
                    activeAction.value = null;
                    activeRecordId.value = null;
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    };

    /**
     * Cancel/close the modal: hides it and drops the form DTO so nothing stale
     * flashes on the next open (each open rebuilds it from the action schema).
     */
    const closeForm = () => {
        if (processing.value) {
            return;
        }

        openSeq++;
        loading.value = false;

        isOpen.value = false;
        activeForm.value = null;
        activeAction.value = null;
        activeRecordId.value = null;
    };

    return {
        isOpen,
        processing,
        loading,
        activeForm,
        activeAction,
        handleFormAction,
        submitForm,
        closeForm,
    };
}
