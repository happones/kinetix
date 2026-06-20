<script setup lang="ts">
import KinetixCheckbox from "./KinetixCheckbox.vue";

defineProps<{
  schema: any[];
  values: Record<string, any>;
  errors: Record<string, string>;
}>();

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
      class="rounded-xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-950 shadow-sm"
      :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
    >
      <div class="p-6 pb-4 border-b border-neutral-100 dark:border-neutral-900">
        <h3
          class="font-semibold leading-none tracking-tight text-neutral-900 dark:text-white"
        >
          {{ comp.heading }}
        </h3>
        <p
          v-if="comp.description"
          class="text-sm text-neutral-500 dark:text-neutral-400 mt-1.5"
        >
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

    <!-- Standard Form Fields -->
    <div
      v-else
      :style="{ gridColumn: getColumnSpan(comp.columnSpan) }"
      class="space-y-1.5 flex flex-col"
    >
      <!-- Label -->
      <label
        v-if="comp.type !== 'hidden' && comp.label"
        :for="comp.name"
        class="text-sm font-medium leading-none text-neutral-700 dark:text-neutral-300"
      >
        {{ comp.label }}
      </label>

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
          class="h-9 w-full rounded-md border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-950 px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-neutral-400 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-neutral-950 dark:focus-visible:ring-neutral-300 disabled:cursor-not-allowed disabled:opacity-50"
          @input="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLInputElement).value,
            )
          "
        />

        <!-- Select -->
        <select
          v-else-if="comp.type === 'select'"
          :id="comp.name"
          :value="values[comp.name]"
          :disabled="comp.isDisabled"
          class="flex h-9 w-full items-center justify-between rounded-md border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-950 px-3 py-2 text-sm shadow-sm placeholder:text-neutral-400 focus:outline-none focus:ring-1 focus:ring-neutral-950 dark:focus:ring-neutral-300 disabled:cursor-not-allowed disabled:opacity-50"
          @change="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLSelectElement).value,
            )
          "
        >
          <option v-for="(lbl, val) in comp.options" :key="val" :value="val">
            {{ lbl }}
          </option>
        </select>

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

        <!-- Toggle Switch -->
        <div v-else-if="comp.type === 'toggle'" class="flex items-center py-1">
          <button
            type="button"
            role="switch"
            :aria-checked="!!values[comp.name]"
            :disabled="comp.isDisabled"
            class="peer inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-950 dark:focus-visible:ring-neutral-300 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-950 disabled:cursor-not-allowed disabled:opacity-50"
            :class="
              values[comp.name]
                ? 'bg-neutral-900 dark:bg-neutral-50'
                : 'bg-neutral-200 dark:bg-neutral-800'
            "
            @click="
              if (!comp.isDisabled) {
                emit('update:value', comp.name, !values[comp.name]);
              }
            "
          >
            <span
              class="pointer-events-none block h-4 w-4 rounded-full bg-white dark:bg-neutral-900 shadow-lg ring-0 transition-transform"
              :class="values[comp.name] ? 'translate-x-4' : 'translate-x-0'"
            />
          </button>
        </div>

        <!-- Textarea -->
        <textarea
          v-else-if="comp.type === 'textarea'"
          :id="comp.name"
          :value="values[comp.name]"
          :placeholder="comp.placeholder"
          :disabled="comp.isDisabled"
          v-bind="comp.extraInputAttributes"
          class="flex min-h-[80px] w-full rounded-md border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-950 px-3 py-2 text-sm shadow-sm placeholder:text-neutral-400 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-neutral-950 dark:focus-visible:ring-neutral-300 disabled:cursor-not-allowed disabled:opacity-50"
          @input="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLTextAreaElement).value,
            )
          "
        />

        <!-- DateTime Picker -->
        <input
          v-else-if="comp.type === 'datetime-picker'"
          :id="comp.name"
          :value="values[comp.name]"
          type="datetime-local"
          :disabled="comp.isDisabled"
          class="h-9 w-full rounded-md border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-950 px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-neutral-950 dark:focus-visible:ring-neutral-300 disabled:cursor-not-allowed disabled:opacity-50"
          @input="
            emit(
              'update:value',
              comp.name,
              ($event.target as HTMLInputElement).value,
            )
          "
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
        class="text-xs font-semibold text-red-500 dark:text-red-900 mt-1"
      >
        {{ errors[comp.name] }}
      </p>
    </div>
  </template>
</template>
