<script setup lang="ts">
import { Plus, Trash2, ChevronUp, ChevronDown } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { useKinetixRepeater } from '@/composables/useKinetixRepeaterField';
import KinetixFormField from './Form/KinetixFormField.vue';
import KinetixFormTabs from './KinetixFormTabs.vue';
import KinetixFormWizard from './KinetixFormWizard.vue';
import KinetixLabel from './KinetixLabel.vue';

const props = defineProps<{
    schema: any[];
    values: Record<string, any>;
    errors: Record<string, string>;
}>();

const { t } = useI18n();

const emit = defineEmits<{
    (e: 'update:value', name: string, value: any): void;
}>();

const getColumnSpan = (span: any) => {
    if (span === 'full') {
        return '1 / -1';
    }

    if (typeof span === 'number') {
        return `span ${span} / span ${span}`;
    }

    return undefined;
};

// Inline-repeater add/remove/reorder/update, keyed by field name.
const { itemsOf, addItem, removeItem, moveItem, updateItem } =
    useKinetixRepeater({
        values: () => props.values,
        emit: (name, value) => emit('update:value', name, value),
    });
</script>

<template>
    <template v-for="(comp, index) in schema" :key="index">
        <!-- Grid Layout -->
        <div
            v-if="comp.type === 'grid'"
            class="gap-4 grid"
            :style="{
                gridTemplateColumns: `repeat(${comp.columns || 1}, minmax(0, 1fr))`,
                gridColumn: getColumnSpan(comp.columnSpan),
            }"
        >
            <KinetixFormSchema
                :schema="comp.schema"
                :values="values"
                :errors="errors"
                @update:value="(name, val) => emit('update:value', name, val)"
            />
        </div>

        <!-- Section Card Layout -->
        <div
            v-else-if="comp.type === 'section'"
            class="rounded-xl shadow-sm border border-input bg-background"
            :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
        >
            <div class="p-6 pb-4 border-b border-border">
                <h3
                    class="font-semibold tracking-tight leading-none text-foreground"
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
            <div class="p-6">
                <div
                    class="gap-4 grid"
                    :style="{
                        gridTemplateColumns: `repeat(${comp.columns || 1}, minmax(0, 1fr))`,
                    }"
                >
                    <KinetixFormSchema
                        :schema="comp.schema"
                        :values="values"
                        :errors="errors"
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
            class="rounded-lg p-4 border border-border"
            :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
        >
            <legend
                v-if="comp.heading"
                class="px-1 text-sm font-medium text-foreground"
            >
                {{ comp.heading }}
            </legend>
            <div
                class="gap-4 grid"
                :style="{
                    gridTemplateColumns: `repeat(${comp.columns || 1}, minmax(0, 1fr))`,
                }"
            >
                <KinetixFormSchema
                    :schema="comp.schema"
                    :values="values"
                    :errors="errors"
                    @update:value="
                        (name, val) => emit('update:value', name, val)
                    "
                />
            </div>
        </fieldset>

        <!-- Tabs Layout (Reka UI) -->
        <div
            v-else-if="comp.type === 'tabs'"
            :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
        >
            <KinetixFormTabs
                :tabs="comp.schema"
                :values="values"
                :errors="errors"
                @update:value="(name, val) => emit('update:value', name, val)"
            />
        </div>

        <!-- Wizard Layout (multi-step) -->
        <div
            v-else-if="comp.type === 'wizard'"
            :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
        >
            <KinetixFormWizard
                :comp="comp"
                :values="values"
                :errors="errors"
                @update:value="(name, val) => emit('update:value', name, val)"
            />
        </div>

        <!-- Split Layout (responsive flex row) -->
        <div
            v-else-if="comp.type === 'split'"
            class="gap-4 md:flex-row flex flex-col [&>*]:flex-1"
            :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
        >
            <KinetixFormSchema
                :schema="comp.schema"
                :values="values"
                :errors="errors"
                @update:value="(name, val) => emit('update:value', name, val)"
            />
        </div>

        <!-- Placeholder (read-only display) -->
        <div
            v-else-if="comp.type === 'placeholder'"
            :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
            class="space-y-1.5 flex flex-col"
        >
            <KinetixLabel v-if="comp.label">{{ comp.label }}</KinetixLabel>
            <p class="text-sm text-muted-foreground">{{ comp.content }}</p>
        </div>

        <!-- Standard Form Fields -->
        <div
            v-else
            :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
            class="space-y-1.5 flex flex-col"
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
                                    class="h-7 w-7 flex items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"
                                    :disabled="idx === 0"
                                    @click="moveItem(comp.name, idx, -1)"
                                >
                                    <ChevronUp class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="h-7 w-7 flex items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"
                                    :disabled="
                                        idx === itemsOf(comp.name).length - 1
                                    "
                                    @click="moveItem(comp.name, idx, 1)"
                                >
                                    <ChevronDown class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="h-7 w-7 flex items-center justify-center rounded-md text-muted-foreground hover:bg-destructive/10 hover:text-destructive disabled:opacity-30"
                                    :disabled="
                                        !!comp.minItems &&
                                        itemsOf(comp.name).length <=
                                            comp.minItems
                                    "
                                    @click="removeItem(comp.name, idx)"
                                >
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>

                        <div class="gap-4 grid grid-cols-1">
                            <KinetixFormSchema
                                :schema="comp.schema"
                                :values="item"
                                :errors="errors"
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
                    :errors="errors"
                    @update="(val) => emit('update:value', comp.name, val)"
                />
            </div>

            <!-- Validation Error (id is the aria-describedby target; alert
                 role announces it to screen readers as it appears). -->
            <p
                v-if="errors[comp.name]"
                :id="`${comp.name}-error`"
                role="alert"
                class="text-xs font-semibold mt-1 text-destructive"
            >
                {{ errors[comp.name] }}
            </p>
        </div>
    </template>
</template>
