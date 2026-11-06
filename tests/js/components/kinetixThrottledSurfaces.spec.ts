import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

/**
 * A throttled request (429) says what the server says ("Too many attempts.
 * Try again in N seconds."), never a generic "failed", and the button that
 * fired it is usable again.
 */
const THROTTLED = 'Too many attempts. Try again in 42 seconds.';

const throttled = () =>
    Object.assign(new Error(THROTTLED), {
        status: 429,
        code: 'throttled',
        retryAfter: 42,
    });

const form = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
    errors: {} as Record<string, string>,
    processing: false,
    post: vi.fn(),
    reset: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
    useForm: () => form,
    router: { reload: vi.fn(), visit: vi.fn() },
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
    isKinetixAbort: () => false,
}));

const toastError = vi.fn();
const toastSuccess = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => toastSuccess(...args),
        error: (...args: unknown[]) => toastError(...args),
    },
}));

import KinetixGdprPanel from '@/components/KinetixGdprPanel.vue';
import KinetixMailTemplates from '@/components/KinetixMailTemplates.vue';
import KinetixPasswordChange from '@/components/KinetixPasswordChange.vue';
import KinetixReportLauncher from '@/components/KinetixReportLauncher.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                mail_test_email: 'Email address',
                mail_send_test: 'Send test',
                mail_test_self_hint: 'Test emails go to your own address.',
            },
        },
    },
});

const mountIt = (component: unknown, props: Record<string, unknown> = {}) =>
    mount(component as never, {
        props,
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

beforeEach(() => {
    fetchMock.mockReset();
    toastError.mockReset();
    toastSuccess.mockReset();
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('429 on the surfaces that queue work or send mail', () => {
    it('GDPR export shows the server message and re-enables the button', async () => {
        fetchMock.mockRejectedValueOnce(throttled());
        const w = mountIt(KinetixGdprPanel);

        const button = w.findAll('button')[0];
        await button.trigger('click');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith(THROTTLED);
        expect(button.attributes('disabled')).toBeUndefined();
    });

    it('GDPR export keeps its own message for any other failure', async () => {
        fetchMock.mockRejectedValueOnce(
            Object.assign(new Error('Server Error'), { status: 500 }),
        );
        const w = mountIt(KinetixGdprPanel);

        await w.findAll('button')[0].trigger('click');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('kinetix.gdpr_export_failed');
    });

    it('a report launch shows the server message and ends its pending state', async () => {
        fetchMock.mockImplementation(
            async (url: string, options?: { method?: string }) => {
                if (options?.method === 'POST') {
                    throw throttled();
                }

                return {
                    types: [
                        {
                            token: 'tok',
                            label: 'Orders',
                            description: null,
                            format: 'csv',
                        },
                    ],
                };
            },
        );
        const w = mountIt(KinetixReportLauncher);
        await flushPromises();

        const run = w
            .findAll('button')
            .find((b) => b.text().includes('kinetix.run_now'))!;
        await run.trigger('click');
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith(THROTTLED);
        expect(run.attributes('disabled')).toBeUndefined();
    });

    it('a mail test shows the server message, in self mode on a fixed, described field', async () => {
        fetchMock.mockImplementation(
            async (url: string, options?: { method?: string }) => {
                if (url.endsWith('/test')) {
                    throw throttled();
                }

                if (options?.method === 'POST') {
                    return { subject: '', html: '' };
                }

                return {
                    templates: [
                        {
                            id: 1,
                            key: 'welcome',
                            name: 'Welcome',
                            subject: 'Hi',
                            body: 'Hello',
                            format: 'markdown',
                            variables: [],
                            enabled: true,
                        },
                    ],
                    test: { recipients: 'self', email: 'ada@example.com' },
                };
            },
        );
        const w = mountIt(KinetixMailTemplates);
        await flushPromises();

        const input = w.get('[data-test="mail-test-email"]');
        expect((input.element as HTMLInputElement).value).toBe(
            'ada@example.com',
        );
        expect(input.attributes('readonly')).toBeDefined();
        expect(input.attributes('aria-label')).toBe('Email address');
        const hintId = input.attributes('aria-describedby')!;
        expect(w.get(`#${hintId}`).text()).toBe(
            'Test emails go to your own address.',
        );

        const send = w.get('[data-test="mail-send-test"]');
        await send.trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/mail-templates/1/test',
            {
                method: 'POST',
                body: { email: 'ada@example.com' },
            },
        );
        expect(toastError).toHaveBeenCalledWith(THROTTLED);
        expect(send.attributes('disabled')).toBeUndefined();
        expect(send.attributes('aria-busy')).toBeUndefined();
    });

    it("an older server without the test meta keeps today's free field", async () => {
        fetchMock.mockResolvedValue({ templates: [] });
        const w = mountIt(KinetixMailTemplates);
        await flushPromises();

        const input = w.get('[data-test="mail-test-email"]');
        expect(input.attributes('readonly')).toBeUndefined();
        expect(input.attributes('aria-describedby')).toBeUndefined();
    });
});

describe('429 on the password change (an Inertia form)', () => {
    it('renders the throttle message the server put in errors.password', () => {
        form.errors = { password: THROTTLED };
        const w = mountIt(KinetixPasswordChange, { action: '/password' });

        expect(w.text()).toContain(THROTTLED);
        form.errors = {};
    });
});
