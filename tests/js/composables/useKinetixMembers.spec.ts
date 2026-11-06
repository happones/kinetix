import { beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('@/composables/useKinetixHttp', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/composables/useKinetixHttp')>()),
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import {
    memberFieldErrors,
    useKinetixMembers,
} from '@/composables/useKinetixMembers';
import type { KinetixMemberProvision } from '@/types/kinetix';

const member = { id: 7 } as KinetixMemberProvision;

const httpError = (status: number, errors?: Record<string, string[]>) =>
    Object.assign(new Error('Rejected'), { status, errors });

describe('useKinetixMembers', () => {
    beforeEach(() => fetchMock.mockReset());

    it('defaults the custom-field state for a server that predates it', async () => {
        fetchMock.mockResolvedValue({ provisions: [], assignable_roles: [] });

        const members = useKinetixMembers();
        await members.load();

        expect(members.hasFields.value).toBe(false);
        expect(members.fieldsForm.value).toBeNull();
    });

    it('reads has_fields and the provisioning form from the index', async () => {
        const form = { schema: [], data: {}, rules: {}, operation: 'create' };
        fetchMock.mockResolvedValue({
            provisions: [],
            assignable_roles: [],
            has_fields: true,
            fields_form: form,
        });

        const members = useKinetixMembers();
        await members.load();

        expect(members.hasFields.value).toBe(true);
        expect(members.fieldsForm.value).toEqual(form);
    });

    it('sends fields on provision only when given', async () => {
        fetchMock.mockResolvedValue(null);
        const members = useKinetixMembers();

        await members.provision('a@x.test', 'editor', 'Ana');
        await members.provision('b@x.test', 'editor', null, { badge: '7' });

        expect(fetchMock.mock.calls[0][1].body).toEqual({
            email: 'a@x.test',
            role: 'editor',
            name: 'Ana',
        });
        expect(fetchMock.mock.calls[1][1].body).toEqual({
            email: 'b@x.test',
            role: 'editor',
            fields: { badge: '7' },
        });
    });

    it("fetches a member's fields form", async () => {
        const form = { schema: [], data: { badge: 'A' }, rules: {} };
        fetchMock.mockResolvedValue({ form });

        const result = await useKinetixMembers().memberFields(member);

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/members/7/fields');
        expect(result).toEqual(form);
    });

    it('updates the role and fields in one PUT', async () => {
        fetchMock.mockResolvedValue(null);

        await useKinetixMembers().updateMember(member, {
            role: 'viewer',
            fields: { badge: 'B' },
        });

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/members/7', {
            method: 'PUT',
            body: { role: 'viewer', fields: { badge: 'B' } },
        });
    });
});

describe('memberFieldErrors', () => {
    it('strips the fields. prefix and keeps the first message', () => {
        expect(
            memberFieldErrors(
                httpError(422, {
                    'fields.badge': ['First.', 'Second.'],
                    'fields.branch_ids': ['Invalid.'],
                }),
            ),
        ).toEqual({ badge: 'First.', branch_ids: 'Invalid.' });
    });

    it('ignores keys that are not custom fields', () => {
        expect(
            memberFieldErrors(
                httpError(422, { email: ['Bad.'], role: ['Bad.'] }),
            ),
        ).toEqual({});
    });

    it('is empty for a failure that is not a validation bag', () => {
        expect(memberFieldErrors(httpError(500))).toEqual({});
        expect(memberFieldErrors(new Error('network'))).toEqual({});
        expect(memberFieldErrors(null)).toEqual({});
    });
});
