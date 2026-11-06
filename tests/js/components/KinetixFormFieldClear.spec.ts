import fs from 'node:fs';
import path from 'node:path';
import { mount } from '@vue/test-utils';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixFormField from '@/components/Form/KinetixFormField.vue';
import { KINETIX_CLEAR_VALUE } from '@/composables/kinetixWriteOnly';
import { preloadAllKinetixLazy } from '../support/lazyComponents';

const messages = {
    form_withheld_hint: 'A value is saved. Leave this blank to keep it.',
    form_withheld_placeholder: 'Saved — leave blank to keep',
    form_withheld_clear: 'Remove saved value',
    form_withheld_cleared: 'The saved value will be removed when you save.',
    form_withheld_undo: 'Undo',
};

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: { en: { kinetix: messages } },
});

const secret = (extra: Record<string, unknown> = {}) => ({
    type: 'text-input',
    name: 'api_key',
    isWithheld: true,
    isClearable: true,
    ...extra,
});

/** A controlled field: every `update` goes back in as the new values. */
function mountField(comp: Record<string, unknown>, value: unknown = '') {
    const wrapper = mount(KinetixFormField, {
        props: { comp, values: { api_key: value }, errors: {} },
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

    const feed = async (): Promise<void> => {
        const updates = wrapper.emitted('update') ?? [];
        const last = updates[updates.length - 1];

        if (last) {
            await wrapper.setProps({ values: { api_key: last[0] } });
        }
    };

    return { wrapper, feed };
}

const flushFrames = () => new Promise((r) => requestAnimationFrame(r));

beforeAll(preloadAllKinetixLazy);

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixFormField: clearing a write-only value', () => {
    it('offers the clear control only for a withheld AND clearable field', () => {
        const button = (comp: Record<string, unknown>) =>
            mountField(comp)
                .wrapper.findAll('button')
                .some((b) => b.text() === 'Remove saved value');

        expect(button(secret())).toBe(true);
        expect(button(secret({ isClearable: false }))).toBe(false);
        expect(button(secret({ isClearable: undefined }))).toBe(false);
        expect(button(secret({ isWithheld: false }))).toBe(false);
    });

    it('keeps the plain hint markup for a field that cannot be cleared', () => {
        const { wrapper } = mountField(secret({ isClearable: false }));

        expect(
            wrapper.find('[data-test="api_key-withheld-clear"]').exists(),
        ).toBe(false);
        expect(wrapper.get('#api_key-withheld').text()).toBe(
            messages.form_withheld_hint,
        );
    });

    it('clears to the sentinel, shows an empty control, describes and announces it, then focuses Undo', async () => {
        const { wrapper, feed } = mountField(secret());
        const input = () => wrapper.get('input');

        expect(input().attributes('aria-describedby')).toBe('api_key-withheld');

        const clear = wrapper
            .findAll('button')
            .find((b) => b.text() === messages.form_withheld_clear)!;
        await clear.trigger('click');

        expect(wrapper.emitted('update')?.[0]).toEqual([KINETIX_CLEAR_VALUE]);
        await feed();
        await wrapper.vm.$nextTick();

        // The control never shows the sentinel.
        expect((input().element as HTMLInputElement).value).toBe('');
        expect(wrapper.html()).not.toContain(KINETIX_CLEAR_VALUE);

        // The status line replaces the hint on the describedby chain.
        const status = wrapper.get('#api_key-cleared');
        expect(status.text()).toBe(messages.form_withheld_cleared);
        expect(input().attributes('aria-describedby')).toBe('api_key-cleared');
        expect(wrapper.find('#api_key-withheld').exists()).toBe(false);

        // Announced through the shared live region.
        await flushFrames();
        expect(
            document.getElementById('kinetix-live-region')?.textContent,
        ).toBe(messages.form_withheld_cleared);

        const undo = wrapper
            .findAll('button')
            .find((b) => b.text() === messages.form_withheld_undo)!;
        expect(document.activeElement).toBe(undo.element);
    });

    it('Undo goes back to blank (keep) and returns focus to the clear button', async () => {
        const { wrapper, feed } = mountField(secret(), KINETIX_CLEAR_VALUE);

        await wrapper
            .findAll('button')
            .find((b) => b.text() === messages.form_withheld_undo)!
            .trigger('click');

        const updates = wrapper.emitted('update')!;
        expect(updates[updates.length - 1]).toEqual(['']);
        await feed();
        await wrapper.vm.$nextTick();

        const clear = wrapper
            .findAll('button')
            .find((b) => b.text() === messages.form_withheld_clear)!;
        expect(clear.exists()).toBe(true);
        expect(document.activeElement).toBe(clear.element);
        expect(wrapper.get('input').attributes('aria-describedby')).toBe(
            'api_key-withheld',
        );
    });

    it('typing a new value cancels the clear', async () => {
        const { wrapper, feed } = mountField(secret(), KINETIX_CLEAR_VALUE);
        const input = wrapper.get('input');

        await input.setValue('sk_new');
        const updates = wrapper.emitted('update')!;
        expect(updates[updates.length - 1]).toEqual(['sk_new']);
        await feed();

        expect(wrapper.find('#api_key-cleared').exists()).toBe(false);
        expect(
            wrapper
                .findAll('button')
                .some((b) => b.text() === messages.form_withheld_clear),
        ).toBe(true);
    });

    it('an empty update while cleared (a blur, a reset) does not cancel it', async () => {
        const { wrapper } = mountField(secret(), KINETIX_CLEAR_VALUE);

        await wrapper.get('input').setValue('');

        expect(wrapper.emitted('update')).toBeUndefined();
        expect(wrapper.find('#api_key-cleared').exists()).toBe(true);
    });

    it('a non-clearable field never turns the sentinel into a status', () => {
        const { wrapper } = mountField(
            secret({ isClearable: false }),
            KINETIX_CLEAR_VALUE,
        );

        expect((wrapper.get('input').element as HTMLInputElement).value).toBe(
            '',
        );
        expect(wrapper.find('#api_key-cleared').exists()).toBe(false);
    });
});

describe('clear-control strings', () => {
    it('exist in every shipped locale', () => {
        const langDir = path.resolve(__dirname, '../../../resources/lang');
        const locales = fs
            .readdirSync(langDir)
            .filter((entry) =>
                fs.existsSync(path.join(langDir, entry, 'kinetix.php')),
            );

        expect(locales.length).toBeGreaterThanOrEqual(7);

        for (const locale of locales) {
            const source = fs.readFileSync(
                path.join(langDir, locale, 'kinetix.php'),
                'utf8',
            );

            for (const key of Object.keys(messages)) {
                expect(source, `${locale}: ${key}`).toContain(`'${key}'`);
            }
        }
    });
});
