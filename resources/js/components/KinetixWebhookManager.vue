<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useKinetixWebhooks } from "@/composables/useKinetixWebhooks";
import { buttonVariants, inputClass } from "@/composables/useShadcnVariants";
import {
  statusBadgeClass,
  type KinetixStatusColor,
} from "@/composables/useStatusColor";
import type { KinetixWebhookEndpoint, KinetixWebhookLog } from "@/types";
import KinetixCheckbox from "./KinetixCheckbox.vue";
import KinetixLabel from "./KinetixLabel.vue";

/**
 * Drop-in customer webhook dashboard: register/edit endpoints, pick subscribed
 * events, rotate the secret, send a test event, and inspect delivery logs. Gate
 * it behind the `webhooks.manage` ability where you mount it.
 */
const { t } = useI18n();
const {
  endpoints,
  availableEvents,
  load,
  create,
  update,
  remove,
  rotate,
  test,
  logs,
} = useKinetixWebhooks();

const editing = ref<KinetixWebhookEndpoint | null>(null);
const openLogsFor = ref<string | number | null>(null);
const logEntries = ref<KinetixWebhookLog[]>([]);

onMounted(load);

function blank(): KinetixWebhookEndpoint {
  return {
    id: null,
    name: "",
    url: "",
    events: [],
    active: true,
    createdAt: null,
  };
}

function startCreate(): void {
  editing.value = blank();
}

function edit(endpoint: KinetixWebhookEndpoint): void {
  editing.value = { ...endpoint, events: [...endpoint.events] };
}

function toggleEvent(name: string, checked: boolean): void {
  if (!editing.value) {
    return;
  }
  editing.value.events = checked
    ? [...editing.value.events, name]
    : editing.value.events.filter((e) => e !== name);
}

async function save(): Promise<void> {
  if (!editing.value) {
    return;
  }

  const payload = {
    name: editing.value.name,
    url: editing.value.url,
    events: editing.value.events,
    active: editing.value.active,
  };

  try {
    if (editing.value.id) {
      await update(editing.value, payload);
      toast.success(t("kinetix.saved"));
    } else {
      const result = await create(payload);
      if (result?.secret) {
        toast.success(
          t("kinetix.webhook_secret_shown", { secret: result.secret }),
        );
      }
    }
    editing.value = null;
    await load();
  } catch (error) {
    toast.error(
      error instanceof Error ? error.message : t("kinetix.save_failed"),
    );
  }
}

async function onDelete(endpoint: KinetixWebhookEndpoint): Promise<void> {
  try {
    await remove(endpoint);
    await load();
    toast.success(t("kinetix.deleted"));
  } catch {
    toast.error(t("kinetix.delete_failed"));
  }
}

async function onRotate(endpoint: KinetixWebhookEndpoint): Promise<void> {
  const result = await rotate(endpoint);
  if (result?.secret) {
    toast.success(t("kinetix.webhook_secret_shown", { secret: result.secret }));
  }
}

async function onTest(endpoint: KinetixWebhookEndpoint): Promise<void> {
  await test(endpoint);
  toast.success(t("kinetix.webhook_test_queued"));
}

async function viewLogs(endpoint: KinetixWebhookEndpoint): Promise<void> {
  if (openLogsFor.value === endpoint.id) {
    openLogsFor.value = null;
    return;
  }
  openLogsFor.value = endpoint.id;
  logEntries.value = await logs(endpoint);
}

