import type { InjectionKey, Ref } from 'vue';
import type { KinetixFlowStats } from '@/types/kinetix';

/** What the canvas nodes read from the editor: problems, statistics, the test chat's position. */
export interface FlowEditorContext {
    problems: Ref<Record<string, string>>;
    stats: Ref<KinetixFlowStats['nodes'] | null>;
    activeNode: Ref<string | null>;
    path: Ref<string[]>;
}

export const FLOW_EDITOR: InjectionKey<FlowEditorContext> = Symbol(
    'kinetix-flow-editor',
);
