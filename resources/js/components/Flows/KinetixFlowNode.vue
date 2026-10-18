<script setup lang="ts">
import { AlertCircle } from '@lucide/vue';
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixFlowEngine } from '@/composables/useKinetixFlowEngine';
import { cn } from '../primitives/cn';
import { FLOW_EDITOR } from './flowEditorContext';
import { flowKind } from './flowNodes';

/**
 * A node on the flow canvas: its kind and a preview of its settings, an
 * input on the left and one output per branch on the right. Shows what's
 * wrong with it, how many conversations went through it (statistics), and
 * where the test chat stands.
 */
const props = defineProps<{
    id: string;
    type: string;
    data: Record<string, unknown>;
    selected?: boolean;
}>();

const { t } = useI18n();
const engine = inject<KinetixFlowEngine>('kinetixFlowEngine');
const editor = inject(FLOW_EDITOR, null);

const kind = computed(() => flowKind(props.type));
const outputs = computed(() => kind.value.outputs(props.data, t));
const summary = computed(() => kind.value.summary(props.data, t));
const problem = computed(() => editor?.problems.value[props.id] ?? null);
const stat = computed(() => editor?.stats.value?.[props.id] ?? null);
const active = computed(() => editor?.activeNode.value === props.id);
const visited = computed(() => editor?.path.value.includes(props.id) ?? false);
const label = computed(() => {
    const key = `kinetix.flow_node_${props.type}`;
    const translated = t(key);

    return translated === key ? props.type : translated;
});
</script>

<template>
    <div
        :class="
            cn(
                'w-64 rounded-xl shadow-sm border bg-card text-card-foreground transition-shadow',
                selected
                    ? 'border-primary ring-2 ring-primary/30'
                    : 'border-border',
                problem && !selected && 'border-destructive/70',
                active && 'ring-4 ring-warning/50',
                visited && !active && 'ring-2 ring-primary/20',
            )
        "
        :data-test="`flow-node-${id}`"
    >
        <component
            :is="engine.Handle"
            v-if="engine && type !== 'start'"
            type="target"
            :position="engine.Position.Left"
            class="!size-3 !border-2 !border-background !bg-muted-foreground"
        />

        <div class="gap-2 px-3 pt-3 flex items-center">
            <span
                class="size-7 inline-flex shrink-0 items-center justify-center rounded-md"
                :class="kind.tone"
            >
                <component :is="kind.icon" class="size-4" />
            </span>
            <span class="text-sm font-semibold min-w-0 flex-1 truncate">{{
                label
            }}</span>
            <span
                v-if="stat"
                class="px-1.5 py-0.5 font-medium rounded-full bg-muted text-[11px] text-muted-foreground tabular-nums"
                :title="
                    t('kinetix.flow_stats_tooltip', {
                        entered: stat.entered,
                        waiting: stat.waiting,
                        stopped: stat.stopped,
                    })
                "
                data-test="flow-node-stat"
                >{{ stat.entered }}</span
            >
            <AlertCircle
                v-if="problem"
                class="size-4 shrink-0 text-destructive"
                :aria-label="problem"
                role="img"
            />
        </div>

        <p
            class="px-3 pt-1.5 text-xs line-clamp-3 break-words text-muted-foreground"
            :class="{ 'italic opacity-70': !summary }"
        >
            {{ summary || t('kinetix.flow_node_empty') }}
        </p>

        <p
            v-if="problem"
            class="px-3 pt-1 line-clamp-2 text-[11px] text-destructive"
        >
            {{ problem }}
        </p>

        <p
            v-if="stat && (stat.waiting || stat.stopped)"
            class="px-3 pt-1 text-[11px] text-muted-foreground"
        >
            <template v-if="stat.waiting">{{
                t('kinetix.flow_stats_waiting', { count: stat.waiting })
            }}</template>
            <template v-if="stat.waiting && stat.stopped"> · </template>
            <template v-if="stat.stopped">{{
                t('kinetix.flow_stats_stopped', { count: stat.stopped })
            }}</template>
        </p>

        <!-- One output per branch, each with its handle on the right edge. -->
        <ul v-if="outputs.length > 1" class="mt-2 py-1 border-t border-border">
            <li
                v-for="output in outputs"
                :key="output.id"
                class="px-3 py-1 text-xs relative text-right text-muted-foreground"
            >
                <span class="pr-1 block truncate">{{ output.label }}</span>
                <component
                    :is="engine.Handle"
                    v-if="engine"
                    :id="output.id"
                    type="source"
                    :position="engine.Position.Right"
                    class="!size-3 !border-2 !border-background !bg-primary"
                />
            </li>
        </ul>
        <div v-else class="h-3" />
        <component
            :is="engine.Handle"
            v-if="engine && outputs.length === 1"
            :id="outputs[0].id"
            type="source"
            :position="engine.Position.Right"
            class="!size-3 !border-2 !border-background !bg-primary"
        />
    </div>
</template>
