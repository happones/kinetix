<script setup lang="ts">
import { Plus, Trash2, ChevronUp, ChevronDown } from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    fieldRequirements,
    requirementViolation,
} from '@/composables/kinetixFieldConstraints';
import type { Requirement } from '@/composables/kinetixFieldConstraints';
import { useKinetixFieldConditions } from '@/composables/useKinetixFieldConditions';
import { useKinetixRepeater } from '@/composables/useKinetixRepeaterField';
import './kinetix-grid.css';
import {
    gridColumnVars,
    resolveColumns,
    SINGLE_COLUMN,
    spanVars,
} from '@/composables/useKinetixResponsiveGrid';
import type { ResponsiveColumns } from '@/composables/useKinetixResponsiveGrid';
import KinetixFieldRequirements from './Form/KinetixFieldRequirements.vue';
import KinetixFormField from './Form/KinetixFormField.vue';
import KinetixFormTabs from './KinetixFormTabs.vue';
import KinetixFormWizard from './KinetixFormWizard.vue';
import KinetixLabel from './KinetixLabel.vue';

const props = defineProps<{
    schema: any[];
    values: Record<string, any>;
    errors: Record<string, string>;
    /**
     * The enclosing grid's per-breakpoint column counts — spans clamp against
     * it so they never overflow. Defaults to a single column (the form root).
     */
    parentColumns?: ResponsiveColumns;
    /**
     * Chrome-free rendering for forms hosted inside a modal: the modal panel
     * is already the surface, so Sections drop their card (border/shadow/bg)
     * and render as divided groups instead of nesting card-in-modal.
     */
    flat?: boolean;
}>();

const { t, locale } = useI18n();

const emit = defineEmits<{
    (e: 'update:value', name: string, value: any): void;
}>();

const cols = computed<ResponsiveColumns>(
    () => props.parentColumns ?? SINGLE_COLUMN,
);

/** Per-breakpoint `--kx-span-*` vars for a schema node. */
const colStyle = (comp: any): Record<string, string> =>
    spanVars(comp.columnSpan, cols.value);

/** Per-breakpoint column counts for a grid-bearing node (grid/section/fieldset). */
const gridOf = (comp: any): ResponsiveColumns => resolveColumns(comp.columns);

// Inline-repeater add/remove/reorder/update, keyed by field name.
const { itemsOf, addItem, removeItem, moveItem, updateItem } =
    useKinetixRepeater({
        values: () => props.values,
        emit: (name, value) => emit('update:value', name, value),
    });

// Conditional visibility/disable/require (visibleWhen/hiddenWhen/…), evaluated
// live against the current form values. Invisible nodes are dropped from the
// render (so a hidden field holds no focus and submits no value via the schema
// walk); a conditionally disabled/required field gets its flag merged in. Nodes
// without conditions pass through untouched (same object reference).
const { resolve } = useKinetixFieldConditions();

// What each field's rules ask of its value (see kinetixFieldConstraints),
// cached per constraints object and locale: the schema re-renders on every
// keystroke, the rules don't change with it.
const fieldLabel = inject<(name: string) => string>(
    'kinetixFieldLabel',
    (name) =>
        name.replace(/[_.-]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase()),
);
const requirementCache = new WeakMap<
    object,
    { locale: string; list: Requirement[] }
>();

const requirementsOf = (comp: any): Requirement[] => {
    const constraints = comp?.constraints;

    if (!constraints) {
        return [];
    }

    const cached = requirementCache.get(constraints);

    if (cached && cached.locale === locale.value) {
        return cached.list;
    }

    const list = fieldRequirements(constraints, t, fieldLabel, {
        counter: comp.type === 'text-input' || comp.type === 'textarea',
        locale: locale.value,
    });
    requirementCache.set(constraints, { locale: locale.value, list });

    return list;
};

/** Requirement parts the field's own input already shows. */
const listedByInput = (comp: any): string[] => {
    if (comp.type === 'text-input' && comp.inputType === 'password') {
        return ['password'];
    }

    return comp.type === 'json-input' ? ['format'] : [];
};

// A field is checked once the user leaves it, then live as they fix it: no
// error while it's still being typed for the first time.
const touched = ref<Record<string, true>>({});

const touch = (name: string): void => {
    if (name && !touched.value[name]) {
        touched.value = { ...touched.value, [name]: true };
    }
};

/**
 * The errors this level shows: the server's (and Precognition's) first, then
 * what the field's own rules say about a value it has left.
 */
const shownErrors = computed<Record<string, string>>(() => {
    const merged = { ...props.errors };

    for (const comp of renderSchema.value) {
        const name = comp?.name;

        if (!name || merged[name] || !touched.value[name]) {
            continue;
        }

        const violation = requirementViolation(
            requirementsOf(comp),
            props.values[name],
            props.values,
            t,
        );

        if (violation) {
            merged[name] = violation;
        }
    }

    return merged;
});

