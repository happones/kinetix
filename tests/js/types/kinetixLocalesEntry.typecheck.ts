/**
 * Type-level test, checked by `npm run types:check` (vue-tsc), never run: the
 * entry wiring `kinetix:install --lazy-locales` writes must typecheck against
 * vue-i18n's createI18n and Inertia's createInertiaApp with no host cast.
 */
import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createI18n } from 'vue-i18n';
import {
    attachKinetixI18n,
    kinetixLoadedMessages,
    kinetixLocaleLoaderFromGlob,
    kinetixPageLocale,
    registerKinetixLocales,
    withKinetixLocales,
} from '@/plugins/kinetixLocales';
import type { KinetixLocaleLoader } from '@/plugins/kinetixLocales';

registerKinetixLocales({
    loader: kinetixLocaleLoaderFromGlob(
        import.meta.glob(['./locales/*.ts', '!./locales/index.ts']),
    ),
    fallbackLocale: 'en',
});

// The opt-in second glob for root JSON catalogs.
kinetixLocaleLoaderFromGlob(
    import.meta.glob('../../lang/*.json'),
    import.meta.glob(['./locales/*.ts', '!./locales/index.ts']),
);

// The `{ import: 'default' }` form is accepted as well.
kinetixLocaleLoaderFromGlob(
    import.meta.glob('./locales/*.ts', { import: 'default' }),
);

// A hand-written loader returning a JSON module's object.
const custom: KinetixLocaleLoader = async (locale) =>
    ({ en: { kinetix: { hello: 'Hello' } } })[locale];
registerKinetixLocales({ loader: custom });

void createInertiaApp({
    resolve: withKinetixLocales((name) =>
        import.meta
            .glob<DefineComponent>('./pages/**/*.vue')
            [`./pages/${name}.vue`](),
    ),
    withApp(app, { page }) {
        const i18n = createI18n({
            legacy: false,
            locale: kinetixPageLocale(page),
            fallbackLocale: 'en',
            messages: kinetixLoadedMessages(),
        });

        attachKinetixI18n(i18n);
        app.use(i18n);
    },
});

// The idle fallback option.
withKinetixLocales(async () => ({}) as DefineComponent, {
    preloadFallback: 'idle',
});
