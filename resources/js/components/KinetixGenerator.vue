<script setup lang="ts">
import { Eye, EyeOff, RefreshCw } from '@lucide/vue';
import { computed, ref, useAttrs, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    handleFromPattern,
    useKinetixGenerator,
    GENERATOR_PRESETS,
} from '@/composables/useKinetixGenerator';
import type { KinetixGeneratorConfig } from '@/composables/useKinetixGenerator';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import { cn } from './primitives/cn';
import KinetixCopyable from './primitives/KinetixCopyable.vue';

/**
 * A configurable value generator (password / PIN / username / custom) that
 * works TWO ways:
 *
 *  1. WITH its own input (default) — a text field + a regenerate button, and
 *     optional copy and reveal affordances. Use with `v-model:value`, or let
 *     the `generator-input` form field drive it.
 *
 *  2. WITHOUT an input, driving an external TARGET — pass `:input="false"` and
 *     a `target`: a callback `(value) => …`, or a CSS selector / element whose
 *     `.value` is set and an `input` event dispatched (so a plain `<input>`,
 *     Alpine, or another framework picks it up). Renders just the button(s).
 *
 * Generation is client-side and crypto-strong (see `useKinetixGenerator`).
 * Styled with the shadcn token contract (inputClass / buttonVariants).
 */
defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        /** The input's id, so a field label (`for`) names it. */
        id?: string | null;
        value?: string | null;
        config?: KinetixGeneratorConfig | null;
        /** Sibling form values, for a username `pattern`. */
        values?: Record<string, unknown>;
        /** Render the built-in input (mode 1). false = button-only (mode 2). */
        input?: boolean;
        /** Mode 2 target: a writer callback, a CSS selector, or an element. */
        target?: ((value: string) => void) | string | HTMLInputElement | null;
        /**
         * Show a preset picker. `true` lists every catalog preset; an array
         * limits it to those names. The chosen preset's config merges over
         * `config`, so the reader can switch password/pin/uuid/… live.
         */
        presets?: boolean | string[];
        disabled?: boolean;
        placeholder?: string | null;
    }>(),
    {
        id: null,
        value: null,
        config: null,
        values: () => ({}),
        input: true,
        target: null,
        presets: false,
        disabled: false,
        placeholder: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

const { t } = useI18n();

// Preset picker (opt-in). The active preset's config merges over the base.
const presetNames = computed<string[]>(() =>
    Array.isArray(props.presets)
        ? props.presets
        : props.presets
          ? Object.keys(GENERATOR_PRESETS)
          : [],
);
const activePreset = ref<string>(
    props.config?.preset ?? presetNames.value[0] ?? '',
);

const effectiveConfig = computed<KinetixGeneratorConfig>(() => {
    const base = props.config ?? {};

    return presetNames.value.length && activePreset.value
        ? { ...base, preset: activePreset.value }
        : base;
});

const { generate } = useKinetixGenerator(() => effectiveConfig.value);

const revealable = computed<boolean>(
    () => effectiveConfig.value.revealable !== false,
);
const copyable = computed<boolean>(() => !!effectiveConfig.value.copyable);
const revealed = ref(false);

const inputType = computed<string>(() =>
    revealable.value || revealed.value ? 'text' : 'password',
);

/** Write a freshly generated value to every wired-up destination. */
const writeToTarget = (value: string): void => {
    const target = props.target;

    if (!target) {
        return;
    }

    if (typeof target === 'function') {
        target(value);

        return;
    }

    const el =
        typeof target === 'string'
            ? (document.querySelector(target) as HTMLInputElement | null)
            : target;

    if (el) {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }
};

// The wrapper keeps class/style; everything else a form field hands down
// (aria-invalid, aria-describedby) describes the control, so it goes on the
// input — on the wrapper div it was invisible to assistive tech.
const attrs = useAttrs();
const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }));
const controlAttrs = computed(() =>
    Object.fromEntries(
        Object.entries(attrs).filter(
            ([name]) => name !== 'class' && name !== 'style',
        ),
    ),
);

// A `{field}` pattern follows the sibling values live, like a slug: while the
// field is empty or still holds the last value it filled in itself. Once the
// user types their own, it stops. Nothing is filled in until the siblings
// resolve the pattern.
const lastAuto = ref<string | null>(null);

watch(
    () => [props.values, effectiveConfig.value] as const,
    () => {
        if (props.disabled) {
            return;
        }

        const next = handleFromPattern(effectiveConfig.value, props.values);
        const current = props.value ?? '';

        if (
            next === '' ||
            next === current ||
            (current !== '' && current !== lastAuto.value)
        ) {
            return;
        }

        lastAuto.value = next;
        emit('update:value', next);
        writeToTarget(next);
    },
    { deep: true, immediate: true },
);

const onGenerate = (): void => {
    if (props.disabled) {
        return;
    }

    const next = generate(props.values);
    emit('update:value', next);
    writeToTarget(next);
};

const onInput = (event: Event): void => {
    emit('update:value', (event.target as HTMLInputElement).value);
};

/** Switching the preset regenerates immediately, so the change is visible. */
const onPresetChange = (event: Event): void => {
    activePreset.value = (event.target as HTMLSelectElement).value;
    onGenerate();
};
</script>

<template>
    <div class="space-y-2" v-bind="rootAttrs">
        <!-- Optional preset picker -->
        <select
            v-if="presetNames.length"
            :value="activePreset"
            :disabled="disabled"
            :class="cn(inputClass, 'cursor-pointer')"
            :aria-label="t('kinetix.generate')"
            @change="onPresetChange"
        >
            <option v-for="name in presetNames" :key="name" :value="name">
                {{ name }}
            </option>
        </select>

        <div class="gap-2 flex items-center">
            <!-- Mode 1: built-in input -->
            <div v-if="input" class="min-w-0 relative flex-1">
                <input
                    :id="id ?? undefined"
                    v-bind="controlAttrs"
                    :value="value ?? ''"
                    :type="inputType"
                    :disabled="disabled"
                    :placeholder="placeholder ?? ''"
                    :class="
                        cn(
                            inputClass,
                            (revealable && copyable) || !revealable
                                ? 'pr-10'
                                : '',
                        )
                    "
                    autocomplete="off"
                    spellcheck="false"
                    @input="onInput"
                />
                <!-- Reveal toggle (only when masked) -->
                <button
                    v-if="!revealable"
                    type="button"
                    :aria-label="
                        revealed ? t('kinetix.hide') : t('kinetix.show')
                    "
                    class="right-2 size-6 absolute top-1/2 flex -translate-y-1/2 touch-manipulation items-center justify-center rounded-md text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :disabled="disabled"
                    @click="revealed = !revealed"
                >
                    <component :is="revealed ? EyeOff : Eye" class="size-4" />
                </button>
            </div>

            <!-- Copy (optional) -->
            <KinetixCopyable v-if="copyable && value" :value="String(value)" />

            <!-- Regenerate -->
            <button
                type="button"
                :class="
                    cn(
                        buttonVariants({ variant: 'outline', size: 'icon' }),
                        'shrink-0 touch-manipulation',
                    )
                "
                :aria-label="t('kinetix.generate')"
                :title="t('kinetix.generate')"
                :disabled="disabled"
                @click="onGenerate"
            >
                <RefreshCw class="size-4" />
            </button>
        </div>
    </div>
</template>
