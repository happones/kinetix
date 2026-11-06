/**
 * On-demand translation messages for vue-i18n.
 *
 * By default a host imports every locale's messages into its entry bundle
 * (eager). Registering a loader here switches to lazy mode: the first paint
 * waits for the active locale (plus the fallback) and the other locales
 * download only when the user switches to them.
 *
 *   registerKinetixLocales({
 *       loader: kinetixLocaleLoaderFromGlob(
 *           import.meta.glob(['./locales/*.ts', '!./locales/index.ts']),
 *       ),
 *       fallbackLocale: 'en',
 *   });
 *
 *   createInertiaApp({
 *       resolve: withKinetixLocales((name) => …),
 *       withApp(app, { page }) {
 *           const i18n = createI18n({
 *               legacy: false,
 *               locale: kinetixPageLocale(page),
 *               fallbackLocale: 'en',
 *               messages: kinetixLoadedMessages(),
 *           });
 *
 *           attachKinetixI18n(i18n);
 *           app.use(i18n);
 *       },
 *   });
 *
 * Without registerKinetixLocales() every export is a no-op, so eager wiring
 * keeps working unchanged. Every entry (SSR, a separate guest entry) needs the
 * same wiring: one that still imports the messages eagerly bundles them all.
 *
 * SSR: nothing here touches window/document at import. The module-level cache
 * holds only the loaded message trees, which are never mutated; build the i18n
 * instance per request (inside withApp) from kinetixLoadedMessages().
 */

import type { LocaleMessageValue, VueMessageType } from 'vue-i18n';

/** One locale's message tree, typed as vue-i18n's `createI18n({ messages })` expects. */
export type KinetixMessages = Record<
    string,
    LocaleMessageValue<VueMessageType>
>;

/** Resolves one locale's messages; null/undefined when there is no file for it. */
export type KinetixLocaleLoader = (
    locale: string,
) => Promise<KinetixMessages | Record<string, unknown> | null | undefined>;

export interface KinetixLocalesOptions {
    loader: KinetixLocaleLoader;
    /** Always loaded alongside the active locale; default 'en'. */
    fallbackLocale?: string;
}

export interface KinetixLocalesResolveOptions {
    /**
     * When the fallback locale loads. `'block'` (default): before the first
     * paint, with the active locale. `'idle'`: after the first paint, so only
     * the active locale blocks; use it only when the active locale is
     * complete (a missing key shows raw until the fallback arrives). A failed
     * active locale still waits for the fallback. The server always blocks.
     */
    preloadFallback?: 'block' | 'idle';
}

/** The part of a vue-i18n composer this module writes to. */
interface KinetixMessageTarget {
    setLocaleMessage(locale: string, messages: any): void;
}

const DEFAULT_FALLBACK = 'en';

let loader: KinetixLocaleLoader | null = null;
let fallbackLocale = DEFAULT_FALLBACK;

/** Every registered code (requested and matched file code) → its messages. */
let loaded = new Map<string, KinetixMessages>();
/** Loader calls by file code; a failed download is dropped so a later call retries. */
let files = new Map<string, Promise<KinetixMessages | null>>();
/** Loads by requested code. Settled true (loaded) or false (no file matches); a failure is dropped. */
let requests = new Map<string, Promise<boolean>>();
/** The client app's composer, set by attachKinetixI18n(). */
let attached: KinetixMessageTarget | null = null;

const isServer = (): boolean => typeof window === 'undefined';

/** Host-registered loader (like registerKinetixTiptap). Without it every export below is a no-op: eager mode. */
export function registerKinetixLocales(options: KinetixLocalesOptions): void {
    loader = options.loader;
    fallbackLocale = options.fallbackLocale || DEFAULT_FALLBACK;
    loaded = new Map();
    files = new Map();
    requests = new Map();
}

export function kinetixLocalesEnabled(): boolean {
    return loader !== null;
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return (
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value) &&
        (Object.getPrototypeOf(value) === Object.prototype ||
            Object.getPrototypeOf(value) === null)
    );
}

function globBasename(path: string): string {
    const file = path.split('/').pop() ?? path;
    const dot = file.lastIndexOf('.');

    return dot > 0 ? file.slice(0, dot) : file;
}

