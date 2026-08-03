/**
 * Registration seam for the OPTIONAL Tiptap editor engine.
 *
 * The published components cannot import '@tiptap/core' themselves in any
 * shape: a static or plain dynamic import fails the HOST's build when the
 * package isn't installed, and an `import(/* @vite-ignore *​/ …)` survives the
 * build but never resolves at runtime — even when the package IS installed
 * (the browser can't resolve bare specifiers). So the import has to live in
 * the host's own module graph: apps that want the Tiptap driver register a
 * loader in their entry file (their import, statically resolved by their
 * build, code-split because it's a dynamic import):
 *
 *     // resources/js/app.ts
 *     import { registerKinetixTiptap } from '@/composables/useKinetixRichEditorEngine';
 *
 *     registerKinetixTiptap(async () => ({
 *         core: await import('@tiptap/core'),
 *         starterKit: await import('@tiptap/starter-kit'),
 *     }));
 *
 * Apps that never register simply see the inline install notice on
 * `->tiptap()` fields; nothing else changes.
 */

export interface KinetixTiptapModules {
    /** The `@tiptap/core` module (or anything exposing `Editor`). */
    core: { Editor: new (options: Record<string, unknown>) => unknown };
    /** The `@tiptap/starter-kit` module (default export or `StarterKit`). */
    starterKit: { default?: unknown; StarterKit?: unknown } | unknown;
}

export type KinetixTiptapLoader = () => Promise<KinetixTiptapModules>;

export interface KinetixTiptapEngine {
    Editor: new (options: Record<string, unknown>) => unknown;
    StarterKit: unknown;
}

let loader: KinetixTiptapLoader | null = null;

/** Register how to load Tiptap — called once from the host's entry file. */
export function registerKinetixTiptap(load: KinetixTiptapLoader): void {
    loader = load;
}

/**
 * Load the registered Tiptap engine. Null when no loader was registered or
 * loading failed — the editor shows its install notice in both cases.
 */
export async function loadKinetixTiptap(): Promise<KinetixTiptapEngine | null> {
    if (loader === null) {
        return null;
    }

    try {
        const { core, starterKit } = await loader();
        const kit = starterKit as { default?: unknown; StarterKit?: unknown };

        return {
            Editor: core.Editor,
            StarterKit: kit.default ?? kit.StarterKit ?? starterKit,
        };
    } catch {
        return null;
    }
}
