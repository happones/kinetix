<script setup lang="ts">
import { Plus, Trash2, ChevronUp, ChevronDown } from "@lucide/vue";
import { SwitchRoot, SwitchThumb } from "reka-ui";
import { useI18n } from "vue-i18n";
import KinetixCheckbox from "./KinetixCheckbox.vue";
import KinetixCombobox from "./KinetixCombobox.vue";
import KinetixFileUpload from "./KinetixFileUpload.vue";
import KinetixFormTabs from "./KinetixFormTabs.vue";
import KinetixFormWizard from "./KinetixFormWizard.vue";
import KinetixKeyValue from "./KinetixKeyValue.vue";
import KinetixLabel from "./KinetixLabel.vue";
import KinetixRadioGroup from "./KinetixRadioGroup.vue";
import KinetixSelect from "./KinetixSelect.vue";
import KinetixTagsInput from "./KinetixTagsInput.vue";
import KinetixDatePicker from "./KinetixDatePicker.vue";
import KinetixDateTimePicker from "./KinetixDateTimePicker.vue";
import KinetixTimePicker from "./KinetixTimePicker.vue";
import KinetixMonthPicker from "./KinetixMonthPicker.vue";
import KinetixYearPicker from "./KinetixYearPicker.vue";
import KinetixWeekPicker from "./KinetixWeekPicker.vue";
import KinetixDateRangePicker from "./KinetixDateRangePicker.vue";
import KinetixAddressPicker from "./KinetixAddressPicker.vue";
import KinetixRichEditor from "./KinetixRichEditor.vue";
import KinetixNumberField from "./KinetixNumberField.vue";
import { cn } from "./primitives/cn";
import { inputClass, textareaClass } from "@/composables/useShadcnVariants";

const props = defineProps<{
  schema: any[];
  values: Record<string, any>;
  errors: Record<string, string>;
}>();

const { t } = useI18n();

const emit = defineEmits<{
  (e: "update:value", name: string, value: any): void;
}>();

const getColumnSpan = (span: any) => {
  if (span === "full") {
    return "1 / -1";
  }

  if (typeof span === "number") {
    return `span ${span} / span ${span}`;
  }

  return undefined;
};

// Toggle a value inside a multi-select array (used by checkbox-list fields).
const toggleArrayValue = (
  current: any,
  optionValue: string,
  checked: boolean,
) => {
  const next = Array.isArray(current) ? [...current] : [];
  const index = next.indexOf(optionValue);

  if (checked && index === -1) {
    next.push(optionValue);
  }

  if (!checked && index !== -1) {
    next.splice(index, 1);
  }

  return next;
};

const isInArray = (current: any, optionValue: string) =>
  Array.isArray(current) && current.includes(optionValue);

// --- Repeater helpers ---------------------------------------------------------
// Build a blank item from the sub-schema's field defaults (recursing layouts).
const buildBlankItem = (schema: any[]) => {
  const item: Record<string, any> = {};

  const walk = (nodes: any[]) => {
    for (const node of nodes) {
      if (Array.isArray(node.schema)) {
        walk(node.schema);
        continue;
      }

      if (node.name) {
        item[node.name] = node.defaultValue ?? null;
      }
    }
  };

  walk(schema);

  return item;
};

const repeaterItems = (name: string): Record<string, any>[] =>
  Array.isArray(props.values[name]) ? props.values[name] : [];

const updateRepeaterItem = (
  name: string,
  index: number,
  fieldName: string,
  value: any,
) => {
  const next = [...repeaterItems(name)];
  next[index] = { ...next[index], [fieldName]: value };
  emit("update:value", name, next);
};

const addRepeaterItem = (name: string, schema: any[]) => {
  emit("update:value", name, [...repeaterItems(name), buildBlankItem(schema)]);
};

const removeRepeaterItem = (name: string, index: number) => {
  const next = [...repeaterItems(name)];
  next.splice(index, 1);
  emit("update:value", name, next);
};

const moveRepeaterItem = (name: string, index: number, direction: number) => {
  const next = [...repeaterItems(name)];
  const target = index + direction;

  if (target < 0 || target >= next.length) {
    return;
  }

  [next[index], next[target]] = [next[target], next[index]];
  emit("update:value", name, next);
};
</script>

