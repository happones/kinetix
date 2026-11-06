// @vitest-environment node
import { createSSRApp, defineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { createI18n, useI18n } from 'vue-i18n';

/**
 * The plugin on the server: no window/document, one module-level cache shared
 * by every request, and an i18n instance built per request from it.
 */

function deepFreeze<T>(value: T): T {
    if (typeof value === 'object' && value !== null) {
        Object.values(value).forEach(deepFreeze);
        Object.freeze(value);
    }

    return value;
}

const messages = deepFreeze({
    en: {
        kinetix: {
            hello: 'Hello {name}',
            items: 'no items | one item | {n} items',
        },
    },
    es: {
        kinetix: {
            hello: 'Hola {name}',
            items: 'sin elementos | un elemento | {n} elementos',
        },
    },
    fr: { kinetix: { hello: 'Bonjour {name}', items: 'aucun | un | {n}' } },
});

const Greeting = defineComponent({
    setup() {
        const { t } = useI18n();

        return () =>
            h('p', [
                t('kinetix.hello', { name: 'Ada' }),
                ' · ',
                t('kinetix.items', 3),
            ]);
    },
});

async function plugin() {
    vi.resetModules();

    return import('@/plugins/kinetixLocales');
}

describe('kinetixLocales on the server', () => {
    it('imports without a window or document', async () => {
        expect(typeof window).toBe('undefined');
        expect(typeof document).toBe('undefined');

        const locales = await plugin();

        expect(locales.kinetixPageLocale({ props: {} })).toBe('en');
    });

    it('treats attachKinetixI18n as a no-op', async () => {
        const locales = await plugin();
        const setLocaleMessage = vi.fn();
        locales.registerKinetixLocales({
            loader: async (locale) => messages[locale as 'en'],
        });

        locales.attachKinetixI18n({ global: { setLocaleMessage } });
        await locales.loadKinetixLocale('es');

        expect(setLocaleMessage).not.toHaveBeenCalled();
    });

    it('renders each concurrent request in its own locale from frozen shared trees', async () => {
        const locales = await plugin();
        locales.registerKinetixLocales({
            loader: async (locale) => {
                // Out of order on purpose: the later request loads first.
                await new Promise((r) =>
                    setTimeout(r, locale === 'es' ? 10 : 0),
                );

                return messages[locale as 'en' | 'es' | 'fr'];
            },
        });

        // What @inertiajs/vue3's SSR path does per request: await resolve,
        // then build the app (withApp) and render it.
        const renderRequest = async (locale: string): Promise<string> => {
            const page = { component: 'Greeting', props: { locale } };
            const component = await locales.withKinetixLocales(() => Greeting)(
                page.component,
                page,
            );
            const app = createSSRApp({ render: () => h(component) });
            const i18n = createI18n({
                legacy: false,
                locale: locales.kinetixPageLocale(page),
                fallbackLocale: 'en',
                messages: locales.kinetixLoadedMessages(),
            });

            locales.attachKinetixI18n(i18n);
            app.use(i18n);

            return renderToString(app);
        };

        const [es, fr, en] = await Promise.all([
            renderRequest('es'),
            renderRequest('fr'),
            renderRequest('en'),
        ]);

        expect(es).toBe('<p>Hola Ada · 3 elementos</p>');
        expect(fr).toBe('<p>Bonjour Ada · 3</p>');
        expect(en).toBe('<p>Hello Ada · 3 items</p>');

        // A second round reuses the cache and still doesn't leak.
        await expect(renderRequest('fr')).resolves.toBe(
            '<p>Bonjour Ada · 3</p>',
        );
        await expect(renderRequest('es')).resolves.toBe(
            '<p>Hola Ada · 3 elementos</p>',
        );
        expect(Object.isFrozen(messages.es.kinetix)).toBe(true);
    });
});