const renderSchema = computed(() =>
    props.schema
        .map((comp) => {
            if (!comp || !comp.conditions) {
                return comp;
            }

            const { visible, disabled, required } = resolve(comp, props.values);

            if (!visible) {
                return null;
            }

            // Only clone when a flag actually changes, to keep renders cheap.
            if (!disabled && required === null) {
                return comp;
            }

            return {
                ...comp,
                isDisabled: disabled || comp.isDisabled,
                isRequired: required === null ? comp.isRequired : required,
            };
        })
        .filter((comp) => comp !== null),
);
</script>

<template>
    <template v-for="(comp, index) in renderSchema" :key="index">
        <!-- Grid Layout (host wrapper measures the grid's own width) -->
        <div
            v-if="comp.type === 'grid'"
            class="kinetix-col kinetix-grid-host"
            :style="colStyle(comp)"
        >
            <div
                class="kinetix-grid gap-4 grid"
                :style="gridColumnVars(gridOf(comp))"
            >
                <KinetixFormSchema
                    :schema="comp.schema"
                    :values="values"
                    :errors="errors"
                    :parent-columns="gridOf(comp)"
                    :flat="flat"
                    @update:value="
                        (name, val) => emit('update:value', name, val)
                    "
                />
            </div>
        </div>

        <!-- Section Layout: a card by default; flat (divided group) when the
             host is already a surface, e.g. a modal panel. -->
        <div
            v-else-if="comp.type === 'section'"
            class="kinetix-col"
            :class="
                flat
                    ? '[&:not(:first-child)]:pt-4 [&:not(:first-child)]:border-t [&:not(:first-child)]:border-border'
                    : 'rounded-xl shadow-sm border border-input bg-background'
            "
            :style="colStyle(comp)"
        >
            <div
                v-if="!flat || comp.heading || comp.description"
                :class="flat ? 'pb-4' : 'p-6 pb-4 border-b border-border'"
            >
                <h3
                    class="font-semibold tracking-tight leading-none text-foreground"
                    :class="flat ? 'text-sm' : ''"
                >
                    {{ comp.heading }}
                </h3>
                <p
                    v-if="comp.description"
                    class="text-sm mt-1.5 text-muted-foreground"
                >
                    {{ comp.description }}
                </p>
            </div>
            <div class="kinetix-grid-host" :class="flat ? '' : 'p-6'">
                <div
                    class="kinetix-grid gap-4 grid"
                    :style="gridColumnVars(gridOf(comp))"
                >
                    <KinetixFormSchema
                        :schema="comp.schema"
                        :values="values"
                        :errors="errors"
                        :parent-columns="gridOf(comp)"
                        :flat="flat"
                        @update:value="
                            (name, val) => emit('update:value', name, val)
                        "
                    />
                </div>
            </div>
        </div>

        <!-- Fieldset Layout (labelled <fieldset>) -->
        <fieldset
            v-else-if="comp.type === 'fieldset'"
            class="kinetix-col kinetix-grid-host rounded-lg p-4 border border-border"
            :style="colStyle(comp)"
        >
            <legend
                v-if="comp.heading"
                class="px-1 text-sm font-medium text-foreground"
            >
                {{ comp.heading }}
            </legend>
            <div
                class="kinetix-grid gap-4 grid"
                :style="gridColumnVars(gridOf(comp))"
            >
                <KinetixFormSchema
                    :schema="comp.schema"
                    :values="values"
                    :errors="errors"
                    :parent-columns="gridOf(comp)"
                    :flat="flat"
                    @update:value="
                        (name, val) => emit('update:value', name, val)
                    "
                />
            </div>
        </fieldset>

        <!-- Tabs Layout (Reka UI) -->
        <div
            v-else-if="comp.type === 'tabs'"
            class="kinetix-col"
            :style="colStyle(comp)"
        >
            <KinetixFormTabs
                :tabs="comp.schema"
                :query-param="comp.queryParam"
                :values="values"
                :errors="errors"
                :flat="flat"
                @update:value="(name, val) => emit('update:value', name, val)"
            />
        </div>

        <!-- Wizard Layout (multi-step) -->
        <div
            v-else-if="comp.type === 'wizard'"
            class="kinetix-col"
            :style="colStyle(comp)"
        >
            <KinetixFormWizard
                :comp="comp"
                :values="values"
                :errors="errors"
                :flat="flat"
                @update:value="(name, val) => emit('update:value', name, val)"
            />
        </div>

        <!-- Split Layout (responsive flex row) -->
        <div
            v-else-if="comp.type === 'split'"
            class="kinetix-col gap-4 md:flex-row flex flex-col [&>*]:flex-1"
            :style="colStyle(comp)"
        >
            <KinetixFormSchema
                :schema="comp.schema"
                :values="values"
                :errors="errors"
                :flat="flat"
                @update:value="(name, val) => emit('update:value', name, val)"
            />
        </div>

        <!-- Placeholder (read-only display) -->
        <div
            v-else-if="comp.type === 'placeholder'"
            :style="colStyle(comp)"
            class="kinetix-col space-y-1.5 flex flex-col"
        >
            <KinetixLabel v-if="comp.label">{{ comp.label }}</KinetixLabel>
            <p class="text-sm text-muted-foreground">{{ comp.content }}</p>
        </div>

        <!-- Standard Form Fields -->
        <div
            v-else
            :style="colStyle(comp)"
            class="kinetix-col space-y-1.5 flex flex-col"
            @focusout="touch(comp.name)"
        >
            <!-- Label -->
            <KinetixLabel
                v-if="comp.type !== 'hidden' && comp.label"
                :for="comp.name"
            >
                {{ comp.label }}
            </KinetixLabel>

            <!-- Field Container -->
            <div class="relative w-full">
                <!-- Inline repeater — recurses into the schema per item. -->
                <div v-if="comp.type === 'repeater'" class="space-y-3">
                    <div
                        v-for="(item, idx) in itemsOf(comp.name)"
                        :key="idx"
                        class="rounded-lg p-4 relative border border-input bg-muted/40"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <span
                                class="text-xs font-semibold text-muted-foreground"
                            >
                                #{{ idx + 1 }}
                            </span>
                            <div class="gap-1 flex items-center">
                                <button
                                    type="button"
                                    :aria-label="t('kinetix.move_up')"
                                    class="h-7 w-7 flex items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-30"
                                    :disabled="idx === 0"
                                    @click="moveItem(comp.name, idx, -1)"
                                >
                                    <ChevronUp
                                        class="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </button>
                                <button
                                    type="button"
                                    :aria-label="t('kinetix.move_down')"
                                    class="h-7 w-7 flex items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-30"
                                    :disabled="
                                        idx === itemsOf(comp.name).length - 1
                                    "
                                    @click="moveItem(comp.name, idx, 1)"
                                >
                                    <ChevronDown
                                        class="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </button>
                                <button
                                    type="button"
                                    :aria-label="t('kinetix.remove')"
                                    class="h-7 w-7 flex items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-destructive/10 hover:text-destructive focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-30"
                                    :disabled="
                                        !!comp.minItems &&
                                        itemsOf(comp.name).length <=
                                            comp.minItems
                                    "
                                    @click="removeItem(comp.name, idx)"
                                >
                                    <Trash2
                                        class="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                        </div>

                        <div class="gap-4 grid grid-cols-1">
                            <KinetixFormSchema
                                :schema="comp.schema"
                                :values="item"
                                :errors="errors"
                                :flat="flat"
                                @update:value="
                                    (name, val) =>
                                        updateItem(comp.name, idx, name, val)
                                "
                            />
                        </div>
                    </div>

                    <button
                        type="button"
                        class="gap-1.5 px-3 py-1.5 text-xs font-medium inline-flex items-center rounded-md border border-dashed border-input text-muted-foreground transition-colors hover:bg-accent disabled:cursor-not-allowed disabled:opacity-40"
                        :disabled="
                            !!comp.maxItems &&
                            itemsOf(comp.name).length >= comp.maxItems
                        "
                        @click="addItem(comp.name, comp.schema)"
                    >
                        <Plus class="h-3.5 w-3.5" />
                        {{ comp.addActionLabel || t('kinetix.add_item') }}
                    </button>
                </div>

                <!-- Every other field type via the O(1) dispatcher map. -->
                <KinetixFormField
                    v-else
                    :comp="comp"
                    :values="values"
                    :errors="shownErrors"
                    :described-by="
                        requirementsOf(comp).some((r) => r.hint) &&
                        !shownErrors[comp.name]
                            ? `${comp.name}-rules`
                            : undefined
                    "
                    @update="(val) => emit('update:value', comp.name, val)"
                />
            </div>

            <!-- What the field's rules ask for: hints, counter, a match. -->
            <KinetixFieldRequirements
                v-if="comp.constraints"
                :name="comp.name"
                :constraints="comp.constraints"
                :requirements="requirementsOf(comp)"
                :value="values[comp.name]"
                :values="values"
                :has-error="!!shownErrors[comp.name]"
                :listed-by-input="listedByInput(comp)"
            />

            <!-- Helper text (id is an aria-describedby target, like the error). -->
            <p
                v-if="comp.description"
                :id="`${comp.name}-help`"
                class="text-xs mt-1 text-muted-foreground"
            >
                {{ comp.description }}
            </p>

            <!-- Validation Error (id is the aria-describedby target; alert
                 role announces it to screen readers as it appears). -->
            <p
                v-if="shownErrors[comp.name]"
                :id="`${comp.name}-error`"
                role="alert"
                class="text-xs font-semibold mt-1 text-destructive"
            >
                {{ shownErrors[comp.name] }}
            </p>
        </div>
    </template>
</template>
