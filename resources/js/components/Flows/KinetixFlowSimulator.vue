<script setup lang="ts">
import { Bot, Lock, RotateCcw, SendHorizontal } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type { KinetixFlowSimulation } from '@/types/kinetix';

/**
 * The editor's test chat: talks to the flow as a contact would, with the
 * graph as it's being edited — nothing reaches WhatsApp or Telegram, waits
 * pass at once. Options are tappable; the canvas follows along.
 */
const props = defineProps<{
    run: (
        state: Record<string, unknown> | null,
        input: string | null,
    ) => Promise<KinetixFlowSimulation | null>;
}>();

const emit = defineEmits<{
    (e: 'position', node: string | null, path: string[]): void;
}>();

const { t } = useI18n();

interface Line {
    kind: 'bot' | 'note' | 'system' | 'me';
    text: string;
    options: string[];
}

const lines = ref<Line[]>([]);
const state = ref<Record<string, unknown> | null>(null);
const status = ref<string>('idle');
const input = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
const showVariables = ref(false);
const scroller = ref<HTMLElement | null>(null);
const path = ref<string[]>([]);

const waiting = computed(() => status.value === 'waiting_reply');
const variables = computed(
    () => (state.value?.variables as Record<string, unknown>) ?? {},
);
const lastOptions = computed(() => {
    const last = [...lines.value].reverse().find((line) => line.kind === 'bot');

    return waiting.value ? (last?.options ?? []) : [];
});

async function step(answer: string | null): Promise<void> {
    busy.value = true;
    error.value = null;

    if (answer !== null) {
        lines.value.push({ kind: 'me', text: answer, options: [] });
    }

    try {
        const result = await props.run(state.value, answer);

        if (result) {
            lines.value.push(...result.entries);
            state.value = result.state;
            status.value = result.status;
            path.value = [...path.value, ...result.path];
            emit(
                'position',
                result.status === 'waiting_reply' ? result.node : null,
                path.value,
            );
        }
    } catch (e) {
        error.value = e instanceof Error ? e.message : String(e);
    } finally {
        busy.value = false;
    }
}

function restart(): void {
    lines.value = [];
    state.value = null;
    status.value = 'idle';
    path.value = [];
    emit('position', null, []);
    void step(null);
}

function send(text?: string): void {
    const answer = (text ?? input.value).trim();

    if (!answer || busy.value || !waiting.value) {
        return;
    }

    input.value = '';
    void step(answer);
}

watch(
    () => lines.value.length,
    () =>
        nextTick(() => {
            if (scroller.value) {
                scroller.value.scrollTop = scroller.value.scrollHeight;
            }
        }),
);

defineExpose({ restart });
</script>

<template>
    <div class="min-h-0 flex h-full flex-col" data-test="flow-simulator">
        <div class="gap-2 pb-3 flex items-center">
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-foreground">
                    {{ t('kinetix.flow_test') }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    {{ t('kinetix.flow_test_help') }}
                </p>
            </div>
            <button
                type="button"
                class="gap-1.5 px-2.5 py-1.5 text-xs font-medium inline-flex items-center rounded-md border border-border transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                data-test="flow-test-restart"
                @click="restart"
            >
                <RotateCcw class="size-3.5" />
                {{
                    status === 'idle'
                        ? t('kinetix.flow_test_start')
                        : t('kinetix.flow_test_restart')
                }}
            </button>
        </div>

        <div
            ref="scroller"
            class="min-h-0 p-3 space-y-2 rounded-lg flex-1 overflow-y-auto border border-border bg-muted/30"
            role="log"
            aria-live="polite"
        >
            <p
                v-if="lines.length === 0"
                class="py-8 text-xs text-center text-muted-foreground"
            >
                {{ t('kinetix.flow_test_empty') }}
            </p>
            <template v-for="(line, index) in lines" :key="index">
                <p
                    v-if="line.kind === 'system'"
                    class="text-center text-[11px] text-muted-foreground"
                >
                    {{ line.text }}
                </p>
                <div
                    v-else
                    class="flex"
                    :class="
                        line.kind === 'me' ? 'justify-end' : 'justify-start'
                    "
                >
                    <div
                        class="rounded-2xl px-3 py-1.5 text-sm max-w-[85%] break-words whitespace-pre-wrap"
                        :class="
                            line.kind === 'me'
                                ? 'rounded-br-md bg-primary text-primary-foreground'
                                : line.kind === 'note'
                                  ? 'rounded-bl-md border border-warning/40 bg-warning/10 text-foreground'
                                  : 'shadow-xs rounded-bl-md bg-background text-foreground'
                        "
                    >
                        <span
                            v-if="line.kind !== 'me'"
                            class="gap-1 font-medium mb-0.5 flex items-center text-[10px] text-muted-foreground"
                        >
                            <Lock v-if="line.kind === 'note'" class="size-3" />
                            <Bot v-else class="size-3" />
                            {{
                                line.kind === 'note'
                                    ? t('kinetix.inbox_note')
                                    : t('kinetix.flow_bot')
                            }}
                        </span>
                        <span class="whitespace-pre-wrap">{{ line.text }}</span>
                        <ol
                            v-if="line.options.length"
                            class="mt-1 text-xs list-inside list-decimal opacity-90"
                        >
                            <li v-for="option in line.options" :key="option">
                                {{ option }}
                            </li>
                        </ol>
                    </div>
                </div>
            </template>
        </div>

        <div v-if="lastOptions.length" class="gap-1.5 pt-2 flex flex-wrap">
            <button
                v-for="option in lastOptions"
                :key="option"
                type="button"
                class="px-2.5 py-1 text-xs font-medium rounded-full border border-primary/40 text-primary transition-colors hover:bg-primary/10 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                data-test="flow-test-option"
                @click="send(option)"
            >
                {{ option }}
            </button>
        </div>

        <p v-if="error" class="pt-2 text-xs text-destructive" role="alert">
            {{ error }}
        </p>

        <form class="gap-2 pt-2 flex" @submit.prevent="send()">
            <input
                v-model="input"
                :class="inputClass"
                :disabled="!waiting || busy"
                :placeholder="
                    waiting
                        ? t('kinetix.flow_test_reply')
                        : t('kinetix.flow_test_not_waiting')
                "
                :aria-label="t('kinetix.flow_test_reply')"
                data-test="flow-test-input"
            />
            <button
                type="submit"
                class="size-9 inline-flex shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-40"
                :disabled="!waiting || busy || !input.trim()"
                :aria-label="t('kinetix.inbox_send')"
            >
                <SendHorizontal class="size-4" />
            </button>
        </form>

        <div class="pt-3">
            <button
                type="button"
                class="text-xs font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:underline focus-visible:outline-none"
                :aria-expanded="showVariables"
                @click="showVariables = !showVariables"
            >
                {{ t('kinetix.flow_test_variables') }}
            </button>
            <pre
                v-if="showVariables"
                class="mt-2 max-h-40 p-2 font-mono overflow-auto rounded-md bg-muted text-[11px] text-foreground"
                >{{ JSON.stringify(variables, null, 2) }}</pre
            >
        </div>
    </div>
</template>
