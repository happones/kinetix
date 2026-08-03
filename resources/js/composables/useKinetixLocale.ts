import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixLocaleOption, KinetixSharedProps } from '@/types/kinetix';

/**
 * Self-service language switcher: read the supported locales + the active one
 * from the shared `kinetix_locale` prop, and switch the locale (persisted
 * server-side, applied instantly in the SPA via vue-i18n).
 */
export function useKinetixLocale() {
    const page = usePage<KinetixSharedProps>();
    const { locale: i18nLocale } = useI18n();

    const state = computed(() => page.props.kinetix_locale);
    const locales = computed<KinetixLocaleOption[]>(
        () => state.value?.locales ?? [],
    );

    // Derived from vue-i18n's own locale, which is a single ref per Vue app, so
    // every switcher on the page reads the same value — a page with a header
    // dropdown AND a settings select can't have them drift apart. A local ref
    // here would be per-instance, and a module-level one would leak between
    // requests under SSR.
    const current = computed<string>(
        () => (i18nLocale.value as string) || state.value?.current || 'en',
    );
    const saving = ref(false);

    async function setLocale(code: string): Promise<void> {
        if (code === current.value) {
            return;
        }

        // Flip the SPA immediately for instant feedback…
        const previous = current.value;
        i18nLocale.value = code;
        saving.value = true;

        try {
            // …persist server-side (works for guests too)…
            await kinetixFetch(`/${kinetixRoutePrefix(page)}/locale`, {
                method: 'POST',
                body: { locale: code },
            });
            // …then refresh so server-rendered strings pick up the new locale.
            router.reload();
        } catch (error) {
            // Roll back the optimistic switch on failure.
            i18nLocale.value = previous;

            throw error;
        } finally {
            saving.value = false;
        }
    }

    return { locales, current, saving, setLocale };
}