/**
 * A module namespace (what `import.meta.glob` yields without `import:
 * 'default'`): a real one is tagged `Module`; a hand-built one carries only
 * `default`, plus named exports that mirror its keys (a JSON module).
 */
function isNamespace(value: unknown): value is { default: unknown } {
    if (typeof value !== 'object' || value === null || !('default' in value)) {
        return false;
    }

    if ((value as Record<symbol, unknown>)[Symbol.toStringTag] === 'Module') {
        return true;
    }

    const exported = (value as { default: unknown }).default;

    return (
        isPlainObject(exported) &&
        Object.keys(value).every(
            (key) =>
                key === 'default' ||
                (value as Record<string, unknown>)[key] === exported[key],
        )
    );
}

/** A module's messages: its default export (or the object itself), with a `{ <locale>: {...} }` wrapper removed. */
function unwrapModule(module: unknown, locale: string): KinetixMessages {
    const value = isNamespace(module) ? module.default : module;

    if (!isPlainObject(value)) {
        return {};
    }

    const keys = Object.keys(value);

    if (
        keys.length === 1 &&
        keys[0] === locale &&
        isPlainObject(value[locale])
    ) {
        return value[locale] as KinetixMessages;
    }

    return value as KinetixMessages;
}

/** Loader over import.meta.glob maps (one or more, merged shallowly per locale in argument order).
 *  Keys are matched by basename (`en.ts`, `en.json`); `index.*` is ignored. A module's default export
 *  `{ en: {...} }` (vue-i18n:generate --multi-locales) is unwrapped to `{...}`. Globs with or without
 *  `{ import: 'default' }` both work. */
export function kinetixLocaleLoaderFromGlob(
    ...maps: Record<string, () => Promise<unknown>>[]
): KinetixLocaleLoader {
    const importers = new Map<string, Array<() => Promise<unknown>>>();

    for (const map of maps) {
        for (const [path, importer] of Object.entries(map)) {
            const locale = globBasename(path);

            if (locale === 'index') {
                continue;
            }

            const list = importers.get(locale) ?? [];
            list.push(importer);
            importers.set(locale, list);
        }
    }

    return async (locale) => {
        const list = importers.get(locale);

        if (list === undefined) {
            return undefined;
        }

        const modules = await Promise.all(list.map((load) => load()));

        return Object.assign(
            {},
            ...modules.map((module) => unwrapModule(module, locale)),
        ) as KinetixMessages;
    };
}