<template>
  <template v-for="(comp, index) in schema" :key="index">
    <!-- Grid Layout -->
    <div
      v-if="comp.type === 'grid'"
      class="grid gap-4"
      :style="{
        gridTemplateColumns: `repeat(${comp.columns || 12}, minmax(0, 1fr))`,
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
      class="rounded-xl border border-input bg-background shadow-sm"
      :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
    >
      <div class="p-6 pb-4 border-b border-border">
        <h3 class="font-semibold leading-none tracking-tight text-foreground">
          {{ comp.heading }}
        </h3>
        <p v-if="comp.description" class="text-sm text-muted-foreground mt-1.5">
          {{ comp.description }}
        </p>
      </div>
      <div class="p-6">
        <div
          class="grid gap-4"
          :style="{
            gridTemplateColumns: `repeat(${comp.columns || 12}, minmax(0, 1fr))`,
          }"
        >
          <KinetixFormSchema
            :schema="comp.schema"
            :values="values"
            :errors="errors"
            @update:value="(name, val) => emit('update:value', name, val)"
          />
        </div>
      </div>
    </div>

    <!-- Fieldset Layout (labelled <fieldset>) -->
    <fieldset
      v-else-if="comp.type === 'fieldset'"
      class="rounded-lg border border-border p-4"
      :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
    >
      <legend
        v-if="comp.heading"
        class="px-1 text-sm font-medium text-foreground"
      >
        {{ comp.heading }}
      </legend>
      <div
        class="grid gap-4"
        :style="{
          gridTemplateColumns: `repeat(${comp.columns || 12}, minmax(0, 1fr))`,
        }"
      >
        <KinetixFormSchema
          :schema="comp.schema"
          :values="values"
          :errors="errors"
          @update:value="(name, val) => emit('update:value', name, val)"
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
      class="flex flex-col gap-4 md:flex-row [&>*]:flex-1"
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
      class="flex flex-col space-y-1.5"
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
        <!-- TextInput -->
        <input
          v-if="comp.type === 'text-input'"
          :id="comp.name"
          :value="values[comp.name]"
          :type="comp.inputType || 'text'"
          :placeholder="comp.placeholder"
          :disabled="comp.isDisabled"
          :class="inputClass"
          @input="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLInputElement).value,
            )
          "
        />

        <!-- Searchable Select → Combobox (Reka UI), local or remote -->
        <KinetixCombobox
          v-else-if="comp.type === 'select' && comp.isSearchable"
          :id="comp.name"
          :value="values[comp.name]"
          :options="comp.options"
          :placeholder="comp.placeholder"
          :disabled="comp.isDisabled"
          :search-token="comp.searchToken"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Select (Reka UI) -->
        <KinetixSelect
          v-else-if="comp.type === 'select'"
          :id="comp.name"
          :value="values[comp.name]"
          :options="comp.options"
          :placeholder="comp.placeholder"
          :disabled="comp.isDisabled"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Checkbox -->
        <div
          v-else-if="comp.type === 'checkbox'"
          class="flex items-center space-x-2 py-1"
        >
          <KinetixCheckbox
            :id="comp.name"
            :checked="!!values[comp.name]"
            :disabled="comp.isDisabled"
            @change="emit('update:value', comp.name, $event)"
          />
        </div>

        <!-- Toggle Switch (Reka UI) -->
        <div v-else-if="comp.type === 'toggle'" class="flex items-center py-1">
          <SwitchRoot
            :model-value="!!values[comp.name]"
            :disabled="comp.isDisabled"
            class="peer inline-flex h-[1.15rem] w-8 shrink-0 cursor-pointer items-center rounded-full border border-transparent shadow-xs transition-all outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-primary data-[state=unchecked]:bg-input dark:data-[state=unchecked]:bg-input/80"
            @update:model-value="emit('update:value', comp.name, $event)"
          >
            <SwitchThumb
              class="pointer-events-none block size-4 rounded-full bg-background ring-0 transition-transform data-[state=checked]:translate-x-[calc(100%-2px)] data-[state=unchecked]:translate-x-0 dark:data-[state=unchecked]:bg-foreground dark:data-[state=checked]:bg-primary-foreground"
            />
          </SwitchRoot>
        </div>

        <!-- Textarea -->
        <textarea
          v-else-if="comp.type === 'textarea'"
          :id="comp.name"
          :value="values[comp.name]"
          :placeholder="comp.placeholder"
          :disabled="comp.isDisabled"
          v-bind="comp.extraInputAttributes"
          :class="textareaClass"
          @input="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLTextAreaElement).value,
            )
          "
        />

        <!-- Date Picker (shadcn calendar by default, native when ->native()) -->
        <KinetixDatePicker
          v-else-if="comp.type === 'date-picker' && comp.useCalendar"
          :value="values[comp.name]"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          :locale="comp.dateLocale"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />
        <input
          v-else-if="comp.type === 'date-picker'"
          :id="comp.name"
          :value="values[comp.name]"
          type="date"
          :disabled="comp.isDisabled"
          :class="inputClass"
          @input="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLInputElement).value,
            )
          "
        />

        <!-- DateTime Picker (shadcn by default, native when ->native()) -->
        <KinetixDateTimePicker
          v-else-if="comp.type === 'datetime-picker' && comp.useCalendar"
          :value="values[comp.name]"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          :locale="comp.dateLocale"
          :minute-step="comp.minuteStep"
          :hour12="comp.hour12"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />
        <input
          v-else-if="comp.type === 'datetime-picker'"
          :id="comp.name"
          :value="values[comp.name]"
          type="datetime-local"
          :disabled="comp.isDisabled"
          :class="inputClass"
          @input="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLInputElement).value,
            )
          "
        />

        <!-- Time Picker (shadcn columns by default, native when ->native()) -->
        <KinetixTimePicker
          v-else-if="comp.type === 'time-picker' && comp.useCalendar"
          :value="values[comp.name]"
          :disabled="comp.isDisabled"
          :minute-step="comp.minuteStep"
          :hour12="comp.hour12"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />
        <input
          v-else-if="comp.type === 'time-picker'"
          :id="comp.name"
          :value="values[comp.name]"
          type="time"
          :disabled="comp.isDisabled"
          :class="inputClass"
          @input="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLInputElement).value,
            )
          "
        />

        <!-- Month / Year / Week pickers (shadcn or native via ->native()) -->
        <KinetixMonthPicker
          v-else-if="comp.type === 'month-picker'"
          :value="values[comp.name]"
          :native="!comp.useCalendar"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          :locale="comp.dateLocale"
          :min-value="comp.minValue"
          :max-value="comp.maxValue"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />
        <KinetixYearPicker
          v-else-if="comp.type === 'year-picker'"
          :value="values[comp.name]"
          :native="!comp.useCalendar"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          :min-value="comp.minValue"
          :max-value="comp.maxValue"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />
        <KinetixWeekPicker
          v-else-if="comp.type === 'week-picker'"
          :value="values[comp.name]"
          :native="!comp.useCalendar"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          :locale="comp.dateLocale"
          :week-starts-on="comp.weekStartsOn ?? 1"
          :min-value="comp.minValue"
          :max-value="comp.maxValue"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Number field (Reka NumberField with steppers + Intl formatting) -->
        <KinetixNumberField
          v-else-if="comp.type === 'number-field'"
          :value="values[comp.name]"
          :config="comp.numberConfig"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Rich text / WYSIWYG (basic | tiptap | markdown) -->
        <KinetixRichEditor
          v-else-if="comp.type === 'rich-editor'"
          :value="values[comp.name]"
          :editor="comp.editor"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Address (structured: lines, city, state, postal, country) -->
        <KinetixAddressPicker
          v-else-if="comp.type === 'address-picker'"
          :value="values[comp.name]"
          :fields="comp.addressFields"
          :countries="comp.options"
          :disabled="comp.isDisabled"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Date range (shadcn range calendar or two native inputs) -->
        <KinetixDateRangePicker
          v-else-if="comp.type === 'date-range-picker'"
          :value="values[comp.name]"
          :native="!comp.useCalendar"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          :locale="comp.dateLocale"
          :weekday-format="comp.weekdayFormat"
          :number-of-months="comp.numberOfMonths"
          :fixed-weeks="comp.fixedWeeks"
          :min-value="comp.minValue"
          :max-value="comp.maxValue"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Radio Group (Reka UI) -->
        <KinetixRadioGroup
          v-else-if="comp.type === 'radio'"
          :value="values[comp.name]"
          :options="comp.options"
          :inline="comp.isInline"
          :disabled="comp.isDisabled"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Checkbox List -->
        <div
          v-else-if="comp.type === 'checkbox-list'"
          class="gap-2"
          :class="
            comp.isInline
              ? 'flex flex-wrap items-center gap-4'
              : 'flex flex-col'
          "
        >
          <label
            v-for="(lbl, val) in comp.options"
            :key="val"
            class="flex items-center gap-2 text-sm text-foreground"
            :class="
              comp.isDisabled
                ? 'cursor-not-allowed opacity-50'
                : 'cursor-pointer'
            "
          >
            <KinetixCheckbox
              :checked="isInArray(values[comp.name], String(val))"
              :disabled="comp.isDisabled"
              @change="
                emit(
                  'update:value',
                  comp.name,
                  toggleArrayValue(values[comp.name], String(val), $event),
                )
              "
            />
            {{ lbl }}
          </label>
        </div>

        <!-- Color Picker -->
        <div
          v-else-if="comp.type === 'color-picker'"
          class="flex items-center gap-2"
        >
          <input
            type="color"
            :value="values[comp.name] || '#000000'"
            :disabled="comp.isDisabled"
            class="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-input bg-background p-1 disabled:cursor-not-allowed disabled:opacity-50"
            @input="
              emit(
                'update:value',
                comp.name,
                ($event.target as HTMLInputElement).value,
              )
            "
          />
          <input
            :id="comp.name"
            :value="values[comp.name]"
            type="text"
            :placeholder="comp.placeholder || '#000000'"
            :disabled="comp.isDisabled"
            :class="cn(inputClass, 'font-mono')"
            @input="
              emit(
                'update:value',
                comp.name,
                ($event.target as HTMLInputElement).value,
              )
            "
          />
        </div>

        <!-- Repeater -->
        <div v-else-if="comp.type === 'repeater'" class="space-y-3">
          <div
            v-for="(item, idx) in values[comp.name] || []"
            :key="idx"
            class="relative rounded-lg border border-input bg-muted/40 p-4"
          >
            <div class="mb-3 flex items-center justify-between">
              <span class="text-xs font-semibold text-muted-foreground">
                #{{ idx + 1 }}
              </span>
              <div class="flex items-center gap-1">
                <button
                  type="button"
                  class="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"
                  :disabled="idx === 0"
                  @click="moveRepeaterItem(comp.name, idx, -1)"
                >
                  <ChevronUp class="h-4 w-4" />
                </button>
                <button
                  type="button"
                  class="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent disabled:opacity-30"
                  :disabled="idx === (values[comp.name] || []).length - 1"
                  @click="moveRepeaterItem(comp.name, idx, 1)"
                >
                  <ChevronDown class="h-4 w-4" />
                </button>
                <button
                  type="button"
                  class="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:text-destructive hover:bg-destructive/10 disabled:opacity-30"
                  :disabled="
                    !!comp.minItems &&
                    (values[comp.name] || []).length <= comp.minItems
                  "
                  @click="removeRepeaterItem(comp.name, idx)"
                >
                  <Trash2 class="h-4 w-4" />
                </button>
              </div>
            </div>

            <div class="grid grid-cols-12 gap-4">
              <KinetixFormSchema
                :schema="comp.schema"
                :values="item"
                :errors="errors"
                @update:value="
                  (name, val) => updateRepeaterItem(comp.name, idx, name, val)
                "
              />
            </div>
          </div>

          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-dashed border-input px-3 py-1.5 text-xs font-medium text-muted-foreground hover:bg-accent disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
            :disabled="
              !!comp.maxItems &&
              (values[comp.name] || []).length >= comp.maxItems
            "
            @click="addRepeaterItem(comp.name, comp.schema)"
          >
            <Plus class="h-3.5 w-3.5" />
            {{ comp.addActionLabel || t("kinetix.add_item") }}
          </button>
        </div>

        <!-- Tags Input -->
        <KinetixTagsInput
          v-else-if="comp.type === 'tags-input'"
          :value="values[comp.name]"
          :disabled="comp.isDisabled"
          :placeholder="comp.placeholder"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Key-Value -->
        <KinetixKeyValue
          v-else-if="comp.type === 'key-value'"
          :value="values[comp.name]"
          :disabled="comp.isDisabled"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- File Upload -->
        <KinetixFileUpload
          v-else-if="comp.type === 'file-upload'"
          :value="values[comp.name]"
          :upload-token="comp.uploadToken"
          :is-multiple="comp.isMultiple"
          :accepted-file-types="comp.acceptedFileTypes"
          :max-size="comp.maxSize"
          :is-image="comp.isImage"
          :max-files="comp.maxFiles"
          :disabled="comp.isDisabled"
          @update:value="(v) => emit('update:value', comp.name, v)"
        />

        <!-- Hidden Field -->
        <input
          v-else-if="comp.type === 'hidden'"
          :value="values[comp.name]"
          type="hidden"
        />
      </div>

      <!-- Validation Error -->
      <p
        v-if="errors[comp.name]"
        class="text-xs font-semibold text-destructive mt-1"
      >
        {{ errors[comp.name] }}
      </p>
    </div>
  </template>
</template>
