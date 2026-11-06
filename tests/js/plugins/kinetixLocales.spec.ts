import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n, useI18n } from 'vue-i18n';

type Plugin = typeof import('@/plugins/kinetixLocales');

const messages = {
    en: { kinetix: { hello: 'Hello', bye: 'Bye' } },
    es: { kinetix: { hello: 'Hola', bye: 'Adiós' } },
};

/** The module keeps per-app state: every test gets a fresh copy. */
async function fresh(): Promise<Plugin> {
    vi.resetModules();

    return import('@/plugins/kinetixLocales');
}

function loaderOver(
    tree: Record<string, Record<string, unknown>>,
): ReturnType<typeof vi.fn> {
    return vi.fn(async (locale: string) => tree[locale]);
}

const Greeting = defineComponent({
    setup() {
        const { t } = useI18n();

        return () => h('p', t('kinetix.hello'));
    },
});

beforeEach(() => {
    document.documentElement.lang = '';
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('kinetixLocales without a registered loader (eager mode)', () => {
    it('makes every export a no-op', async () => {
        const plugin = await fresh();
        const setLocaleMessage = vi.fn();

        expect(plugin.kinetixLocalesEnabled()).toBe(false);
        await expect(plugin.loadKinetixLocale('es')).resolves.toBe(true);
        await expect(plugin.ensureKinetixLocale('es')).resolves.toBe(true);
        expect(plugin.kinetixLoadedMessages()).toEqual({});

        plugin.attachKinetixI18n({ global: { setLocaleMessage } });
        await plugin.loadKinetixLocale('fr');
        expect(setLocaleMessage).not.toHaveBeenCalled();
    });

    it('hands back the inner component unchanged', async () => {
        const plugin = await fresh();
        const component = { name: 'Dashboard' };
        const resolve = vi.fn(() => component);

        const wrapped = plugin.withKinetixLocales(resolve);

        await expect(wrapped('Dashboard', { props: {} })).resolves.toBe(
            component,
        );
        expect(resolve).toHaveBeenCalledWith('Dashboard', { props: {} });
    });
});

describe('loadKinetixLocale', () => {
    it('shares one loader call per locale between concurrent callers, and loads the fallback once', async () => {
        const plugin = await fresh();
        const loader = loaderOver(messages);
        plugin.registerKinetixLocales({ loader, fallbackLocale: 'en' });

        const results = await Promise.all([
            plugin.loadKinetixLocale('es'),
            plugin.loadKinetixLocale('es'),
            plugin.loadKinetixLocale('es'),
        ]);
        await plugin.loadKinetixLocale('es');
        await plugin.loadKinetixLocale('en');

        expect(results).toEqual([true, true, true]);
        expect(loader.mock.calls.filter(([l]) => l === 'es')).toHaveLength(1);
        expect(loader.mock.calls.filter(([l]) => l === 'en')).toHaveLength(1);
        expect(plugin.kinetixLoadedMessages()).toEqual(messages);
    });

    it('resolves false on a failed download, warns once, and retries on the next call', async () => {
        const plugin = await fresh();
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        let failing = true;
        const loader = vi.fn(async (locale: string) => {
            if (locale !== 'en' && failing) {
                throw new Error('chunk failed');
            }

            return messages[locale as 'en' | 'es'];
        });
        plugin.registerKinetixLocales({ loader });

        await expect(
            Promise.all([
                plugin.loadKinetixLocale('es'),
                plugin.loadKinetixLocale('es'),
            ]),
        ).resolves.toEqual([false, false]);
        expect(warn).toHaveBeenCalledTimes(1);
        // The fallback still loaded, so the app can mount on it.
        expect(Object.keys(plugin.kinetixLoadedMessages())).toEqual(['en']);

        failing = false;

        await expect(plugin.loadKinetixLocale('es')).resolves.toBe(true);
        expect(plugin.kinetixLoadedMessages().es).toEqual(messages.es);
        expect(warn).toHaveBeenCalledTimes(1);
    });

    it('warns once for a locale that has no messages at all', async () => {
        const plugin = await fresh();
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        plugin.registerKinetixLocales({ loader: loaderOver(messages) });

        await expect(plugin.loadKinetixLocale('de')).resolves.toBe(false);
        await expect(plugin.loadKinetixLocale('de')).resolves.toBe(false);

        expect(warn).toHaveBeenCalledTimes(1);
    });

    it('matches a regional code to its language file and registers both codes', async () => {
        const plugin = await fresh();
        const loader = loaderOver(messages);
        plugin.registerKinetixLocales({ loader });

        await expect(plugin.loadKinetixLocale('es-MX')).resolves.toBe(true);
        await expect(plugin.loadKinetixLocale('es_MX')).resolves.toBe(true);

        const loaded = plugin.kinetixLoadedMessages();
        expect(loaded['es-MX']).toBe(loaded.es);
        expect(loaded.es_MX).toBe(loaded.es);
        expect(loaded.es).toEqual(messages.es);
        // One download for the `es` file, however it was asked for.
        expect(loader.mock.calls.filter(([l]) => l === 'es')).toHaveLength(1);
    });

    it('returns a fresh outer object on every call', async () => {
        const plugin = await fresh();
        plugin.registerKinetixLocales({ loader: loaderOver(messages) });
        await plugin.loadKinetixLocale('es');

        const first = plugin.kinetixLoadedMessages();
        first.fr = {};

        expect(plugin.kinetixLoadedMessages()).not.toHaveProperty('fr');
    });
});

describe('kinetixLocaleLoaderFromGlob', () => {
    it('unwraps the per-locale module, shallow-merges later maps, and ignores index', async () => {
        const plugin = await fresh();
        const index = vi.fn(async () => ({ default: { broken: true } }));
        const loader = plugin.kinetixLocaleLoaderFromGlob(
            {
                './locales/es.ts': async () => ({
                    default: { es: { kinetix: { hello: 'Hola' } } },
                }),
                './locales/index.ts': index,
            },
            {
                '../../lang/es.json': async () => ({
                    default: { 'Log in': 'Iniciar sesión' },
                    'Log in': 'Iniciar sesión',
                }),
            },
        );

        await expect(loader('es')).resolves.toEqual({
            kinetix: { hello: 'Hola' },
            'Log in': 'Iniciar sesión',
        });
        await expect(loader('index')).resolves.toBeUndefined();
        await expect(loader('fr')).resolves.toBeUndefined();
        expect(index).not.toHaveBeenCalled();
    });

    it("accepts real module namespaces and `{ import: 'default' }` globs alike", async () => {
        const plugin = await fresh();
        const namespace = (value: unknown) =>
            Object.freeze(
                Object.defineProperties(Object.create(null), {
                    default: { value, enumerable: true },
                    [Symbol.toStringTag]: { value: 'Module' },
                }),
            );
        const loader = plugin.kinetixLocaleLoaderFromGlob(
            // A real namespace, without `import: 'default'`.
            { './locales/es.ts': async () => namespace({ es: { a: 'A' } }) },
            // `import: 'default'`: the importer resolves to the object itself,
            // even one with a `default` group of its own.
            {
                '../../lang/es.json': async () => ({
                    default: { nested: 'kept' },
                    b: 'B',
                }),
            },
            { './more/es.ts': async () => ({ es: { c: 'C' } }) },
        );

        await expect(loader('es')).resolves.toEqual({
            a: 'A',
            default: { nested: 'kept' },
            b: 'B',
            c: 'C',
        });
    });

    it('lets a later map win on a shared top-level key', async () => {
        const plugin = await fresh();
        const loader = plugin.kinetixLocaleLoaderFromGlob(
            { 'a/en.json': async () => ({ default: { title: 'From JSON' } }) },
            { 'b/en.ts': async () => ({ default: { title: 'From TS' } }) },
        );

        await expect(loader('en')).resolves.toEqual({ title: 'From TS' });
    });
});

describe('kinetixPageLocale', () => {
    it('prefers props.locale, then the shared locale, then <html lang>, then the fallback', async () => {
        const plugin = await fresh();
        plugin.registerKinetixLocales({
            loader: loaderOver(messages),
            fallbackLocale: 'fr',
        });

        expect(
            plugin.kinetixPageLocale({
                props: { locale: 'es', kinetix_locale: { current: 'pt' } },
            }),
        ).toBe('es');
        expect(
            plugin.kinetixPageLocale({
                props: { kinetix_locale: { current: 'pt' } },
            }),
        ).toBe('pt');

        document.documentElement.lang = 'ja';
        expect(plugin.kinetixPageLocale({ props: {} })).toBe('ja');

        document.documentElement.lang = '';
        expect(plugin.kinetixPageLocale()).toBe('fr');
    });
});

describe('withKinetixLocales', () => {
    it('does not hand over the component before the messages are in', async () => {
        const plugin = await fresh();
        let release: () => void = () => {};
        const gate = new Promise<void>((resolve) => {
            release = resolve;
        });
        plugin.registerKinetixLocales({
            loader: async (locale) => {
                await gate;

                return messages[locale as 'en' | 'es'];
            },
        });

        const order: string[] = [];
        const resolve = vi.fn(() => {
            order.push('component requested');

            return { name: 'Page' };
        });

        const pending = plugin
            .withKinetixLocales(resolve)('Page', { props: { locale: 'es' } })
            .then((component) => {
                order.push('component handed over');

                return component;
            });

        // The component is requested at once, in parallel with the messages…
        await Promise.resolve();
        expect(resolve).toHaveBeenCalledTimes(1);
        await new Promise((r) => setTimeout(r, 0));
        expect(order).toEqual(['component requested']);

        // …but handed over only once they are loaded.
        release();
        await expect(pending).resolves.toEqual({ name: 'Page' });
        order.push('messages checked');
        expect(Object.keys(plugin.kinetixLoadedMessages()).sort()).toEqual([
            'en',
            'es',
        ]);
        expect(order).toEqual([
            'component requested',
            'component handed over',
            'messages checked',
        ]);
    });

    it('paints translated text on the first render (no raw keys)', async () => {
        const plugin = await fresh();
        plugin.registerKinetixLocales({ loader: loaderOver(messages) });
        const page = { props: { locale: 'es' } };

        const component = await plugin.withKinetixLocales(() => Greeting)(
            'Greeting',
            page,
        );

        const i18n = createI18n({
            legacy: false,
            locale: plugin.kinetixPageLocale(page),
            fallbackLocale: 'en',
            messages: plugin.kinetixLoadedMessages(),
        });
        plugin.attachKinetixI18n(i18n);

        const wrapper = mount(component, { global: { plugins: [i18n] } });

        expect(wrapper.html()).toContain('Hola');
        expect(wrapper.html()).not.toContain('kinetix.hello');
    });

    it('falls back to the fallback locale when the active one fails', async () => {
        const plugin = await fresh();
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        plugin.registerKinetixLocales({
            loader: async (locale) => {
                if (locale === 'es') {
                    throw new Error('offline');
                }

                return messages[locale as 'en'];
            },
        });
        const page = { props: { locale: 'es' } };

        const component = await plugin.withKinetixLocales(() => Greeting)(
            'Greeting',
            page,
        );
        const i18n = createI18n({
            legacy: false,
            locale: plugin.kinetixPageLocale(page),
            fallbackLocale: 'en',
            fallbackWarn: false,
            missingWarn: false,
            messages: plugin.kinetixLoadedMessages(),
        });

        const wrapper = mount(component, { global: { plugins: [i18n] } });

        expect(wrapper.text()).toBe('Hello');
    });
});

describe('withKinetixLocales — preloadFallback', () => {
    it("'idle' hands the page over on the active locale and loads the fallback after", async () => {
        const plugin = await fresh();
        let releaseFallback: () => void = () => {};
        const fallbackGate = new Promise<void>((resolve) => {
            releaseFallback = resolve;
        });
        const loader = vi.fn(async (locale: string) => {
            if (locale === 'en') {
                await fallbackGate;
            }

            return messages[locale as 'en' | 'es'];
        });
        plugin.registerKinetixLocales({ loader, fallbackLocale: 'en' });
        const page = { props: { locale: 'es' } };

        const component = await plugin.withKinetixLocales(() => Greeting, {
            preloadFallback: 'idle',
        })('Greeting', page);

        // Painted on Spanish alone; English hasn't been asked for yet.
        expect(Object.keys(plugin.kinetixLoadedMessages())).toEqual(['es']);
        const i18n = createI18n({
            legacy: false,
            locale: plugin.kinetixPageLocale(page),
            fallbackLocale: 'en',
            messages: plugin.kinetixLoadedMessages(),
        });
        plugin.attachKinetixI18n(i18n);
        expect(mount(component, { global: { plugins: [i18n] } }).text()).toBe(
            'Hola',
        );

        await vi.waitFor(() => expect(loader).toHaveBeenCalledWith('en'));
        releaseFallback();
        await vi.waitFor(() =>
            expect(i18n.global.getLocaleMessage('en')).toEqual(messages.en),
        );
    });

    it("'idle' still waits for the fallback when the active locale fails", async () => {
        const plugin = await fresh();
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        plugin.registerKinetixLocales({
            loader: async (locale) => {
                if (locale === 'es') {
                    throw new Error('offline');
                }

                return messages.en;
            },
        });

        await plugin.withKinetixLocales(() => Greeting, {
            preloadFallback: 'idle',
        })('Greeting', { props: { locale: 'es' } });

        expect(Object.keys(plugin.kinetixLoadedMessages())).toEqual(['en']);
    });

    it("'block' (the default) loads both before handing over", async () => {
        const plugin = await fresh();
        plugin.registerKinetixLocales({ loader: loaderOver(messages) });

        await plugin.withKinetixLocales(() => Greeting)('Greeting', {
            props: { locale: 'es' },
        });

        expect(Object.keys(plugin.kinetixLoadedMessages()).sort()).toEqual([
            'en',
            'es',
        ]);
    });
});

describe('attachKinetixI18n / ensureKinetixLocale', () => {
    it('registers later loads on the attached instance', async () => {
        const plugin = await fresh();
        plugin.registerKinetixLocales({ loader: loaderOver(messages) });
        await plugin.loadKinetixLocale('en');

        const i18n = createI18n({
            legacy: false,
            locale: 'en',
            messages: plugin.kinetixLoadedMessages(),
        });
        plugin.attachKinetixI18n(i18n);

        await expect(plugin.ensureKinetixLocale('es')).resolves.toBe(true);

        expect(i18n.global.getLocaleMessage('es')).toEqual(messages.es);
    });

    it('registers on a composer the host never attached', async () => {
        const plugin = await fresh();
        plugin.registerKinetixLocales({ loader: loaderOver(messages) });
        const setLocaleMessage = vi.fn();

        await expect(
            plugin.ensureKinetixLocale('es-MX', { setLocaleMessage }),
        ).resolves.toBe(true);

        expect(setLocaleMessage).toHaveBeenCalledWith('es-MX', messages.es);
        expect(setLocaleMessage).toHaveBeenCalledWith('es', messages.es);
        expect(setLocaleMessage).toHaveBeenCalledWith('en', messages.en);
    });

    it('resolves false when the messages cannot be loaded', async () => {
        const plugin = await fresh();
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        plugin.registerKinetixLocales({
            loader: async (locale) => {
                if (locale === 'es') {
                    throw new Error('offline');
                }

                return messages.en;
            },
        });

        await expect(plugin.ensureKinetixLocale('es')).resolves.toBe(false);
    });
});
