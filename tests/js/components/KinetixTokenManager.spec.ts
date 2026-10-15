import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';

const load = vi.fn();
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

describe('KinetixTokenManager', () => {
    beforeEach(() => {
        create.mockClear();
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

        const w = mountManager();

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
        const w = mountManager();
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
        const w = mountManager();

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
});
