import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';

const load = vi.fn().mockResolvedValue(undefined);
const create = vi.fn().mockResolvedValue({ plainTextToken: 'tok_plain' });
const remove = vi.fn();
const tokens = ref<any[]>([]);
const scopes = ref<Record<string, string>>({});
const defaultScopes = ref<string[]>([]);

vi.mock('@/composables/useKinetixTokens', () => ({
    useKinetixTokens: () => ({
        tokens,
        scopes,
        defaultScopes,
        loading: ref(false),
        load,
        create,
        remove,
    }),
}));

import KinetixTokenManager from '@/components/KinetixTokenManager.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key.replace('kinetix.', ''),
    messages: {
        en: {
            kinetix: {
                token_expires: 'Expires {date}',
                token_expired: 'Expired',
            },
        },
    },
});

/** Stub the shadcn calendar with a plain date input. */
const DatePickerStub = defineComponent({
    props: { value: { type: String, default: null } },
    emits: ['update:value'],
    setup(props, { emit }) {
        return () =>
            h('input', {
                type: 'date',
                'data-test': 'expires-at',
                value: props.value,
                onInput: (e: Event) =>
                    emit('update:value', (e.target as HTMLInputElement).value),
            });
    },
});

const mountManager = () =>
    mount(KinetixTokenManager, {
        global: {
            plugins: [i18n],
            stubs: { KinetixDatePicker: DatePickerStub },
        },
    });

const mountSettled = async () => {
    const w = mountManager();
    await flushPromises();

    return w;
};

const addButton = (w: ReturnType<typeof mountManager>) =>
    w.findAll('button').find((b) => b.text() === 'token_add')!;

