import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const { routerMock, pageState } = vi.hoisted(() => ({
    routerMock: {
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
    pageState: {
        props: { errors: {} as Record<string, string> },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: routerMock,
    usePage: () => pageState,
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
}));

vi.mock('vue-sonner', () => ({
    toast: { error: vi.fn() },
}));

import { useKinetixRecordModals } from '@/composables/useKinetixRecordModals';

const blueprint = {
    schema: [{ type: 'text', name: 'name' }],
    data: { name: '' },
    rules: {},
    operation: 'create',
};

const config = (overrides: Record<string, unknown> = {}) =>
    ({
        enabled: true,
        token: 'signed-token',
        source: 'server',
        createForm: blueprint,
        ...overrides,
    }) as any;

const setup = (overrides: Record<string, unknown> = {}) => {
    let api: ReturnType<typeof useKinetixRecordModals>;

    const Harness = defineComponent({
        setup() {
            api = useKinetixRecordModals({
                config: () => config(overrides),
                // Team-scoped apps ship a prefix like `acme/_kinetix`.
                routePrefix: () => 'acme/_kinetix',
            });

            return () => h('div');
        },
    });

    mount(Harness);

    return api!;
};

describe('useKinetixRecordModals', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        pageState.props.errors = {};
    });

    it('opens create with a clone of the blueprint (never mutates it)', () => {
        const api = setup();

        api.handleModalAction({ modal: 'create', label: 'New' } as any);

        expect(api.isFormOpen.value).toBe(true);
        expect(api.isEditing.value).toBe(false);

        api.activeForm.value.data.name = 'typed';
        expect(blueprint.data.name).toBe('');
    });

    it('clears stale validation errors when a modal opens', () => {
        pageState.props.errors = { name: 'The name field is required.' };
        const api = setup();

        api.handleModalAction({ modal: 'create' } as any);

        expect(pageState.props.errors).toEqual({});
        expect(api.isFormOpen.value).toBe(true);
    });

    it('submits create/update through the team-scoped record endpoint', () => {
        const api = setup();

        api.handleModalAction({ modal: 'create' } as any);
        api.submitForm({ name: 'Alpha' });

        expect(routerMock.post).toHaveBeenCalledWith(
            '/acme/_kinetix/tables/record',
            { token: 'signed-token', data: { name: 'Alpha' } },
            expect.objectContaining({ preserveScroll: true }),
        );

        // A successful submit closes the modal and drops the form DTO.
        routerMock.post.mock.calls[0][2].onSuccess();
        routerMock.post.mock.calls[0][2].onFinish();
        expect(api.isFormOpen.value).toBe(false);
        expect(api.activeForm.value).toBeNull();
    });

    it('prefills edit from the row when source is "row"', async () => {
        const api = setup({ source: 'row' });

        await api.handleModalAction(
            { modal: 'edit' } as any,
            {
                id: 5,
                values: { name: 'Loaded row' },
            } as any,
        );

        expect(fetchMock).not.toHaveBeenCalled();
        expect(api.isFormOpen.value).toBe(true);
        expect(api.activeForm.value.data).toEqual({ name: 'Loaded row' });

        api.submitForm({ name: 'Renamed' });
        expect(routerMock.put).toHaveBeenCalledWith(
            '/acme/_kinetix/tables/record',
            { token: 'signed-token', id: 5, data: { name: 'Renamed' } },
            expect.anything(),
        );
    });

    it('fetches a fresh form for edit in server mode', async () => {
        fetchMock.mockResolvedValueOnce({
            form: { ...blueprint, data: { name: 'Fresh' }, operation: 'edit' },
        });
        const api = setup();

        api.handleModalAction({ modal: 'edit' } as any, { id: 5 } as any);
        await vi.waitFor(() => expect(api.isLoading.value).toBe(false));

        expect(fetchMock).toHaveBeenCalledWith(
            '/acme/_kinetix/tables/record/resolve',
            expect.objectContaining({
                method: 'POST',
                body: { token: 'signed-token', mode: 'edit', id: 5 },
            }),
        );
        expect(api.activeForm.value.data.name).toBe('Fresh');
    });

    it('cancel closes the form and clears its state, but not while processing', () => {
        const api = setup();
        api.handleModalAction({ modal: 'create' } as any);

        api.processing.value = true;
        api.closeForm();
        expect(api.isFormOpen.value).toBe(true);

        api.processing.value = false;
        api.closeForm();
        expect(api.isFormOpen.value).toBe(false);
        expect(api.activeForm.value).toBeNull();
    });

    it('delete asks for confirmation, then deletes through the endpoint', () => {
        const api = setup();

        api.handleModalAction(
            { modal: 'delete', label: 'Delete' } as any,
            {
                id: 9,
            } as any,
        );
        expect(api.isDeleteOpen.value).toBe(true);
        expect(routerMock.delete).not.toHaveBeenCalled();

        api.confirmDelete();
        expect(routerMock.delete).toHaveBeenCalledWith(
            '/acme/_kinetix/tables/record',
            expect.objectContaining({
                data: { token: 'signed-token', id: 9 },
            }),
        );
    });

    it('cancelDelete closes the confirmation without deleting', () => {
        const api = setup();

        api.handleModalAction({ modal: 'delete' } as any, { id: 9 } as any);
        api.cancelDelete();

        expect(api.isDeleteOpen.value).toBe(false);
        expect(api.pendingDelete.value).toBeNull();
        expect(routerMock.delete).not.toHaveBeenCalled();
    });

    it('ignores non-modal actions and reports them unhandled', () => {
        const api = setup();

        expect(api.handleModalAction({ label: 'Export' } as any)).toBe(false);
        expect(api.isFormOpen.value).toBe(false);
    });

    it('does nothing when recordModals is disabled', () => {
        const api = setup({ enabled: false });

        expect(api.handleModalAction({ modal: 'create' } as any)).toBe(false);
        expect(api.isFormOpen.value).toBe(false);
    });
});
