import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    importChain,
    JS_ROOT,
    staticSpecifiersOf,
} from './support/staticImportGraph';

/**
 * Bundle guard for realtime: `@laravel/echo-vue` (and pusher-js behind it)
 * only downloads when a page actually goes realtime. Every Kinetix caller
 * reaches it through `composables/kinetixEcho.ts`, the one module that
 * imports it — dynamically. A static value import anywhere else would put it
 * in that page's chunk for every host, broadcasting or not.
 *
 * Type-only imports (`import type …`) are erased at build time and allowed.
 */
const ECHO = '@laravel/echo-vue';
const LOADER = path.join(JS_ROOT, 'composables', 'kinetixEcho.ts');

const sources = (dir: string): string[] =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            return sources(full);
        }

        return /\.(ts|vue)$/.test(entry.name) ? [full] : [];
    });

const files = sources(JS_ROOT);
const relative = (file: string): string => path.relative(JS_ROOT, file);

const isEcho = (module: string): boolean =>
    !path.isAbsolute(module) &&
    (module === ECHO || module.startsWith(`${ECHO}/`));

describe('echo-vue bundle guard', () => {
    it('no module under resources/js imports it statically (as a value)', () => {
        const offenders = files
            .filter((file) => staticSpecifiersOf(file).some(isEcho))
            .map(relative);

        expect(offenders).toEqual([]);
    });

    it('kinetixEcho.ts is the only module that imports it at all', () => {
        const dynamic = /import\(\s*['"]@laravel\/echo-vue['"]\s*\)/;
        const importers = files
            .filter((file) => dynamic.test(fs.readFileSync(file, 'utf8')))
            .map(relative);

        expect(importers).toEqual([relative(LOADER)]);
    });

    it.each([
        'components/KinetixInbox.vue',
        'components/KinetixChannelManager.vue',
        'composables/useKinetixInbox.ts',
        'composables/useKinetixChannels.ts',
    ])('%s never statically reaches it', (from) => {
        const chain = importChain(path.join(JS_ROOT, from), isEcho);

        expect(
            chain,
            `Static import chain:\n  ${chain?.join('\n  -> ')}`,
        ).toBeNull();
    });
});
