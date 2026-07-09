import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';

const load = vi.fn();
const create = vi.fn().mockResolvedValue({ plainTextToken: 'tok_plain' });
const remove = vi.fn();
const tokens = ref<any[]>([]);
const scopes = ref<Record<string, string>>({});

vi.mock('@/composables/useKinetixTokens', () => ({
    useKinetixTokens: () => ({
        tokens,
        scopes,
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
    });

    it('sends the chosen expiration date when creating a token', async () => {
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
            abilities: [],
            expires_at: '2027-01-15',
        });
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
