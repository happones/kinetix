import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { KinetixCookieConsentConfig, KinetixSharedProps } from '@/types';

const DEFAULT_COOKIE_NAME = 'kinetix_cookie_consent';
const DEFAULT_EXPIRY_DAYS = 365;

function readCookie(name: string): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

function writeCookie(name: string, value: string, days: number): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.cookie = `${name}=${value};path=/;max-age=${days * 86400};SameSite=Lax`;
}

const visible = ref(false);
const checked = ref(false);

/**
 * Config-driven cookie consent bar. Whether the visitor has already
 * responded is resolved entirely client-side (a plain browser cookie, no
 * server round-trip) — the shared `kinetix_cookie_consent` prop only carries
 * the configurable bits (cookie name/expiry/position/policy link).
 */
export function useKinetixCookieConsent() {
    const page = usePage<KinetixSharedProps>();

    const config = computed<KinetixCookieConsentConfig>(
        () => page.props.kinetix_cookie_consent ?? { enabled: false },
    );

    const cookieName = computed(
        () => config.value.cookieName ?? DEFAULT_COOKIE_NAME,
    );
    const expiryDays = computed(
        () => config.value.expiryDays ?? DEFAULT_EXPIRY_DAYS,
    );

    function checkConsent(): void {
        checked.value = true;
        visible.value =
            config.value.enabled && readCookie(cookieName.value) === null;
    }

    function accept(): void {
        writeCookie(cookieName.value, 'accepted', expiryDays.value);
        visible.value = false;
    }

    function decline(): void {
        writeCookie(cookieName.value, 'declined', expiryDays.value);
        visible.value = false;
    }

    return { config, visible, checked, checkConsent, accept, decline };
}
