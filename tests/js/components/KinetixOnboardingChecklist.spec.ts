import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const fetchMock = vi.fn();
const pageProps: Record<string, unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixOnboardingChecklist from '@/components/KinetixOnboardingChecklist.vue';
import type { KinetixOnboarding } from '@/types/kinetix';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                onboarding_title: 'Getting started',
                onboarding_progress: '{completed} of {total} complete',
                onboarding_progress_short: '{completed} of {total}',
                onboarding_dismiss: 'Dismiss',
                onboarding_mark_done: 'Mark as done',
                onboarding_mark_done_step: 'Mark {step} as done',
                onboarding_go: 'Go',
                onboarding_status_completed: 'Completed',
                onboarding_status_pending: 'Not completed',
            },
        },
    },
});

const state = (overrides: Partial<KinetixOnboarding> = {}): KinetixOnboarding =>
    ({
        steps: [
            {
                key: 'verify-email',
                title: 'Verify your email',
                description: 'Confirm your address.',
                ctaLabel: 'Resend',
                ctaHref: '/email/verify',
                icon: 'mail',
                completed: true,
                manual: false,
            },
            {
                key: 'invite',
                title: 'Invite a teammate',
                description: null,
                ctaLabel: 'Invite',
                ctaHref: '/team/members',
                icon: 'user',
                completed: false,
                manual: false,
            },
            {
                key: 'read-docs',
                title: 'Read the quickstart',
                description: null,
                ctaLabel: null,
                ctaHref: null,
                icon: null,
                completed: false,
                manual: true,
            },
        ],
        completedCount: 1,
        total: 3,
        complete: false,
        dismissed: false,
        ...overrides,
    }) as KinetixOnboarding;

async function mountChecklist(props: Record<string, unknown> = {}) {
    const wrapper = mount(KinetixOnboardingChecklist, {
        props,
        global: { plugins: [i18n] },
    });

    await flushPromises();

    return wrapper;
}

describe('KinetixOnboardingChecklist', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValue(state());
        pageProps.kinetix_onboarding = undefined;
    });

    it('renders from the page payload without a request', async () => {
        pageProps.kinetix_onboarding = state();

        const wrapper = await mountChecklist({ variant: 'sidebar' });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Invite a teammate');
    });

    it('renders the card variant by default', async () => {
        const wrapper = await mountChecklist();

        expect(wrapper.find('h2').text()).toBe('Getting started');
        expect(wrapper.text()).toContain('1 of 3 complete');
        expect(wrapper.text()).toContain('Mark as done');
    });

    it('names the progress bar and spells its value out for screen readers', async () => {
        const wrapper = await mountChecklist();
        const bar = wrapper.find('[role="progressbar"]');

        expect(bar.attributes('aria-label')).toBe('Getting started');
        expect(bar.attributes('aria-valuenow')).toBe('33');
        expect(bar.attributes('aria-valuetext')).toBe('1 of 3 complete');
    });

    it('stays hidden while the checklist is dismissed', async () => {
        fetchMock.mockResolvedValue(state({ dismissed: true }));

        const wrapper = await mountChecklist();

        expect(wrapper.find('section').exists()).toBe(false);
    });

    it('hides itself once complete unless asked to stay', async () => {
        fetchMock.mockResolvedValue(state({ complete: true }));

        expect((await mountChecklist()).find('section').exists()).toBe(false);
        expect(
            (await mountChecklist({ hideWhenComplete: false }))
                .find('section')
                .exists(),
        ).toBe(true);
    });

    describe('sidebar variant', () => {
        const sidebar = () => mountChecklist({ variant: 'sidebar' });

        it('renders the terse counter instead of the sentence', async () => {
            const wrapper = await sidebar();

            expect(wrapper.text()).toContain('1 of 3');
            expect(wrapper.text()).not.toContain('1 of 3 complete');
        });

        it('folds away when the shadcn sidebar collapses to icons', async () => {
            const wrapper = await sidebar();

            expect(wrapper.find('section').classes()).toContain(
                'group-data-[collapsible=icon]:hidden',
            );
        });

        it('drops the step descriptions the card variant shows', async () => {
            expect((await sidebar()).text()).not.toContain(
                'Confirm your address.',
            );
        });

        it('gives the dismiss control an accessible name', async () => {
            const wrapper = await sidebar();

            expect(wrapper.find('button[aria-label="Dismiss"]').exists()).toBe(
                true,
            );
        });

        it('ticks a manual step off from its leading circle', async () => {
            const wrapper = await sidebar();
            const tick = wrapper.find(
                'button[aria-label="Mark Read the quickstart as done"]',
            );

            expect(tick.exists()).toBe(true);

            await tick.trigger('click');

            expect(fetchMock).toHaveBeenLastCalledWith(
                '/_kinetix/onboarding/complete',
                { method: 'POST', body: { step: 'read-docs' } },
            );
        });

        it('stretches the CTA across the row for pending steps only', async () => {
            const wrapper = await sidebar();
            const links = wrapper.findAll('a');

            expect(links).toHaveLength(1);
            expect(links[0].attributes('href')).toBe('/team/members');
            expect(links[0].classes()).toContain('after:inset-0');
        });

        it('spells completion out instead of leaning on the strike-through', async () => {
            const rows = (await sidebar()).findAll('li');

            expect(rows[0].find('.sr-only').text()).toBe('Completed');
            expect(rows[0].text()).toContain('Verify your email');
            expect(rows[1].find('.sr-only').text()).toBe('Not completed');
        });
    });
});
