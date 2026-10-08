import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Intra-package import convention. Kinetix components are PUBLISHED into the
 * host app, often under a subdirectory (`@/components/kinetix/`). An
 * intra-package import of a sibling component through the `@/components/` alias
 * then resolves to a path that doesn't exist there and the host's build fails
 * (the FilterFormField.vue regression — a `@/components/KinetixFormSchema.vue`
 * import that only worked because the package itself resolves `@` to its own
 * `resources/js`). Relative imports are immune to where the app publishes the
 * files, so they are the rule — this scan keeps it that way.
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

describe('intra-package imports (published components)', () => {
    it('finds the components directory', () => {
        expect(files.length).toBeGreaterThan(50);
    });

    it('never imports a sibling component through the @/components/ alias', () => {
        const offenders = files
            .filter(({ source }) => /\bfrom\s+['"]@\/components\//.test(source))
            .map(({ name }) => name);

        expect(
            offenders,
            `These components import a sibling via @/components/, which breaks the ` +
                `host build when components are published to a subdirectory. Use a ` +
                `relative import instead:\n  ${offenders.join('\n  ')}`,
        ).toEqual([]);
    });
});
