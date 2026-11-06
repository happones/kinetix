<script setup lang="ts">
import {
    Bold,
    Heading1,
    Heading2,
    Italic,
    List,
    ListOrdered,
    Quote,
    Strikethrough,
} from '@lucide/vue';
import { ToolbarButton, ToolbarRoot } from 'reka-ui';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import { loadKinetixTiptap } from '@/composables/useKinetixRichEditorEngine';

/**
 * Tiptap-backed WYSIWYG. Tiptap stays an OPTIONAL dependency through the
 * registration seam in `useKinetixRichEditorEngine`: the HOST registers a
 * loader in its entry file (its own import, resolved by its own build), and
 * apps that don't register see the install notice — no import shape inside a
 * published component can be optional at build time AND resolvable at runtime.
 *
 * The toolbar is the same labelled ARIA toolbar as the basic driver: one tab
 * stop, arrow-key navigation, and `aria-pressed` on every toggle.
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        disabled?: boolean;
        placeholder?: string | null;
    }>(),
    { value: '', disabled: false, placeholder: null },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

const { t } = useI18n();
const element = ref<HTMLElement | null>(null);

const editor = shallowRef<any>(null);
const failed = ref(false);
const active = ref<Record<string, boolean>>({});

function refreshActive(): void {
    const e = editor.value;

    if (!e) {
        return;
    }

    active.value = {
        bold: e.isActive('bold'),
        italic: e.isActive('italic'),
        strike: e.isActive('strike'),
        h1: e.isActive('heading', { level: 1 }),
        h2: e.isActive('heading', { level: 2 }),
        bulletList: e.isActive('bulletList'),
        orderedList: e.isActive('orderedList'),
        blockquote: e.isActive('blockquote'),
    };
}

onMounted(async () => {
    const engine = await loadKinetixTiptap();

    if (engine === null) {
        failed.value = true;

        return;
    }

    try {
        editor.value = new engine.Editor({
            element: element.value as HTMLElement,
            extensions: [engine.StarterKit],
            content: props.value ?? '',
            editable: !props.disabled,
            onUpdate: ({
                editor: e,
            }: {
                editor: { getHTML: () => string };
            }) => {
                emit('update:value', e.getHTML());
            },
            onSelectionUpdate: refreshActive,
            onTransaction: refreshActive,
        });
        refreshActive();
    } catch {
        failed.value = true;
    }
});

watch(
    () => props.value,
    (next) => {
        const e = editor.value;

        if (e && (next ?? '') !== e.getHTML()) {
            e.commands.setContent(next ?? '', false);
        }
    },
);

onBeforeUnmount(() => editor.value?.destroy());

const run = (fn: (chain: any) => any) => {
    const e = editor.value;

    if (e) {
        fn(e.chain().focus()).run();
    }
};

interface ToolButton {
    key: string;
    /** `kinetix.*` key of the button's accessible name. */
    label: string;
    icon: Component;
    run: () => void;
}

const tools: ToolButton[] = [
    {
        key: 'bold',
        label: 'editor_bold',
        icon: Bold,
        run: () => run((c) => c.toggleBold()),
    },
    {
        key: 'italic',
        label: 'editor_italic',
        icon: Italic,
        run: () => run((c) => c.toggleItalic()),
    },
    {
        key: 'strike',
        label: 'editor_strike',
        icon: Strikethrough,
        run: () => run((c) => c.toggleStrike()),
    },
    {
        key: 'h1',
        label: 'editor_heading_1',
        icon: Heading1,
        run: () => run((c) => c.toggleHeading({ level: 1 })),
    },
    {
        key: 'h2',
        label: 'editor_heading_2',
        icon: Heading2,
        run: () => run((c) => c.toggleHeading({ level: 2 })),
    },
    {
        key: 'bulletList',
        label: 'editor_bullet_list',
        icon: List,
        run: () => run((c) => c.toggleBulletList()),
    },
    {
        key: 'orderedList',
        label: 'editor_ordered_list',
        icon: ListOrdered,
        run: () => run((c) => c.toggleOrderedList()),
    },
    {
        key: 'blockquote',
        label: 'editor_blockquote',
        icon: Quote,
        run: () => run((c) => c.toggleBlockquote()),
    },
];
</script>

<template>
    <div
        v-if="failed"
        class="p-4 text-sm rounded-md border border-border bg-muted/40 text-muted-foreground"
    >
        {{ t('kinetix.editor_tiptap_missing') }}
    </div>
    <div
        v-else
        class="shadow-xs overflow-hidden rounded-md border border-input bg-transparent transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
    >
        <ToolbarRoot
            :aria-label="t('kinetix.editor_toolbar')"
            orientation="horizontal"
            loop
            class="gap-0.5 p-1 flex flex-wrap items-center border-b border-border bg-muted/40"
        >
            <ToolbarButton
                v-for="tool in tools"
                :key="tool.key"
                :disabled="disabled"
                :aria-label="t(`kinetix.${tool.label}`)"
                :title="t(`kinetix.${tool.label}`)"
                :aria-pressed="active[tool.key] === true"
                :data-tool="tool.key"
                class="size-7 rounded inline-flex items-center justify-center text-muted-foreground transition-[color,background-color,box-shadow] outline-none hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none aria-pressed:bg-accent aria-pressed:text-accent-foreground"
                @mousedown.prevent
                @click="tool.run()"
            >
                <component :is="tool.icon" class="size-4" aria-hidden="true" />
            </ToolbarButton>
        </ToolbarRoot>
        <div
            ref="element"
            class="kx-tiptap min-h-32 px-3 py-2 text-sm [&_.ProseMirror]:min-h-28 [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:text-lg [&_h2]:font-semibold [&_ul]:pl-5 [&_ol]:pl-5 [&_blockquote]:pl-3 text-foreground [&_.ProseMirror]:outline-none [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:text-muted-foreground [&_ol]:list-decimal [&_ul]:list-disc"
        />
    </div>
</template>