describe('KinetixTokenManager', () => {
    beforeEach(() => {
        create.mockClear();
        load.mockReset().mockResolvedValue(undefined);
        tokens.value = [];
        scopes.value = { 'posts.read': 'Read posts' };
        defaultScopes.value = [];
    });

    it('starts a new token with the default scopes ticked', async () => {
        scopes.value = {
            'posts.read': 'Read posts',
            'posts.write': 'Write posts',
        };
        defaultScopes.value = ['posts.read'];

        const w = await mountSettled();

        await w
            .findAll('button')
            .find((b) => b.text() === 'token_add')!
            .trigger('click');
        await w.get('#token-name').setValue('Reader');
        await w
            .findAll('button')
            .find((b) => b.text() === 'token_create')!
            .trigger('click');
        await flushPromises();

        expect(create).toHaveBeenCalledWith({
            name: 'Reader',
            abilities: ['posts.read'],
            expires_at: null,
        });
    });

    it('needs a scope before it creates a token, when there are scopes to pick', async () => {
        const w = await mountSettled();
        const createButton = () =>
            w.findAll('button').find((b) => b.text() === 'token_create')!;

        await w
            .findAll('button')
            .find((b) => b.text() === 'token_add')!
            .trigger('click');
        await w.get('#token-name').setValue('Reader');

        expect(w.text()).toContain('token_scopes_required');
        expect(createButton().attributes('disabled')).toBeDefined();

        await w
            .get('label input[type="checkbox"], label [role="checkbox"]')
            .trigger('click');

        expect(createButton().attributes('disabled')).toBeUndefined();
    });

    it('sends the chosen expiration date when creating a token', async () => {
        defaultScopes.value = ['posts.read'];
        const w = await mountSettled();

        await w
            .findAll('button')
            .find((b) => b.text() === 'token_add')!
            .trigger('click');
        await w.get('#token-name').setValue('Deploy');
        await w.get('[data-test="expires-at"]').setValue('2027-01-15');
        await w
            .findAll('button')
            .find((b) => b.text() === 'token_create')!
            .trigger('click');
        await flushPromises();

        expect(create).toHaveBeenCalledWith({
            name: 'Deploy',
            abilities: ['posts.read'],
            expires_at: '2027-01-15',
        });
    });

    it('shows the creation date for each token', () => {
        tokens.value = [
            {
                id: 1,
                name: 'Live',
                abilities: [],
                lastUsedAt: null,
                createdAt: '2026-06-01T09:00:00Z',
                expiresAt: null,
            },
        ];

        const w = mountManager();

        expect(w.text()).toContain('token_created');
        expect(w.text()).toContain(
            new Date('2026-06-01T09:00:00Z').toLocaleDateString(),
        );
    });

    it('shows an expiry badge and flags expired tokens', () => {
        tokens.value = [
            {
                id: 1,
                name: 'Live',
                abilities: ['posts.read'],
                lastUsedAt: null,
                createdAt: null,
                expiresAt: '2099-01-01T00:00:00Z',
            },
            {
                id: 2,
                name: 'Old',
                abilities: ['posts.read'],
                lastUsedAt: null,
                createdAt: null,
                expiresAt: '2020-01-01T00:00:00Z',
            },
            {
                id: 3,
                name: 'Forever',
                abilities: ['posts.read'],
                lastUsedAt: null,
                createdAt: null,
                expiresAt: null,
            },
        ];

        const w = mountManager();
        const text = w.text();

        expect(text).toContain('Expires');
        expect(text).toContain('Expired');
        // Tokens without expiry show no badge (the count of badges is 2).
        expect(w.findAll('.rounded-md.px-1\\.5')).toHaveLength(2);
    });

    it('keeps the Create button busy until the list has loaded', async () => {
        let resolve!: () => void;
        load.mockReturnValue(new Promise<void>((r) => (resolve = r)));

        const w = mountManager();
        await flushPromises();

        expect(addButton(w).attributes('disabled')).toBeDefined();
        expect(addButton(w).attributes('aria-busy')).toBe('true');
        expect(addButton(w).text()).toBe('token_add');

        resolve();
        await flushPromises();

        expect(addButton(w).attributes('disabled')).toBeUndefined();
        expect(addButton(w).attributes('aria-busy')).toBeUndefined();
    });

    it('lets Create work again after a failed load and applies the defaults that arrive', async () => {
        scopes.value = { 'posts.read': 'Read', 'posts.write': 'Write' };
        load.mockRejectedValueOnce(new Error('boom'));

        const w = mountManager();
        await flushPromises();

        expect(addButton(w).attributes('disabled')).toBeUndefined();

        // The retry resolves later; the form is already open by then.
        let resolve!: () => void;
        load.mockReturnValueOnce(new Promise<void>((r) => (resolve = r)));
        await addButton(w).trigger('click');

        expect(load).toHaveBeenCalledTimes(2);

        defaultScopes.value = ['posts.read'];
        resolve();
        await flushPromises();
        await w.get('#token-name').setValue('Late');
        await w
            .findAll('button')
            .find((b) => b.text() === 'token_create')!
            .trigger('click');
        await flushPromises();

        expect(create).toHaveBeenCalledWith({
            name: 'Late',
            abilities: ['posts.read'],
            expires_at: null,
        });
    });

    it('does not overwrite scopes the person already touched when defaults arrive', async () => {
        scopes.value = { 'posts.read': 'Read', 'posts.write': 'Write' };
        load.mockRejectedValueOnce(new Error('boom'));

        const w = mountManager();
        await flushPromises();

        load.mockReturnValueOnce(new Promise<void>(() => {}));
        await addButton(w).trigger('click');

        const boxes = w.findAll('label [role="checkbox"], label input');
        await boxes[1].trigger('click');

        defaultScopes.value = ['posts.read'];
        await flushPromises();
        await w.get('#token-name').setValue('Mine');
        await w
            .findAll('button')
            .find((b) => b.text() === 'token_create')!
            .trigger('click');
        await flushPromises();

        expect(create).toHaveBeenCalledWith({
            name: 'Mine',
            abilities: ['posts.write'],
            expires_at: null,
        });
    });
});
