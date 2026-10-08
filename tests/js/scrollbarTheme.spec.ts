import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Chrome and Firefox paint a scrollbar from the standard `scrollbar-color` and
 * then ignore every `::-webkit-scrollbar` rule. A literal colour there stays the
 * same in light and dark mode (the notification list kept a light grey thumb on
 * a dark panel), so it must come from a theme token.
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

describe('themed scrollbars', () => {
    it('every scrollbar-color reads its thumb from a theme token', () => {
        const offenders = vueFiles(componentsDir).flatMap((file) =>
            [
                ...fs
                    .readFileSync(file, 'utf8')
                    .matchAll(/scrollbar-color:\s*([^;]+);/g),
            ]
                .map((match) => match[1].replace(/\s+/g, ' ').trim())
                // A var() with a literal fallback isn't enough: the chain has
                // to reach a theme token (`--color-*`) before any literal.
                .filter(
                    (value) =>
                        !/^var\(--[\w-]+,\s*var\(--color-/.test(value) &&
                        !value.startsWith('var(--color-'),
                )
                .map((value) => `${path.basename(file)}: ${value}`),
        );

        expect(offenders).toEqual([]);
    });
});
