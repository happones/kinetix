<script setup lang="ts">
import { Check, WandSparkles } from '@lucide/vue';
import { computed, useAttrs } from 'vue';
import { useI18n } from 'vue-i18n';
import { textareaClass } from '@/composables/useKinetixShadcnVariants';

/**
 * A JSON editor: a monospace text area, a line under it saying whether the
 * JSON holds — and, when it doesn't, at which line and column it breaks —
 * and a Format button that indents it. Tab keeps moving focus (no keyboard
 * trap); indentation is what Format is for.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        value?: string | null;
        placeholder?: string;
        disabled?: boolean;
        /** Minimum height in lines. */
        rows?: number;
    }>(),
    {
        id: undefined,
        value: '',
        placeholder: undefined,
        disabled: false,
        rows: 8,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

defineOptions({ inheritAttrs: false });
const attrs = useAttrs();
const { t } = useI18n();

const text = computed(() => String(props.value ?? ''));

/**
 * The offset where JSON stops being valid — a small scanner, because browsers
 * word (and place) the parser's error differently, and recent ones don't say
 * where at all. `JSON.parse` still decides whether it's valid.
 */
function errorOffset(src: string): number {
    let i = 0;

    const fail = (): never => {
        throw i;
    };
    const ws = (): void => {
        while (i < src.length && ' \t\n\r'.includes(src[i])) {
            i++;
        }
    };
    const string = (): void => {
        i++;

        while (i < src.length) {
            const c = src[i];

            if (c === '"') {
                i++;

                return;
            }

            if (c < ' ') {
                fail();
            }

            i += c === '\\' ? 2 : 1;
        }

        fail();
    };
    const value = (): void => {
        ws();
        const c = src[i];

        if (c === '{' || c === '[') {
            const close = c === '{' ? '}' : ']';
            i++;
            ws();

            if (src[i] === close) {
                i++;

                return;
            }

            for (;;) {
                if (c === '{') {
                    ws();

                    if (src[i] !== '"') {
                        fail();
                    }

                    string();
                    ws();

                    if (src[i] !== ':') {
                        fail();
                    }

                    i++;
                }

                value();
                ws();

                if (src[i] === ',') {
                    i++;
                    continue;
                }

                if (src[i] === close) {
                    i++;

                    return;
                }

                fail();
            }
        }

        if (c === '"') {
            string();

            return;
        }

        const literal =
            /^(?:true|false|null|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)/.exec(
                src.slice(i),
            );

        if (!literal) {
            fail();
        }

        i += literal![0].length;
    };

    try {
        value();
        ws();

        return i < src.length ? i : src.length;
    } catch (at) {
        return typeof at === 'number' ? at : src.length;
    }
}

const parsed = computed<
    { ok: true } | { ok: false; line: number; column: number }
>(() => {
    if (text.value.trim() === '') {
        return { ok: true };
    }

    try {
        JSON.parse(text.value);

        return { ok: true };
    } catch {
        const before = text.value.slice(0, errorOffset(text.value)).split('\n');

        return {
            ok: false,
            line: before.length,
            column: before[before.length - 1].length + 1,
        };
    }
});

const format = (): void => {
    if (!parsed.value.ok || text.value.trim() === '') {
        return;
    }

    emit('update:value', JSON.stringify(JSON.parse(text.value), null, 2));
};
</script>

<template>
    <div class="space-y-1.5">
        <textarea
            :id="id"
            :value="value ?? ''"
            :placeholder="placeholder"
            :disabled="disabled"
            spellcheck="false"
            autocapitalize="none"
            autocorrect="off"
            v-bind="attrs"
            :class="[textareaClass, 'font-mono text-xs leading-relaxed']"
            :style="{ minHeight: `calc(${rows}lh + 1rem + 2px)` }"
            @input="
                emit(
                    'update:value',
                    ($event.target as HTMLTextAreaElement).value,
                )
            "
        />
        <div class="gap-2 flex items-center justify-between">
            <p class="text-xs" aria-live="polite">
                <span
                    v-if="text.trim() !== '' && parsed.ok"
                    class="gap-1 inline-flex items-center text-success"
                >
                    <Check class="size-3.5" aria-hidden="true" />
                    {{ t('kinetix.json_valid') }}
                </span>
                <span v-else-if="!parsed.ok" class="text-destructive">
                    {{
                        t('kinetix.json_breaks_at', {
                            line: parsed.line,
                            column: parsed.column,
                        })
                    }}
                </span>
            </p>
            <button
                type="button"
                class="gap-1 px-2 py-1 text-xs font-medium inline-flex items-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                :disabled="disabled || !parsed.ok || text.trim() === ''"
                @click="format"
            >
                <WandSparkles class="size-3.5" aria-hidden="true" />
                {{ t('kinetix.json_format') }}
            </button>
        </div>
    </div>
</template>
