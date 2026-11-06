import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { ensureKinetixLocale } from '@/plugins/kinetixLocales';
import type { KinetixLocaleOption, KinetixSharedProps } from '@/types/kinetix';

/**
 * Self-service language switcher: read the supported locales + the active one
 * from the shared `kinetix_locale` prop, and switch the locale (persisted
 * server-side, applied instantly in the SPA via vue-i18n). With lazy locales
 * (`registerKinetixLocales`), the new locale's messages load before it is
 * applied, so the UI never shows raw keys.
 */
/**
 * The latest pick across every switcher on the page: a pick made anywhere
 * (even of the current locale) supersedes one still waiting for its messages.
 * Only a counter, never request data, so sharing it under SSR is harmless.
 */
let latestPick = 0;

export function useKinetixLocale() {
    const page = usePage<KinetixSharedProps>();
    const composer = useI18n({ useScope: 'global' });
    const { locale: i18nLocale } = composer;

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

    // This switcher's picks still waiting for their messages, and its POST.
    const loading = new Set<number>();
    let persisting = 0;
    const syncSaving = (): void => {
        saving.value = loading.size > 0 || persisting > 0;
    };

    async function setLocale(code: string): Promise<void> {
        const pick = ++latestPick;
        // An earlier pick here is superseded: stop showing it as in progress.
        loading.clear();

        if (code === current.value) {
            syncSaving();

            return;
        }

        loading.add(pick);
        syncSaving();

        let ready: boolean;

        try {
            // Lazy locales: wait for the messages before switching, so the
            // UI never renders raw keys. Eager mode resolves at once.
            ready = await ensureKinetixLocale(code, composer);
        } finally {
            loading.delete(pick);
            syncSaving();
        }

        if (pick !== latestPick) {
            return;
        }

        if (!ready) {
            throw new Error(
                `Kinetix could not load the "${code}" translation messages.`,
            );
        }

        // Flip the SPA immediately for instant feedback…
        const previous = current.value;
        i18nLocale.value = code;
        persisting++;
        syncSaving();

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
            persisting--;
            syncSaving();
        }
    }

    return { locales, current, saving, setLocale };
}
