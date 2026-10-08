import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The gallery swaps three modules for stubs (vite.gallery.config.ts). A stub
 * missing a name that some component imports breaks the WHOLE gallery — ES
 * module linking fails before anything renders — and `npm run audit:mobile`
 * with it: every specimen just times out. That went unnoticed from v0.200.0
 * (`isKinetixAbort`) to v0.207.0.
 */
const root = path.resolve(__dirname, '../..');

const STUBS: Record<string, string> = {
    '@/composables/useKinetixHttp': 'gallery/stubs/http.ts',
    '@inertiajs/vue3': 'gallery/stubs/inertia.ts',
    '@laravel/echo-vue': 'gallery/stubs/echo.ts',
};

const sources = (dir: string): string[] =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            return entry.name === 'stubs' ? [] : sources(full);
        }

        return /\.(ts|vue)$/.test(entry.name) ? [full] : [];
    });

const importers = [
    ...sources(path.join(root, 'resources/js')),
    ...sources(path.join(root, 'gallery')),
].map((file) => fs.readFileSync(file, 'utf8'));

/** Value names imported from a module (type-only imports are erased). */
const importedNames = (module: string): string[] => {
    const escaped = module.replace(/[.*+?^${}()|[\]\\/@]/g, '\\$&');
    const pattern = new RegExp(
        `import\\s+(type\\s+)?\\{([^}]*)\\}\\s+from\\s+['"]${escaped}['"]`,
        'g',
    );

    return [
        ...new Set(
            importers.flatMap((source) =>
                [...source.matchAll(pattern)]
                    .filter((match) => !match[1])
                    .flatMap((match) =>
                        match[2]
                            .split(',')
                            .map((name) => name.trim())
                            .filter((name) => name && !name.startsWith('type '))
                            .map((name) => name.split(/\s+as\s+/)[0]),
                    ),
            ),
        ),
    ];
};

const exportedNames = (file: string): string[] =>
    [
        ...fs
            .readFileSync(path.join(root, file), 'utf8')
            .matchAll(
                /export\s+(?:async\s+)?(?:function|const|let|class)\s+(\w+)/g,
            ),
    ].map((match) => match[1]);

describe('gallery stubs', () => {
    it.each(Object.entries(STUBS))(
        '%s stub exports every name the components import',
        (module, stub) => {
            const exported = exportedNames(stub);
            const imported = importedNames(module);

            expect(imported.length).toBeGreaterThan(0);
            expect(imported.filter((name) => !exported.includes(name))).toEqual(
                [],
            );
        },
    );
});
