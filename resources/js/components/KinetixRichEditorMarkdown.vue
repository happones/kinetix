<script setup lang="ts">
import { Eye, Pencil } from "@lucide/vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { inputClass } from "@/composables/useShadcnVariants";

/**
 * Zero-dependency Markdown editor: a textarea with a live preview tab. Stores the
 * raw Markdown. The preview uses a tiny, HTML-escaping renderer (headings, bold,
 * italic, code, links, lists, blockquotes) — render the stored Markdown with a
 * full parser on output if you need more.
 */
const props = withDefaults(
  defineProps<{
    value?: string | null;
    disabled?: boolean;
    placeholder?: string | null;
  }>(),
  { value: "", disabled: false, placeholder: null },
);

const emit = defineEmits<{ (e: "update:value", value: string): void }>();

const { t } = useI18n();
const tab = ref<"write" | "preview">("write");

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

/** Minimal, safe Markdown → HTML for the preview pane. */
function renderMarkdown(md: string): string {
  const lines = escapeHtml(md).split("\n");
  const html: string[] = [];
  let inList: "ul" | "ol" | null = null;

  const inline = (s: string): string =>
    s
      .replace(/`([^`]+)`/g, "<code>$1</code>")
      .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
      .replace(/\*([^*]+)\*/g, "<em>$1</em>")
      .replace(
        /\[([^\]]+)\]\((https?:[^)\s]+)\)/g,
        '<a href="$2" target="_blank" rel="noopener">$1</a>',
      );

  const closeList = () => {
    if (inList) {
      html.push(`</${inList}>`);
      inList = null;
    }
  };

  for (const line of lines) {
    const heading = line.match(/^(#{1,6})\s+(.*)$/);
    const ul = line.match(/^[-*]\s+(.*)$/);
    const ol = line.match(/^\d+\.\s+(.*)$/);
    const quote = line.match(/^>\s+(.*)$/);

    if (heading) {
      closeList();
      const level = heading[1].length;
      html.push(`<h${level}>${inline(heading[2])}</h${level}>`);
    } else if (ul) {
      if (inList !== "ul") {
        closeList();
        html.push("<ul>");
        inList = "ul";
      }
      html.push(`<li>${inline(ul[1])}</li>`);
    } else if (ol) {
      if (inList !== "ol") {
        closeList();
        html.push("<ol>");
        inList = "ol";
      }
      html.push(`<li>${inline(ol[1])}</li>`);
    } else if (quote) {
      closeList();
      html.push(`<blockquote>${inline(quote[1])}</blockquote>`);
    } else if (line.trim() === "") {
      closeList();
    } else {
      closeList();
      html.push(`<p>${inline(line)}</p>`);
    }
  }
  closeList();
  return html.join("\n");
}

const preview = computed(() => renderMarkdown(props.value ?? ""));

function onInput(event: Event): void {
  emit("update:value", (event.target as HTMLTextAreaElement).value);
}
</script>

<template>
  <div
    class="overflow-hidden rounded-md border border-input bg-transparent shadow-xs dark:bg-input/30"
  >
    <div class="flex items-center gap-1 border-b border-border bg-muted/40 p-1">
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs font-medium transition-colors"
        :class="
          tab === 'write'
            ? 'bg-background text-foreground shadow-xs'
            : 'text-muted-foreground hover:text-foreground'
        "
        @click="tab = 'write'"
      >
        <Pencil class="size-3.5" /> {{ t("kinetix.editor_write") }}
      </button>
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs font-medium transition-colors"
        :class="
          tab === 'preview'
            ? 'bg-background text-foreground shadow-xs'
            : 'text-muted-foreground hover:text-foreground'
        "
        @click="tab = 'preview'"
      >
        <Eye class="size-3.5" /> {{ t("kinetix.editor_preview") }}
      </button>
    </div>

    <textarea
      v-if="tab === 'write'"
      :value="value ?? ''"
      :disabled="disabled"
      :placeholder="placeholder ?? ''"
      rows="8"
      :class="[inputClass, 'rounded-none border-0 font-mono shadow-none focus-visible:ring-0']"
      @input="onInput"
    />
    <div
      v-else
      class="kx-md min-h-32 px-3 py-2 text-sm text-foreground [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:text-lg [&_h2]:font-semibold [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1"
      v-html="preview"
    />
  </div>
</template>