const logColor = (success: boolean): KinetixStatusColor =>
  success ? "success" : "danger";
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-foreground">
        {{ t("kinetix.webhooks_title") }}
      </h2>
      <button
        v-if="!editing"
        type="button"
        :class="buttonVariants({ size: 'sm' })"
        @click="startCreate"
      >
        {{ t("kinetix.webhook_add") }}
      </button>
    </div>

    <!-- Editor -->
    <div
      v-if="editing"
      class="space-y-3 rounded-lg border border-border bg-card p-4"
    >
      <div class="space-y-2">
        <KinetixLabel for="wh-name">{{
          t("kinetix.webhook_name")
        }}</KinetixLabel>
        <input
          id="wh-name"
          v-model="editing.name"
          type="text"
          :class="inputClass"
        />
      </div>
      <div class="space-y-2">
        <KinetixLabel for="wh-url">{{ t("kinetix.webhook_url") }}</KinetixLabel>
        <input
          id="wh-url"
          v-model="editing.url"
          type="url"
          placeholder="https://"
          :class="inputClass"
        />
      </div>

      <div class="space-y-2">
        <KinetixLabel>{{ t("kinetix.webhook_events") }}</KinetixLabel>
        <label
          v-for="(label, name) in availableEvents"
          :key="name"
          class="flex items-center gap-2 text-sm text-foreground"
        >
          <KinetixCheckbox
            :checked="editing.events.includes(String(name))"
            @change="toggleEvent(String(name), $event)"
          />
          <span
            >{{ label }}
            <span class="text-muted-foreground">({{ name }})</span></span
          >
        </label>
      </div>

      <label class="flex items-center gap-2 text-sm text-foreground">
        <KinetixCheckbox
          :checked="editing.active"
          @change="editing.active = $event"
        />
        {{ t("kinetix.webhook_active") }}
      </label>

      <div class="flex justify-end gap-2">
        <button
          type="button"
          :class="buttonVariants({ variant: 'outline', size: 'sm' })"
          @click="editing = null"
        >
          {{ t("kinetix.cancel") }}
        </button>
        <button
          type="button"
          :class="buttonVariants({ size: 'sm' })"
          @click="save"
        >
          {{ t("kinetix.save") }}
        </button>
      </div>
    </div>

    <!-- List -->
    <div
      v-else
      class="divide-y divide-border rounded-lg border border-border bg-card"
    >
      <p
        v-if="endpoints.length === 0"
        class="p-4 text-sm text-muted-foreground"
      >
        {{ t("kinetix.no_webhooks") }}
      </p>

      <div v-for="endpoint in endpoints" :key="String(endpoint.id)" class="p-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="min-w-0">
            <span class="text-sm font-medium text-foreground">{{
              endpoint.name
            }}</span>
            <span class="ml-2 truncate text-xs text-muted-foreground">{{
              endpoint.url
            }}</span>
          </div>
          <div class="flex items-center gap-2">
            <span
              v-if="!endpoint.active"
              class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
            >
              {{ t("kinetix.webhook_inactive") }}
            </span>
            <button
              :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
              @click="onTest(endpoint)"
            >
              {{ t("kinetix.webhook_test") }}
            </button>
            <button
              :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
              @click="onRotate(endpoint)"
            >
              {{ t("kinetix.webhook_rotate") }}
            </button>
            <button
              :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
              @click="viewLogs(endpoint)"
            >
              {{ t("kinetix.webhook_logs") }}
            </button>
            <button
              :class="buttonVariants({ variant: 'outline', size: 'sm' })"
              @click="edit(endpoint)"
            >
              {{ t("kinetix.edit") }}
            </button>
            <button
              :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
              @click="onDelete(endpoint)"
            >
              {{ t("kinetix.delete") }}
            </button>
          </div>
        </div>

        <!-- Logs -->
        <div v-if="openLogsFor === endpoint.id" class="mt-3 space-y-1">
          <p
            v-if="logEntries.length === 0"
            class="text-xs text-muted-foreground"
          >
            {{ t("kinetix.activity_empty") }}
          </p>
          <div
            v-for="log in logEntries"
            :key="String(log.id)"
            class="flex items-center justify-between gap-2 text-xs"
          >
            <span class="font-mono text-foreground">{{ log.event }}</span>
            <span
              class="inline-flex items-center rounded-full px-2 py-0.5 font-semibold"
              :class="statusBadgeClass(logColor(log.success))"
            >
              {{ log.statusCode ?? "—" }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
