<script setup lang="ts">
import { Check, Circle, Eye, EyeOff } from '@lucide/vue';
import { computed, ref, useAttrs } from 'vue';
import { useI18n } from 'vue-i18n';
import { passwordStrength } from '@/composables/kinetixFieldConstraints';
import type { Requirement } from '@/composables/kinetixFieldConstraints';
import { inputClass } from '@/composables/useKinetixShadcnVariants';

/**
 * A password field: a show/hide toggle, and — when the field has a password
 * policy (`Password::min(10)->mixedCase()->numbers()`) — the policy as a
 * checklist that ticks off live as the user types, with a strength meter.
 *
 * The checklist is guidance, not an error: it updates on every keystroke,
 * each item says met or not met to screen readers, and the strength is
 * announced politely only when its level changes. What the server checks
 * alone (`uncompromised()`) is listed without a tick.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        value?: string | null;
        placeholder?: string;
        disabled?: boolean;
        /** The field's password requirements (`part: 'password'`). */
        requirements?: Requirement[];
        /** `new-password` for a field that sets one, `current-password` to sign in. */
        autocomplete?: string;
    }>(),
    {
        id: undefined,
        value: '',
        placeholder: undefined,
        disabled: false,
        requirements: () => [],
        autocomplete: 'new-password',
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

// The field's aria wiring (invalid, describedby) belongs on the input, with
// the checklist added to what describes it.
defineOptions({ inheritAttrs: false });
const attrs = useAttrs();
const describedBy = computed(
    () =>
        [
            attrs['aria-describedby'] as string | undefined,
            hasPolicy.value && props.id ? `${props.id}-policy` : undefined,
        ]
            .filter(Boolean)
            .join(' ') || undefined,
);

const { t } = useI18n();

const revealed = ref(false);
const text = computed(() => String(props.value ?? ''));

const checklist = computed(() =>
    props.requirements
        .filter((r) => r.part === 'password' && r.hint)
        .map((r) => ({
            label: r.hint as string,
            // Only the server can tell (a known data breach).
            serverOnly: !r.check,
            met: !!r.check && r.check(text.value, {}),
        })),
);

const hasPolicy = computed(() => checklist.value.length > 0);
const strength = computed(() => passwordStrength(text.value));

const STRENGTH = ['', 'weak', 'fair', 'good', 'strong'] as const;
const STRENGTH_TONE = [
    '',
    'bg-destructive',
    'bg-warning',
    'bg-success',
    'bg-success',
];
</script>

<template>
    <div class="space-y-2">
        <div class="relative">
            <input
                :id="id"
                :value="value"
                :type="revealed ? 'text' : 'password'"
                :placeholder="placeholder"
                :disabled="disabled"
                :autocomplete="autocomplete"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                v-bind="attrs"
                :aria-describedby="describedBy"
                :class="[inputClass, 'pr-10']"
                @input="
                    emit(
                        'update:value',
                        ($event.target as HTMLInputElement).value,
                    )
                "
            />
            <button
                type="button"
                class="inset-y-0 right-0 pr-1.5 absolute flex items-center"
                :disabled="disabled"
                :aria-label="revealed ? t('kinetix.hide') : t('kinetix.reveal')"
                :aria-pressed="revealed"
                @click="revealed = !revealed"
            >
                <span
                    class="size-7 flex items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-foreground"
                >
                    <EyeOff v-if="revealed" class="size-4" aria-hidden="true" />
                    <Eye v-else class="size-4" aria-hidden="true" />
                </span>
            </button>
        </div>

        <template v-if="hasPolicy">
            <!-- Strength: four segments, the level in words beside them. -->
            <div v-if="text" class="gap-2 flex items-center">
                <div class="gap-1 grid flex-1 grid-cols-4" aria-hidden="true">
                    <span
                        v-for="segment in 4"
                        :key="segment"
                        class="h-1 rounded-full transition-colors"
                        :class="
                            segment <= strength
                                ? STRENGTH_TONE[strength]
                                : 'bg-muted'
                        "
                    />
                </div>
                <span
                    class="text-xs font-medium text-muted-foreground"
                    aria-live="polite"
                >
                    {{
                        t('kinetix.password_strength', {
                            level: t(
                                `kinetix.password_strength_${STRENGTH[strength]}`,
                            ),
                        })
                    }}
                </span>
            </div>

            <ul :id="id ? `${id}-policy` : undefined" class="space-y-1">
                <li
                    v-for="item in checklist"
                    :key="item.label"
                    class="gap-1.5 text-xs flex items-start"
                    :class="item.met ? 'text-success' : 'text-muted-foreground'"
                >
                    <Check
                        v-if="item.met"
                        class="size-3.5 mt-px shrink-0"
                        aria-hidden="true"
                    />
                    <Circle
                        v-else
                        class="size-3.5 mt-px shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{ item.label }}</span>
                    <span v-if="!item.serverOnly" class="sr-only">
                        ({{
                            item.met
                                ? t('kinetix.rule_met')
                                : t('kinetix.rule_not_met')
                        }})
                    </span>
                </li>
            </ul>
        </template>
    </div>
</template>
