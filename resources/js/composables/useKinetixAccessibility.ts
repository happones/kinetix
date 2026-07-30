import { usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixAccessibility, KinetixSharedProps } from '@/types/kinetix';

export const KINETIX_A11Y_STORAGE = 'kinetix.accessibility';

const DEFAULTS: KinetixAccessibility = {
    reducedMotion: false,
    highContrast: false,
    textSize: 'normal',
    underlineLinks: false,
    enhancedFocus: false,
};

/**
 * Apply accessibility preferences as classes on <html>. Safe to call before the
 * Vue app mounts (used by the KinetixAccessibility plugin for flash-free apply).
 */
export function applyKinetixAccessibility(
    prefs: Partial<KinetixAccessibility>,
): void {
    if (typeof document === 'undefined') {
        return;
    }

    const c = document.documentElement.classList;
    c.toggle('kx-reduce-motion', !!prefs.reducedMotion);
    c.toggle('kx-high-contrast', !!prefs.highContrast);
    c.toggle('kx-underline-links', !!prefs.underlineLinks);
    c.toggle('kx-enhanced-focus', !!prefs.enhancedFocus);
    c.remove('kx-text-large', 'kx-text-x-large');

    if (prefs.textSize === 'large') {
        c.add('kx-text-large');
    } else if (prefs.textSize === 'x-large') {
        c.add('kx-text-x-large');
    }
}

/**
 * Reactive per-user accessibility preferences. Seeds from the shared
 * `kinetix_accessibility` Inertia prop, applies + mirrors to localStorage, and
 * persists changes to the server.
 */
export function useKinetixAccessibility() {
    const page = usePage<KinetixSharedProps>();
    const shared = (page.props as Record<string, unknown>)
        .kinetix_accessibility as Partial<KinetixAccessibility> | null;

    const prefs = reactive<KinetixAccessibility>({
        ...DEFAULTS,
        ...(shared ?? {}),
    });

    const persist = (): void => {
        try {
            localStorage.setItem(KINETIX_A11Y_STORAGE, JSON.stringify(prefs));
        } catch {
            // ignore storage failures
        }

        applyKinetixAccessibility(prefs);
    };

    // Reflect current prefs on mount (covers SPA visits where the plugin's
    // initial pass already ran).
    persist();

    async function set<K extends keyof KinetixAccessibility>(
        key: K,
        value: KinetixAccessibility[K],
    ): Promise<void> {
        prefs[key] = value;
        persist();

        // Server persistence is best-effort: on guest pages (login, account-setup
        // wizard) there is no authenticated user, so the endpoint 401s — the
        // localStorage mirror + applied classes still take effect.
        try {
            await kinetixFetch(`/${kinetixRoutePrefix(page)}/accessibility`, {
                method: 'POST',
                body: { [key]: value },
            });
        } catch {
            // ignore — preference is kept client-side
        }
    }

    return { prefs, set };
}
