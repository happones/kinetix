<script setup lang="ts">
import { ref } from "vue";
import { TabsRoot, TabsList, TabsTrigger, TabsContent } from "reka-ui";
import { resolveIcon } from "@/composables/useKinetixIcons";
import KinetixFormSchema from "./KinetixFormSchema.vue";

/**
 * Renders a `tabs` layout component: a Reka UI tab strip whose panels each
 * recurse back into KinetixFormSchema. Active tab state is local to this
 * instance (so multiple tab groups on one form stay independent).
 */
const props = defineProps<{
  tabs: any[];
  values: Record<string, any>;
  errors: Record<string, string>;
}>();

const emit = defineEmits<{
  (e: "update:value", name: string, value: any): void;
}>();

const active = ref("0");
</script>

<template>
  <TabsRoot v-model="active" class="w-full">
    <TabsList
      class="inline-flex h-9 items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground"
    >
      <TabsTrigger
        v-for="(tab, index) in props.tabs"
        :key="index"
        :value="String(index)"
        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-sm font-medium whitespace-nowrap transition-all focus-visible:outline-none focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm"
      >
        <component
          :is="resolveIcon(tab.icon)"
          v-if="resolveIcon(tab.icon)"
          class="size-4"
        />
        {{ tab.heading }}
      </TabsTrigger>
    </TabsList>

    <TabsContent
      v-for="(tab, index) in props.tabs"
      :key="index"
      :value="String(index)"
      class="mt-4 focus-visible:outline-none"
    >
      <div
        class="grid gap-4"
        :style="{
          gridTemplateColumns: `repeat(${tab.columns || 12}, minmax(0, 1fr))`,
        }"
      >
        <KinetixFormSchema
          :schema="tab.schema"
          :values="values"
          :errors="errors"
          @update:value="(name, val) => emit('update:value', name, val)"
        />
      </div>
    </TabsContent>
  </TabsRoot>
</template>
