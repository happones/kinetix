import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    importChain,
    JS_ROOT,
    REPO_ROOT,
    staticClosure,
} from './support/staticImportGraph';

/**
 * Bundle guard for the layout hosts: the components a host app mounts once in
 * its layout ship on EVERY page, so each stays a light shell that downloads
 * its heavy body on demand (first use, or once the page enables the feature),
 * and realtime code only arrives when realtime is on.
 *
 * A failure prints the import chain that pulled the module in: break it by
 * moving the import behind the host's lazy entry (or `kinetixEcho.ts`), not
 * by deleting this rule.
 */

const component = (name: string): string =>
    path.join(JS_ROOT, 'components', name);
const composable = (name: string): string =>
    path.join(JS_ROOT, 'composables', name);

const file = (absolute: string) => (module: string) => module === absolute;
const pkg = (name: string) => (module: string) =>
    !path.isAbsolute(module) &&
    (module === name || module.startsWith(`${name}/`));

const ECHO = '@laravel/echo-vue';

const GUARDS: Array<{ from: string; label: string; forbidden: string }> = [
    {
        from: component('KinetixImportModal.vue'),
        label: 'KinetixImporter.vue',
        forbidden: component('KinetixImporter.vue'),
    },
    {
        from: component('KinetixImportModal.vue'),
        label: 'useKinetixImporter.ts',
        forbidden: composable('useKinetixImporter.ts'),
    },
    {
        from: component('KinetixFilePreview.vue'),
        label: 'KinetixLightbox.vue',
        forbidden: component('KinetixLightbox.vue'),
    },
    // The header dropdown is the default; the select field loads where used.
    {
        from: component('KinetixLanguageSwitcher.vue'),
        label: 'LanguageSwitcher/LanguageSelect.vue',
        forbidden: component('LanguageSwitcher/LanguageSelect.vue'),
    },
    ...[
        ['KinetixSpotlight.vue', 'Spotlight/SpotlightBody.vue'],
        ['KinetixShortcuts.vue', 'Shortcuts/ShortcutsBody.vue'],
        ['KinetixTours.vue', 'Tours/ToursBody.vue'],
        [
            'KinetixAnnouncementBanner.vue',
            'Announcements/AnnouncementBannerBody.vue',
        ],
        [
            'KinetixConfidentialUnlock.vue',
            'Confidential/ConfidentialUnlockBody.vue',
        ],
        ['KinetixCookieConsent.vue', 'CookieConsent/CookieConsentBody.vue'],
    ].map(([shell, body]) => ({
        from: component(shell),
        label: body,
        forbidden: component(body),
    })),
];

/**
 * The layout hosts docs/installation.md tells a host to mount: every
 * `<KinetixX` tag in it that names a top-level component.
 */
const documentedHosts = (): string[] => {
    const doc = fs.readFileSync(
        path.join(REPO_ROOT, 'docs/installation.md'),
        'utf8',
    );
    const names = new Set(
        [...doc.matchAll(/<(Kinetix[A-Za-z]+)/g)].map((match) => match[1]),
    );

    return [...names]
        .map((name) => component(`${name}.vue`))
        .filter((candidate) => fs.existsSync(candidate))
        .sort();
};

describe('bundle guard: layout hosts', () => {
    it('resolves the import graph of the hosts', () => {
        // Sanity: the graph really walks, so an empty closure can't pass.
        const closure = staticClosure(component('KinetixImportModal.vue'));

        expect(closure.has(component('primitives/KinetixModal.vue'))).toBe(
            true,
        );
        expect(closure.has('vue')).toBe(true);
    });

    it.each(GUARDS)(
        '$from never statically reaches $label',
        ({ from, forbidden }) => {
            expect(fs.existsSync(forbidden)).toBe(true);

            const chain = importChain(from, file(forbidden));

            expect(
                chain,
                `Static import chain:\n  ${chain?.join('\n  -> ')}`,
            ).toBeNull();
        },
    );

    it.each([
        component('KinetixNotifications.vue'),
        composable('useKinetixPresence.ts'),
        component('KinetixOnlineUsers.vue'),
    ])(`%s never statically reaches ${ECHO}`, (from) => {
        const chain = importChain(from, pkg(ECHO));

        expect(
            chain,
            `Static import chain:\n  ${chain?.join('\n  -> ')}`,
        ).toBeNull();
    });

    it(`keeps ${ECHO} out of every layout host the install guide lists`, () => {
        const hosts = documentedHosts();

        // The guide's core hosts are in the list (so a reworded guide can't
        // silently empty it).
        expect(hosts).toEqual(
            expect.arrayContaining([
                component('KinetixNotifications.vue'),
                component('KinetixImportModal.vue'),
                component('KinetixFilePreview.vue'),
                component('KinetixSpotlight.vue'),
            ]),
        );

        const offenders = hosts
            .map((host) => importChain(host, pkg(ECHO)))
            .filter((chain): chain is string[] => chain !== null)
            .map((chain) => chain.join(' -> '));

        expect(offenders).toEqual([]);
    });
});
