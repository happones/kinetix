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
    const current = ref<string>(
        state.value?.current ?? (i18nLocale.value as string),
    );
    const saving = ref(false);

    async function setLocale(code: string): Promise<void> {
        if (code === current.value) {
            return;
        }

        // Flip the SPA immediately for instant feedback…
        const previous = current.value;
        current.value = code;
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
            current.value = previous;
            i18nLocale.value = previous;

            throw error;
        } finally {
            saving.value = false;
        }
    }

    return { locales, current, saving, setLocale };
}
