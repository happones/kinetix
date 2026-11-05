import fs from 'node:fs';
import path from 'node:path';

/**
 * The static import graph of the published frontend, for bundle-guard specs:
 * what a component pulls into its chunk no matter what the page renders.
 *
 * Counted as edges:
 *  - value imports (`import X from`, `import { a } from`, `import * as`),
 *    unless every specifier is a `type` one;
 *  - side-effect imports (`import './x.css'`);
 *  - re-exports (`export { a } from`, `export * from`), unless type-only.
 *
 * Ignored: `import type`, `export type … from` and dynamic `import()` — those
 * never put the target in the importer's chunk.
 *
 * Relative specifiers and `@/` (resources/js) resolve to files, trying the
 * path as written, then `.ts`, `.vue` and `/index.ts`. Bare specifiers
 * (`@tanstack/vue-virtual`, `vue`) are leaves, kept as written. Only `.vue` and
 * `.ts` files are parsed; anything else (a `.css`) is a leaf.
 *
 * Statements are matched at the start of a line, so imports quoted inside
 * doc comments never count.
 */

export const REPO_ROOT = path.resolve(__dirname, '../../..');
export const JS_ROOT = path.join(REPO_ROOT, 'resources/js');

const IMPORT_FROM =
    /^[ \t]*import\s+(?!\()([^'";]*?)\s*from\s*['"]([^'"]+)['"]/gm;
const IMPORT_BARE = /^[ \t]*import\s*['"]([^'"]+)['"]/gm;
const EXPORT_FROM = /^[ \t]*export\s+([^'";]*?)\s*from\s*['"]([^'"]+)['"]/gm;
const SCRIPT_BLOCK = /<script\b[^>]*>([\s\S]*?)<\/script>/g;

const PARSED_EXTENSIONS = ['.ts', '.vue'];
const INFERRED_SUFFIXES = ['', '.ts', '.vue', '/index.ts'];

/** Whether an import/export clause carries only types. */
const isTypeOnly = (clause: string): boolean => {
    const trimmed = clause.trim();

    if (/^type\s+\S/.test(trimmed)) {
        return true;
    }

    const braces = /^\{([\s\S]*)\}$/.exec(trimmed);

    if (!braces) {
        return false;
    }

    const specifiers = braces[1]
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean);

    return (
        specifiers.length > 0 &&
        specifiers.every((part) => /^type\s+\S/.test(part))
    );
};

const sourceOf = (file: string): string => {
    const raw = fs.readFileSync(file, 'utf8');

    if (!file.endsWith('.vue')) {
        return raw;
    }

    return [...raw.matchAll(SCRIPT_BLOCK)].map((m) => m[1]).join('\n');
};

/** The specifiers a file statically depends on, as written. */
export function staticSpecifiersOf(file: string): string[] {
    const source = sourceOf(file);
    const specifiers: string[] = [];

    for (const [, clause, specifier] of source.matchAll(IMPORT_FROM)) {
        if (!isTypeOnly(clause)) {
            specifiers.push(specifier);
        }
    }

    for (const [, specifier] of source.matchAll(IMPORT_BARE)) {
        specifiers.push(specifier);
    }

    for (const [, clause, specifier] of source.matchAll(EXPORT_FROM)) {
        if (!isTypeOnly(clause)) {
            specifiers.push(specifier);
        }
    }

    return specifiers;
}

const isFile = (candidate: string): boolean => {
    try {
        return fs.statSync(candidate).isFile();
    } catch {
        return false;
    }
};

/**
 * Resolve a specifier from an importing file: an absolute path for local
 * files (unresolvable ones kept as their absolute path), the specifier itself
 * for packages.
 */
export function resolveSpecifier(from: string, specifier: string): string {
    let base: string;

    if (specifier.startsWith('@/')) {
        base = path.join(JS_ROOT, specifier.slice(2));
    } else if (specifier.startsWith('./') || specifier.startsWith('../')) {
        base = path.resolve(path.dirname(from), specifier);
    } else {
        return specifier;
    }

    for (const suffix of INFERRED_SUFFIXES) {
        if (isFile(base + suffix)) {
            return base + suffix;
        }
    }

    return base;
}

const edgeCache = new Map<string, string[]>();

const edgesOf = (file: string): string[] => {
    let edges = edgeCache.get(file);

    if (!edges) {
        edges = PARSED_EXTENSIONS.some((ext) => file.endsWith(ext))
            ? staticSpecifiersOf(file).map((s) => resolveSpecifier(file, s))
            : [];
        edgeCache.set(file, edges);
    }

    return edges;
};

/** An absolute path for a file given absolute, `@/`-prefixed or repo-relative. */
export function toAbsolute(file: string): string {
    if (file.startsWith('@/')) {
        return path.join(JS_ROOT, file.slice(2));
    }

    return path.isAbsolute(file) ? file : path.join(REPO_ROOT, file);
}

/**
 * Every module a file reaches through static imports, the file itself
 * included: absolute paths for local files, bare specifiers for packages.
 */
export function staticClosure(file: string): Set<string> {
    const start = toAbsolute(file);
    const seen = new Set<string>([start]);
    const queue = [start];

    while (queue.length > 0) {
        const current = queue.shift() as string;

        if (!path.isAbsolute(current) || !isFile(current)) {
            continue;
        }

        for (const next of edgesOf(current)) {
            if (!seen.has(next)) {
                seen.add(next);
                queue.push(next);
            }
        }
    }

    return seen;
}

/**
 * The shortest static import chain from a file to the first module matching
 * `target`, repo-relative, or null when nothing matches. For failure messages.
 */
export function importChain(
    file: string,
    target: (module: string) => boolean,
): string[] | null {
    const start = toAbsolute(file);
    const parent = new Map<string, string | null>([[start, null]]);
    const queue = [start];

    while (queue.length > 0) {
        const current = queue.shift() as string;

        if (target(current) && current !== start) {
            const chain: string[] = [];

            for (
                let node: string | null = current;
                node !== null;
                node = parent.get(node) ?? null
            ) {
                chain.unshift(
                    path.isAbsolute(node)
                        ? path.relative(REPO_ROOT, node)
                        : node,
                );
            }

            return chain;
        }

        if (!path.isAbsolute(current) || !isFile(current)) {
            continue;
        }

        for (const next of edgesOf(current)) {
            if (!parent.has(next)) {
                parent.set(next, current);
                queue.push(next);
            }
        }
    }

    return null;
}
