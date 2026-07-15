import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { h } from 'vue';

const completeMock = vi.fn().mockResolvedValue(undefined);

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('@/composables/useKinetixWizard', () => ({
    useKinetixWizard: () => ({ complete: completeMock, status: vi.fn() }),
}));

import KinetixWizard from '@/components/KinetixWizard.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: {
        en: {
            kinetix: {
                wizard_next: 'Next',
                wizard_back: 'Back',
                wizard_finish: 'Finish',
                wizard_step_of: 'Step {current} of {total}',
            },
        },
    },
});

const steps = [
    { key: 'a', label: 'Account' },
    { key: 'b', label: 'Profile' },
];

const mountWizard = (props: Record<string, any> = {}) =>
    mount(KinetixWizard, {
        props: { steps, ...props },
        slots: {
            a: () => h('div', { id: 'panel-a' }, 'A content'),
            b: () => h('div', { id: 'panel-b' }, 'B content'),
        },
        global: { plugins: [i18n] },
    });

const nextButton = (w: any) =>
    w.findAll('button').find((b: any) => b.text() === 'Next');
const backButton = (w: any) =>
    w.findAll('button').find((b: any) => b.text() === 'Back');

describe('KinetixWizard', () => {
    it("shows the first step's slot content and advances on Next", async () => {
        const wrapper = mountWizard();

        expect(wrapper.find('#panel-a').exists()).toBe(true);
        expect(wrapper.find('#panel-b').exists()).toBe(false);

        await nextButton(wrapper)!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('#panel-b').exists()).toBe(true);
        expect(wrapper.emitted('step-change')?.[0]).toEqual([1]);
    });

    it('blocks advancing when beforeNext returns false', async () => {
        const wrapper = mountWizard({ beforeNext: () => false });

        await nextButton(wrapper)!.trigger('click');
        await wrapper.vm.$nextTick();

        // Still on the first step.
        expect(wrapper.find('#panel-a').exists()).toBe(true);
        expect(wrapper.emitted('step-change')).toBeUndefined();
    });

    it('emits finish and marks completion via slug on the last step', async () => {
        const wrapper = mountWizard({ slug: 'account-setup', step: 1 });

        // On the last step the primary button reads Finish.
        const finish = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Finish');
        expect(finish).toBeTruthy();

        await finish!.trigger('click');
        await wrapper.vm.$nextTick();

        expect(completeMock).toHaveBeenCalledWith('account-setup');
        expect(wrapper.emitted('finish')).toBeTruthy();
    });

    it('Back is disabled on the first step', () => {
        const wrapper = mountWizard();
        expect(backButton(wrapper)!.attributes('disabled')).toBeDefined();
    });

    it('renders the simple variant with a step counter', () => {
        const wrapper = mountWizard({ variant: 'simple' });
        expect(wrapper.text()).toContain('Step 1 of 2');
    });

    it('renders a button per step for the panels variant', () => {
        const wrapper = mountWizard({ variant: 'panels' });
        // Two step pills + Back + Next.
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels.some((t) => t.includes('Account'))).toBe(true);
        expect(labels.some((t) => t.includes('Profile'))).toBe(true);
    });

    it('renders the official reka stepper by default', () => {
        const wrapper = mountWizard();
        // Reka StepperRoot defaults to a horizontal orientation.
        expect(wrapper.find('[data-orientation="horizontal"]').exists()).toBe(
            true,
        );
        expect(wrapper.text()).toContain('Account');
        expect(wrapper.text()).toContain('Profile');
    });

    it('supports a vertical stepper orientation', () => {
        const wrapper = mountWizard({ orientation: 'vertical' });
        expect(wrapper.find('[data-orientation="vertical"]').exists()).toBe(
            true,
        );
    });

    it('stretches the horizontal stepper full-width by default', () => {
        const wrapper = mountWizard();
        const root = wrapper.find('[data-orientation="horizontal"]');
        expect(root.classes()).toContain('w-full');
    });

    it('renders a compact, centered stepper when fullWidth is false', () => {
        const wrapper = mountWizard({ fullWidth: false });
        const root = wrapper.find('[data-orientation="horizontal"]');
        expect(root.classes()).not.toContain('w-full');
        expect(root.classes()).toContain('w-fit');
        expect(root.classes()).toContain('mx-auto');
    });

    it('defaults to the inline step layout (label hidden below sm:)', () => {
        const wrapper = mountWizard();
        expect(wrapper.text()).toContain('Account');
        // Inline wraps the label in a `sm:block hidden` span.
        expect(wrapper.html()).toContain('sm:block');
    });

    it('renders the stacked step layout with the label always visible', () => {
        const wrapper = mountWizard({ stepLayout: 'stacked' });
        expect(wrapper.text()).toContain('Account');
        expect(wrapper.text()).toContain('Profile');
        // Stacked never hides the label behind a breakpoint.
        expect(wrapper.html()).not.toContain('sm:block');
    });

    it('renders the tooltip step layout with the label as an aria-label, not visible text', () => {
        const wrapper = mountWizard({ stepLayout: 'tooltip' });
        // The label isn't rendered as visible text (the tooltip is closed by default)...
        expect(wrapper.text()).not.toContain('Account');
        expect(wrapper.text()).not.toContain('Profile');
        // ...but it's still available to assistive tech via aria-label.
        const trigger = wrapper
            .findAll('button')
            .find((b) => b.attributes('aria-label') === 'Account');
        expect(trigger).toBeTruthy();
    });

    it('applies a per-step color to its indicator once active/complete', () => {
        const wrapper = mountWizard({
            steps: [
                { key: 'a', label: 'Account', color: 'success' },
                { key: 'b', label: 'Profile' },
            ],
        });

        // Step "a" is active (index 0) and colored — solid success fill.
        expect(wrapper.html()).toContain('bg-success');
    });

    it('leaves an upcoming step neutral regardless of its configured color', () => {
        const wrapper = mountWizard({
            steps: [
                { key: 'a', label: 'Account' },
                { key: 'b', label: 'Profile', color: 'danger' },
            ],
        });

        // Step "b" is upcoming — its color must not leak into the neutral state.
        expect(wrapper.html()).not.toContain('bg-destructive');
    });

    it('marks errored steps destructive on the indicator', () => {
        const wrapper = mountWizard({ errorSteps: [1] });
        expect(wrapper.html()).toContain('bg-destructive');
    });

    it('keeps an errored step navigable even under linear gating', async () => {
        // Step 1 is unreached (linear default) so normally locked, but it holds
        // an error — the user must be able to jump straight to it.
        const wrapper = mountWizard({ variant: 'panels', errorSteps: [1] });

        const profilePill = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Profile'))!;
        expect(profilePill.attributes('disabled')).toBeUndefined();

        await profilePill.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('#panel-b').exists()).toBe(true);
        expect(wrapper.emitted('step-change')?.at(-1)).toEqual([1]);
    });

    it('locks an unreached step with no error under linear gating', () => {
        const wrapper = mountWizard({ variant: 'panels' });
        const profilePill = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Profile'))!;
        expect(profilePill.attributes('disabled')).toBeDefined();
    });
});
