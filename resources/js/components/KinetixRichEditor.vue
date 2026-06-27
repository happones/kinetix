<script setup lang="ts">
import KinetixRichEditorBasic from './KinetixRichEditorBasic.vue';
import KinetixRichEditorMarkdown from './KinetixRichEditorMarkdown.vue';
import KinetixRichEditorTiptap from './KinetixRichEditorTiptap.vue';

/**
 * Rich text / WYSIWYG field with swappable drivers:
 *  - "basic"    : zero-dependency contenteditable + toolbar (HTML)
 *  - "tiptap"   : richer WYSIWYG, Tiptap loaded lazily (HTML)
 *  - "markdown" : zero-dependency textarea + preview (Markdown)
 */
withDefaults(
    defineProps<{
        value?: string | null;
        editor?: string | null;
        disabled?: boolean;
        placeholder?: string | null;
    }>(),
    { value: '', editor: 'basic', disabled: false, placeholder: null },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

const drivers: Record<string, unknown> = {
    basic: KinetixRichEditorBasic,
    tiptap: KinetixRichEditorTiptap,
    markdown: KinetixRichEditorMarkdown,
};
</script>

<template>
    <component
        :is="drivers[editor ?? 'basic'] ?? KinetixRichEditorBasic"
        :value="value"
        :disabled="disabled"
        :placeholder="placeholder"
        @update:value="(v: string) => emit('update:value', v)"
    />
</template>
