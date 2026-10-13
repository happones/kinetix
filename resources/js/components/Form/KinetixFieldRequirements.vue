<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    characterCounter,
    confirmsMatch,
    requirementHints,
} from '@/composables/kinetixFieldConstraints';
import type { Requirement } from '@/composables/kinetixFieldConstraints';

/**
 * What a field's rules ask for, under the field: the requirements as one
 * line of helper text, a character counter when the length is capped, and a
 * "Matches" confirmation for a field that must equal another. The hints'
 * id (`<name>-rules`) is on the field's `aria-describedby` chain; the
 * counter speaks only near the limit, so typing isn't narrated.
 */
const props = defineProps<{
    name: string;
    constraints: any;
    requirements: Requirement[];
    value: unknown;
    values: Record<string, unknown>;
    /** A violation is showing: it already says what the hints say. */
    hasError: boolean;
    /** Parts the input lists itself (a password's checklist). */
    listedByInput?: string[];
}>();

const { t } = useI18n();

const hints = computed(() =>
    requirementHints(props.requirements, props.listedByInput),
);
const counter = computed(() =>
    characterCounter(props.constraints, props.value),
);
const remaining = computed(() =>
    counter.value ? counter.value.max - counter.value.count : null,
);
const matches = computed(() =>
    confirmsMatch(props.requirements, props.value, props.values),
);
</script>

<template>
    <div
        v-if="hints.length || counter || matches"
        class="gap-3 mt-1 flex items-start justify-between"
    >
        <div class="min-w-0 space-y-0.5">
            <p
                v-if="hints.length && !hasError && !matches"
                :id="`${name}-rules`"
                class="text-xs text-muted-foreground"
            >
                {{ hints.join(' · ') }}
            </p>
            <p
                v-if="matches"
                class="gap-1 text-xs font-medium inline-flex items-center text-success"
                aria-live="polite"
            >
                <Check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ t('kinetix.rule_matches') }}
            </p>
        </div>

        <template v-if="counter">
            <span
                class="text-xs shrink-0 tabular-nums"
                :class="
                    remaining !== null && remaining < 0
                        ? 'font-semibold text-destructive'
                        : 'text-muted-foreground'
                "
                aria-hidden="true"
            >
                {{ counter.count }}/{{ counter.max }}
            </span>
            <span class="sr-only" aria-live="polite">
                {{
                    remaining !== null && remaining <= 10
                        ? t('kinetix.rule_characters_left', {
                              count: remaining,
                          })
                        : ''
                }}
            </span>
        </template>
    </div>
</template>
