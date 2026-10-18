import type { Component } from 'vue';

/**
 * Registration seam for the flow editor's canvas engine, Vue Flow
 * (`@vue-flow/core` and its background, controls and minimap).
 *
 * Published components can't import it themselves (see the Tiptap seam for
 * why): apps that use <KinetixFlowEditor> register a loader in their entry
 * file — their imports, code-split — along with its stylesheets:
 *
 *     // resources/js/app.ts
 *     import { registerKinetixFlowEditor } from '@/composables/useKinetixFlowEngine';
 *
 *     registerKinetixFlowEditor(async () => {
 *         await Promise.all([
 *             import('@vue-flow/core/dist/style.css'),
 *             import('@vue-flow/core/dist/theme-default.css'),
 *             import('@vue-flow/controls/dist/style.css'),
 *             import('@vue-flow/minimap/dist/style.css'),
 *         ]);
 *
 *         return {
 *             core: await import('@vue-flow/core'),
 *             background: await import('@vue-flow/background'),
 *             controls: await import('@vue-flow/controls'),
 *             minimap: await import('@vue-flow/minimap'),
 *         };
 *     });
 *
 * Without it, the editor shows how to install it.
 */

export interface KinetixFlowEngineModules {
    core: {
        VueFlow: Component;
        Handle: Component;
        Position: Record<string, string>;
    };
    background?: { Background: Component };
    controls?: { Controls: Component };
    minimap?: { MiniMap: Component };
}

export type KinetixFlowEngineLoader = () => Promise<KinetixFlowEngineModules>;

export interface KinetixFlowEngine {
    VueFlow: Component;
    Handle: Component;
    Position: Record<string, string>;
    Background: Component | null;
    Controls: Component | null;
    MiniMap: Component | null;
}

let loader: KinetixFlowEngineLoader | null = null;
let loaded: Promise<KinetixFlowEngine | null> | null = null;

/** Register how to load Vue Flow — called once from the host's entry file. */
export function registerKinetixFlowEditor(load: KinetixFlowEngineLoader): void {
    loader = load;
    loaded = null;
}

/** Load the registered engine once. Null when none is registered or it failed to load. */
export function loadKinetixFlowEngine(): Promise<KinetixFlowEngine | null> {
    if (loader === null) {
        return Promise.resolve(null);
    }

    loaded ??= loader()
        .then((modules) => ({
            VueFlow: modules.core.VueFlow,
            Handle: modules.core.Handle,
            Position: modules.core.Position,
            Background: modules.background?.Background ?? null,
            Controls: modules.controls?.Controls ?? null,
            MiniMap: modules.minimap?.MiniMap ?? null,
        }))
        .catch(() => null);

    return loaded;
}
