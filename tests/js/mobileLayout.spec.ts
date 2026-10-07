import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Layout rules that keep every component inside a phone's width. Each one is
 * a bug that shipped: a component a few pixels too wide pushes the whole page
 * sideways. `npm run audit:mobile` measures the rendered gallery; these scans
 * catch the patterns before anything renders.
 */
const componentsDir = path.resolve(__dirname, '../../resources/js/components');

const vueFiles = (dir: string): string[] =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            return vueFiles(full);
        }

        return entry.name.endsWith('.vue') ? [full] : [];
    });

const files = vueFiles(componentsDir).map((file) => ({
    name: path.relative(componentsDir, file),
    source: fs.readFileSync(file, 'utf8'),
}));

const staticClasses = (source: string): string[][] =>
    [...source.matchAll(/\bclass="([^"]*)"/g)].map((match) =>
        match[1].split(/\s+/),
    );

describe('mobile layout (published components)', () => {
    it('finds the components directory', () => {
        expect(files.length).toBeGreaterThan(50);
    });

    // `grid md:grid-cols-2` leaves phones an implicit `auto` column, which
    // grows to its content's min-content width instead of fitting the screen.
    // `grid-cols-1` is `minmax(0, 1fr)`: the column takes the container's.
    it('every responsive grid declares its base column', () => {
        const offenders = files.flatMap(({ name, source }) =>
            staticClasses(source)
                .filter(
                    (classes) =>
                        classes.includes('grid') &&
                        classes.some((c) =>
                            /^(sm|md|lg|xl|2xl):grid-cols-/.test(c),
                        ) &&
                        !classes.some((c) => c.startsWith('grid-cols-')),
                )
                .map((classes) => `${name}: ${classes.join(' ')}`),
        );

        expect(offenders).toEqual([]);
    });

    // The shared strip scrolls sideways past its container; a hand-written
    // one forgets to, and a long set of tabs widens the page.
    it('every tab strip uses tabsListClass', () => {
        const offenders = files
            .filter(
                ({ source }) =>
                    /<TabsList\b/.test(source) &&
                    !/<TabsList\s+:class="tabsListClass"/.test(source),
            )
            .map(({ name }) => name);

        expect(offenders).toEqual([]);
    });
});
