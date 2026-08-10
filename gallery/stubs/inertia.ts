import { defineComponent, h } from 'vue';

/**
 * Minimal @inertiajs/vue3 stub for the screenshot gallery — just enough for the
 * Kinetix components to mount without a real Inertia runtime. No navigation
 * actually happens (screenshots are static).
 */
const page = {
    props: {
        kinetix_config: { route_prefix: '_kinetix' },
        // Surfaced for the impersonation banner specimen.
        kinetix_impersonation: {
            active: true,
            user: { id: 1, name: 'Ada Lovelace' },
        },
        // Surfaced for the accessibility panel specimen.
        kinetix_accessibility: {
            reducedMotion: true,
            highContrast: false,
            textSize: 'large',
            underlineLinks: true,
            enhancedFocus: false,
        },
        // Surfaced for the language switcher specimen.
        kinetix_locale: {
            enabled: true,
            current: 'en',
            locales: [
                { code: 'en', label: 'English' },
                { code: 'es', label: 'Español' },
                { code: 'fr', label: 'Français' },
                { code: 'pt', label: 'Português' },
            ],
        },
        // Surfaced for the team switcher specimen.
        kinetix_teams: {
            enabled: true,
            current: { id: 1, name: 'Acme Inc.' },
            createUrl: '/teams/create',
            teams: [
                { id: 1, name: 'Acme Inc.', url: '#', current: true },
                { id: 2, name: 'Globex', url: '#', current: false },
                { id: 3, name: 'Initech', url: '#', current: false },
            ],
        },
        // Surfaced for the plan-lock specimens: a plan WITHOUT the gated
        // capability, so every <KinetixPlanLock> renders its locked state.
        kinetix_billing: {
            enabled: true,
            plan: {
                slug: 'starter',
                name: 'Starter',
                features: { capabilities: { discord: false } },
            },
            upgradeUrl: '/billing',
            lock: {
                variant: 'card',
                modal: true,
                blur: true,
                badgeLabel: null,
            },
        },
        // Surfaced for the presence / online-users specimen.
        kinetix_presence: { enabled: true, channel: 'kinetix-presence' },
        // Surfaced for the queue-stats specimen (poll 0 → no interval).
        kinetix_queue: { enabled: true, poll: 0 },
        // Surfaced for the health-status specimen (poll 0 → no interval).
        kinetix_health: { enabled: true, poll: 0 },
        // Surfaced for the cookie-consent specimen.
        kinetix_cookie_consent: {
            enabled: true,
            cookieName: 'kinetix_gallery_cookie_consent',
            expiryDays: 365,
            position: 'bottom',
            policyUrl: '/cookie-policy',
        },
        // Surfaced for the reports-center specimens (poll 0 → no interval).
        kinetix_reports_center: { enabled: true, poll: 0 },
        // Surfaced for the confidential-fields unlock specimen (locked state).
        kinetix_confidential: {
            enabled: true,
            ttlMinutes: 5,
            unlockedUntil: null,
        },
    },
    url: '/',
    component: 'Gallery',
    version: null as string | null,
};

export function usePage() {
    return page;
}

export const router = {
    get() {},
    post() {},
    put() {},
    patch() {},
    delete() {},
    visit() {},
    reload() {},
    cancelAll() {},
    on() {
        return () => {};
    },
};

export const Link = defineComponent({
    name: 'InertiaLinkStub',
    props: { href: { type: String, default: '#' } },
    setup(_, { slots }) {
        return () => h('a', { href: '#' }, slots.default?.());
    },
});

export function usePoll() {
    return { start() {}, stop() {} };
}

export function useForm(data: Record<string, unknown> = {}) {
    return {
        ...data,
        processing: false,
        errors: {},
        post() {},
        put() {},
        get() {},
        delete() {},
    };
}

export const Head = defineComponent({
    name: 'InertiaHeadStub',
    setup: () => () => null,
});
