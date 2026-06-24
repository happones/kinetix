<script setup lang="ts">
import { usePage } from "@inertiajs/vue3";
import { UploadCloud, X, FileText, Loader2 } from "@lucide/vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { kinetixFetch } from "@/composables/useKinetixHttp";

const props = withDefaults(
  defineProps<{
    value?: string | string[] | null;
    uploadToken: string;
    isMultiple?: boolean;
    acceptedFileTypes?: string[] | null;
    maxSize?: number | null;
    isImage?: boolean;
    maxFiles?: number | null;
    disabled?: boolean;
    routePrefix?: string | null;
  }>(),
  {
    value: null,
    isMultiple: false,
    acceptedFileTypes: null,
    maxSize: null,
    isImage: false,
    maxFiles: null,
    disabled: false,
    routePrefix: null,
  },
);

const emit = defineEmits<{
  (e: "update:value", value: string | string[] | null): void;
}>();

const { t } = useI18n();
const page = usePage();

const prefix = computed(
  () =>
    (page.props.kinetix_config as any)?.route_prefix ??
    props.routePrefix ??
    "_kinetix",
);

const inputRef = ref<HTMLInputElement | null>(null);
const uploading = ref(false);
const errorMessage = ref<string | null>(null);

const paths = (): string[] => {
  if (Array.isArray(props.value)) {
    return props.value;
  }

  return props.value ? [props.value] : [];
};

const acceptAttr = computed(() => {
  if (props.acceptedFileTypes && props.acceptedFileTypes.length > 0) {
    return props.acceptedFileTypes.join(",");
  }

  return props.isImage ? "image/*" : undefined;
});

const canAddMore = computed(() => {
  if (props.disabled) {
    return false;
  }

  if (!props.isMultiple) {
    return paths().length === 0;
  }

  return !props.maxFiles || paths().length < props.maxFiles;
});

const previewUrl = (path: string): string => {
  if (/^https?:\/\//.test(path) || path.startsWith("/")) {
    return path;
  }

  return `/storage/${path}`;
};

const basename = (path: string): string => path.split("/").pop() ?? path;

const uploadOne = async (file: File): Promise<string | null> => {
  const body = new FormData();
  body.append("file", file);
  body.append("token", props.uploadToken);

  const result = await kinetixFetch<{ path?: string }>(
    `/${prefix.value}/uploads/store`,
    { method: "POST", body },
  );

  return result?.path ?? null;
};

const onSelect = async (event: Event) => {
  const input = event.target as HTMLInputElement;
  const files = input.files ? Array.from(input.files) : [];

  if (files.length === 0) {
    return;
  }

  uploading.value = true;
  errorMessage.value = null;
  const current = [...paths()];

  try {
    for (const file of files) {
      if (
        props.isMultiple &&
        props.maxFiles &&
        current.length >= props.maxFiles
      ) {
        break;
      }

      const path = await uploadOne(file);

      if (!path) {
        continue;
      }

      if (props.isMultiple) {
        current.push(path);
        emit("update:value", [...current]);
        continue;
      }

      emit("update:value", path);
      break;
    }
  } catch (error: any) {
    errorMessage.value = error?.message ?? t("kinetix.upload_failed");
  } finally {
    uploading.value = false;

    if (inputRef.value) {
      inputRef.value.value = "";
    }
  }
};

const remove = async (path: string) => {
  errorMessage.value = null;

  try {
    await kinetixFetch(`/${prefix.value}/uploads/delete`, {
      method: "POST",
      body: { path, token: props.uploadToken },
    });
  } catch {
    // Even if the server-side delete fails, drop it from the field value.
  }

  const next = paths().filter((p) => p !== path);
  emit("update:value", props.isMultiple ? next : (next[0] ?? null));
};
</script>

<template>
  <div class="space-y-3">
    <!-- Dropzone / picker -->
    <label
      v-if="canAddMore"
      class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-input p-5 text-center transition-colors hover:border-ring"
      :class="disabled ? 'pointer-events-none opacity-50' : ''"
    >
      <Loader2
        v-if="uploading"
        class="h-6 w-6 animate-spin text-muted-foreground"
      />
      <UploadCloud v-else class="h-6 w-6 text-muted-foreground" />
      <span class="text-sm text-muted-foreground">
        {{ uploading ? t("kinetix.uploading") : t("kinetix.choose_file") }}
      </span>
      <input
        ref="inputRef"
        type="file"
        class="hidden"
        :accept="acceptAttr"
        :multiple="isMultiple"
        :disabled="disabled || uploading"
        @change="onSelect"
      />
    </label>

    <p v-if="errorMessage" class="text-xs font-semibold text-destructive">
      {{ errorMessage }}
    </p>

    <!-- Uploaded files -->
    <div v-if="paths().length > 0" class="flex flex-wrap gap-3">
      <div v-for="path in paths()" :key="path" class="relative">
        <img
          v-if="isImage"
          :src="previewUrl(path)"
          :alt="basename(path)"
          class="h-20 w-20 rounded-lg border border-input object-cover"
        />
        <div
          v-else
          class="flex h-20 w-32 flex-col items-center justify-center gap-1 rounded-lg border border-input bg-muted p-2"
        >
          <FileText class="h-5 w-5 text-muted-foreground" />
          <span
            class="w-full truncate text-center text-[10px] text-muted-foreground"
          >
            {{ basename(path) }}
          </span>
        </div>

        <button
          v-if="!disabled"
          type="button"
          class="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-destructive text-white shadow hover:bg-destructive/90"
          :aria-label="t('kinetix.remove')"
          @click="remove(path)"
        >
          <X class="h-3 w-3" />
        </button>
      </div>
    </div>
  </div>
</template>
