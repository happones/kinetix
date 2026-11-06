import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import { createApp, defineComponent, h } from 'vue';
import type { DefineComponent } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n, useI18n } from 'vue-i18n';
import {
    attachKinetixI18n,
    kinetixLoadedMessages,
    kinetixLocaleLoaderFromGlob,
    kinetixPageLocale,
    registerKinetixLocales,
    withKinetixLocales,
} from '@/plugins/kinetixLocales';

/**
 * The entry wiring `kinetix:install --lazy-locales` writes, run through the
 * real createInertiaApp: both shapes (withApp only, and an existing setup()
 * that builds i18n inline) must install i18n and paint translated text on the
 * very first render.
 */

const renders: string[] = [];

const Dashboard = defineComponent({
    setup() {
        const { t } = useI18n();

        return () => {
            const text = t('kinetix.hello');
            renders.push(text);

            return h('p', { id: 'greeting' }, text);
        };
    },
});

const page = {
    component: 'Dashboard',
    props: { locale: 'es', errors: {} },
    url: '/dashboard',
    version: null,
    clearHistory: false,
    encryptHistory: false,
};

/** What the generator's `--multi-locales` output looks like to the glob. */
const generated = {
    './locales/en.ts': async () => ({
        default: { en: { kinetix: { hello: 'Hello' } } },
    }),
    './locales/es.ts': async () => ({
        default: { es: { kinetix: { hello: 'Hola' } } },
    }),
    './locales/index.ts': async () => ({ default: {} }),
};

let resolveCalls: string[];

const resolve = (name: string) => {
    resolveCalls.push(name);

    return Promise.resolve({
        default: Dashboard as unknown as DefineComponent,
    });
};

beforeEach(() => {
    renders.length = 0;
    resolveCalls = [];
    document.body.innerHTML = '<div id="app"></div>';
    registerKinetixLocales({
        loader: kinetixLocaleLoaderFromGlob(generated),
        fallbackLocale: 'en',
    });
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('lazy locales through createInertiaApp', () => {
    it('withApp only: i18n is installed and the first render is translated', async () => {
        let installed: ReturnType<typeof createI18n> | null = null;

        await createInertiaApp({
            page,
            resolve: withKinetixLocales(resolve),
            withApp(app, { page: initial }) {
                const i18n = createI18n({
                    legacy: false,
                    locale: kinetixPageLocale(initial),
                    fallbackLocale: 'en',
                    messages: kinetixLoadedMessages(),
                });

                attachKinetixI18n(i18n);
                app.use(i18n);
                app.use(createPinia());
                installed = i18n;
            },
        });

        expect(resolveCalls).toContain('Dashboard');
        expect(installed).not.toBeNull();
        expect(installed!.global.locale.value).toBe('es');
        expect(Object.keys(installed!.global.messages.value).sort()).toEqual([
            'en',
            'es',
        ]);
        expect(document.getElementById('greeting')?.textContent).toBe('Hola');
        expect(renders[0]).toBe('Hola');
        expect(renders).not.toContain('kinetix.hello');
    });

    it('setup(): i18n built inline from props.initialPage, translated on the first render', async () => {
        let installed: ReturnType<typeof createI18n> | null = null;

        await createInertiaApp({
            page,
            resolve: withKinetixLocales(resolve),
            setup({ el, App, props, plugin }) {
                const i18n = createI18n({
                    legacy: false,
                    locale: kinetixPageLocale(props.initialPage),
                    fallbackLocale: 'en',
                    messages: kinetixLoadedMessages(),
                });

                attachKinetixI18n(i18n);
                installed = i18n;

                // The order the installer writes: i18n and Pinia first.
                createApp({ render: () => h(App, props) })
                    .use(i18n)
                    .use(createPinia())
                    .use(plugin)
                    .mount(el!);
            },
        });

        expect(installed).not.toBeNull();
        expect(installed!.global.locale.value).toBe('es');
        expect(installed!.global.getLocaleMessage('es')).toEqual({
            kinetix: { hello: 'Hola' },
        });
        expect(document.getElementById('greeting')?.textContent).toBe('Hola');
        expect(renders[0]).toBe('Hola');
        expect(renders).not.toContain('kinetix.hello');
    });

    it('eager wiring (no loader registered) still mounts unchanged', async () => {
        vi.resetModules();
        const eager = await import('@/plugins/kinetixLocales');

        await createInertiaApp({
            page,
            resolve: eager.withKinetixLocales(resolve),
            withApp(app) {
                app.use(
                    createI18n({
                        legacy: false,
                        locale: 'es',
                        messages: { es: { kinetix: { hello: 'Hola' } } },
                    }),
                );
            },
        });

        expect(eager.kinetixLocalesEnabled()).toBe(false);
        expect(renders[0]).toBe('Hola');
    });
});
