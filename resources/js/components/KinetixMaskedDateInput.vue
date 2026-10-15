<script setup lang="ts">
import { computed, ref, useAttrs, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    applyDateMask,
    dateMaskToIso,
    defaultDateFormat,
    isoToDateMask,
    maskPlaceholder,
    parseDateFormat,
} from '@/composables/kinetixDateMask';
import type { KinetixDateKind } from '@/composables/kinetixDateMask';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import { cn } from './primitives/cn';

/**
 * A date or time typed on the keyboard over a mask: the reader types digits,
 * the separators of the format (`dd/mm/yyyy`, `mm/dd/yyyy hh:mm a`…) appear
 * for them, and the field gets the ISO value once the text is complete. A
 * date that doesn't exist (31/02) or falls outside the bounds is said so
 * under the field. The pickers use it in their `typed` mode, with their
 * calendar or clock in the `button` slot.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        /** ISO value: `Y-m-d`, `Y-m-dTH:i`, `H:i` or `Y-m`. */
        value?: string | null;
        kind?: KinetixDateKind;
        /** The mask's format; null = the locale's order. */
        format?: string | null;
        locale?: string | null;
        /** The field's clock, for the default format. */
        hour12?: boolean | null;
        disabled?: boolean;
        minValue?: string | null;
        maxValue?: string | null;
    }>(),
    {
        id: undefined,
        value: null,
        kind: 'date',
        format: null,
        locale: null,
        hour12: null,
        disabled: false,
        minValue: null,
        maxValue: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string | null): void }>();

// The field's own wiring (`aria-describedby` to its help and error,
// `aria-invalid`) belongs on the input, joined with the mask's.
defineOptions({ inheritAttrs: false });
const attrs = useAttrs();

const { t, locale: appLocale } = useI18n();

const segments = computed(() =>
    parseDateFormat(
        props.format ||
            defaultDateFormat(
                props.kind,
                props.locale ?? appLocale.value,
                props.hour12,
            ),
    ),
);
const placeholder = computed(() => maskPlaceholder(segments.value));
// AM/PM is typed as a letter, which a numeric keypad doesn't have.
const hasMeridiem = computed(() =>
    segments.value.some((segment) => segment.type === 'meridiem'),
);

const text = ref(isoToDateMask(props.value, segments.value));
const problem = ref<'invalid' | 'incomplete' | 'range' | null>(null);

// What this input last handed the field: anything else arriving through
// `value` was set from outside (the calendar, a reset) and shows in the mask.
let lastEmitted: string | null = props.value ?? null;

watch(
    () => [props.value ?? null, segments.value] as const,
    ([value], [, previousSegments]) => {
        if (value === lastEmitted && segments.value === previousSegments) {
            return;
        }

        lastEmitted = value;
        text.value = isoToDateMask(value, segments.value);
        problem.value = null;
    },
);

/** Hands the field a value — `null` while the text isn't a usable one. */
function hand(value: string | null): void {
    if (value !== lastEmitted) {
        lastEmitted = value;
        emit('update:value', value);
    }
}

const errorId = computed(() =>
    props.id ? `${props.id}-mask-error` : undefined,
);
const hintId = computed(() =>
    props.id ? `${props.id}-mask-format` : undefined,
);

const fieldInvalid = computed(() =>
    attrs['aria-invalid'] === 'true' || attrs['aria-invalid'] === true
        ? 'true'
        : undefined,
);

const describedBy = computed(
    () =>
        [
            attrs['aria-describedby'] as string | undefined,
            hintId.value,
            problem.value ? errorId.value : null,
        ]
            .filter(Boolean)
            .join(' ') || undefined,
);

const message = computed(() => {
    const what =
        props.kind === 'time'
            ? 'kinetix.date_mask_time_invalid'
            : 'kinetix.date_mask_invalid';

    return {
        invalid: t(what),
        incomplete: t('kinetix.date_mask_incomplete', {
            format: placeholder.value,
        }),
        range: t('kinetix.date_mask_range'),
        null: '',
    }[String(problem.value) as 'invalid' | 'incomplete' | 'range' | 'null'];
});

/** ISO strings compare in order, a date bound against a datetime value too. */
const outOfRange = (iso: string): boolean =>
    (!!props.minValue &&
        iso.slice(0, props.minValue.length) < props.minValue) ||
    (!!props.maxValue && iso.slice(0, props.maxValue.length) > props.maxValue);

function onInput(event: Event): void {
    const input = event.target as HTMLInputElement;
    const masked = applyDateMask(input.value, segments.value);

    text.value = masked;
    input.value = masked;

    const iso = dateMaskToIso(masked, segments.value, props.kind);

    if (iso === false) {
        problem.value = 'invalid';
    } else if (iso !== null && outOfRange(iso)) {
        problem.value = 'range';
    } else {
        problem.value = null;
    }

    // A half-typed or impossible date leaves the field empty, so a stale
    // value never submits behind the text the reader sees.
    hand(typeof iso === 'string' && problem.value === null ? iso : null);
}

/** Leaving a half-typed value says what's missing. */
function onBlur(): void {
    if (
        text.value !== '' &&
        dateMaskToIso(text.value, segments.value, props.kind) === null
    ) {
        problem.value = 'incomplete';
    }
}
</script>

<template>
    <div class="min-w-0 w-full">
        <div class="flex w-full">
            <input
                v-bind="attrs"
                :id="id"
                type="text"
                :value="text"
                :placeholder="placeholder"
                :disabled="disabled"
                :inputmode="hasMeridiem ? 'text' : 'numeric'"
                autocomplete="off"
                spellcheck="false"
                :aria-invalid="problem ? 'true' : fieldInvalid"
                :aria-describedby="describedBy"
                :class="
                    cn(
                        inputClass,
                        'tabular-nums',
                        $slots.button && 'rounded-r-none',
                    )
                "
                @input="onInput"
                @blur="onBlur"
            />
            <slot name="button" />
        </div>
        <span :id="hintId" class="sr-only">{{
            t('kinetix.date_mask_format', { format: placeholder })
        }}</span>
        <p
            v-if="problem"
            :id="errorId"
            class="mt-1.5 text-sm text-destructive"
            aria-live="polite"
        >
            {{ message }}
        </p>
    </div>
</template>