function nonEmptyString(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

/** The page's locale: props.locale ?? props.kinetix_locale.current ?? <html lang> (client) ?? fallback. */
export function kinetixPageLocale(page?: { props?: object } | null): string {
    const props = (page?.props ?? {}) as Record<string, unknown>;
    const shared = props.kinetix_locale as { current?: unknown } | undefined;

    return (
        nonEmptyString(props.locale) ??
        nonEmptyString(shared?.current) ??
        (typeof document !== 'undefined'
            ? nonEmptyString(document.documentElement?.lang)
            : null) ??
        fallbackLocale
    );
}

/** The file codes to try for a locale: exact, the other separator (`pt-BR` ↔ `pt_BR`), then the base language. */
function candidates(locale: string): string[] {
    const swapped = locale.includes('-')
        ? locale.replace(/-/g, '_')
        : locale.replace(/_/g, '-');
    const base = locale.split(/[-_]/)[0];

    return [...new Set([locale, swapped, base])].filter((code) => code !== '');
}

function register(codes: string[], messages: KinetixMessages): void {
    for (const code of codes) {
        if (loaded.get(code) === messages) {
            continue;
        }

        loaded.set(code, messages);
        attached?.setLocaleMessage(code, messages);
    }
}

function warn(locale: string, error?: unknown): void {
    console.warn(
        `[kinetix] Could not load the "${locale}" translation messages; the fallback locale is shown instead.`,
        ...(error === undefined ? [] : [error]),
    );
}

/** One loader call per file code, shared by every locale that maps to it. */
function loadFile(code: string): Promise<KinetixMessages | null> {
    const existing = files.get(code);

    if (existing !== undefined) {
        return existing;
    }

    const promise = (async () =>
        ((await loader!(code)) as KinetixMessages | null | undefined) ??
        null)();

    files.set(code, promise);
    promise.catch(() => {
        if (files.get(code) === promise) {
            files.delete(code);
        }
    });

    return promise;
}

/** One requested locale: never rejects, warns once per attempt that fails. */
function loadLocale(locale: string): Promise<boolean> {
    const existing = requests.get(locale);

    if (existing !== undefined) {
        return existing;
    }

    let failed = false;
    const promise = (async () => {
        let error: unknown;

        for (const code of candidates(locale)) {
            try {
                const messages = await loadFile(code);

                if (messages) {
                    register([locale, code], messages);

                    return true;
                }
            } catch (reason) {
                // A loader that rejects for an unknown file still gets the
                // next candidate (`es-MX` → `es`).
                failed = true;
                error ??= reason;
            }
        }

        // No file loaded. A missing locale settles for good (one warning);
        // a failed download is retried by the next call.
        warn(locale, failed ? error : undefined);

        return false;
    })();

    requests.set(locale, promise);
    // Registered before any caller awaits, so a retry made right after a
    // failure already starts a fresh load.
    void promise.then(() => {
        if (failed && requests.get(locale) === promise) {
            requests.delete(locale);
        }
    });

    return promise;
}

/** Load (memoized per locale; concurrent calls share one promise; a failure clears it so a later call retries)
 *  the locale and the fallback. Never rejects: resolves false when the locale could not be loaded. */
export async function loadKinetixLocale(locale: string): Promise<boolean> {
    if (loader === null) {
        return true;
    }

    const [ready] = await Promise.all([
        loadLocale(locale),
        locale === fallbackLocale ? true : loadLocale(fallbackLocale),
    ]);

    return ready;
}

/** A fresh object of every loaded locale's messages, for createI18n({ messages }). Safe to call per SSR request. */
export function kinetixLoadedMessages(): Record<string, KinetixMessages> {
    return Object.fromEntries(loaded);
}

/** Run after the first paint, when the browser is idle. */
function whenIdle(task: () => void): void {
    if (typeof requestIdleCallback === 'function') {
        requestIdleCallback(task, { timeout: 2000 });

        return;
    }

    setTimeout(task, 1);
}

/** The active locale, with the fallback either awaited or deferred to idle time. */
async function loadForPage(
    locale: string,
    preloadFallback: 'block' | 'idle',
): Promise<boolean> {
    if (
        loader === null ||
        preloadFallback === 'block' ||
        isServer() ||
        locale === fallbackLocale
    ) {
        return loadKinetixLocale(locale);
    }

    if (!(await loadLocale(locale))) {
        // Nothing to show without the fallback: wait for it after all.
        await loadLocale(fallbackLocale);

        return false;
    }

    whenIdle(() => void loadLocale(fallbackLocale));

    return true;
}

/** Wrap Inertia's resolve: awaits loadKinetixLocale(kinetixPageLocale(page)) in parallel with the component. */
export function withKinetixLocales<T>(
    resolve: (name: string, page?: any) => T | Promise<T>,
    options: KinetixLocalesResolveOptions = {},
): (name: string, page?: any) => Promise<T> {
    const preloadFallback = options.preloadFallback ?? 'block';

    return async (name, page) => {
        const [component] = await Promise.all([
            resolve(name, page),
            loadForPage(kinetixPageLocale(page), preloadFallback),
        ]);

        return component;
    };
}

/** Client only (no-op on the server): later loads are setLocaleMessage'd onto this instance. */
export function attachKinetixI18n(i18n: {
    global: { setLocaleMessage(locale: string, messages: any): void };
}): void {
    if (isServer()) {
        return;
    }

    attached = i18n.global;
}

/** Load + register on the attached/passed composer; false on failure. Used by useKinetixLocale before flipping. */
export async function ensureKinetixLocale(
    locale: string,
    target?: KinetixMessageTarget,
): Promise<boolean> {
    if (loader === null) {
        return true;
    }

    const ready = await loadKinetixLocale(locale);

    if (!ready) {
        return false;
    }

    // Loads register on the attached composer as they land; a composer the
    // host never attached gets the messages here, before the locale flips.
    if (target !== undefined && target !== attached) {
        const codes = new Set([
            ...candidates(locale),
            ...candidates(fallbackLocale),
        ]);

        for (const code of codes) {
            const messages = loaded.get(code);

            if (messages !== undefined) {
                target.setLocaleMessage(code, messages);
            }
        }
    }

    return true;
}
