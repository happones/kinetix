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
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { loadKinetixTiptap } from '@/composables/useKinetixRichEditorEngine';

/**
 * Tiptap-backed WYSIWYG. Tiptap stays an OPTIONAL dependency through the
 * registration seam in `useKinetixRichEditorEngine`: the HOST registers a
 * loader in its entry file (its own import, resolved by its own build), and
 * apps that don't register see the install notice — no import shape inside a
 * published component can be optional at build time AND resolvable at runtime.
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

const tools = [
    { key: 'bold', icon: Bold, run: () => run((c) => c.toggleBold()) },
    { key: 'italic', icon: Italic, run: () => run((c) => c.toggleItalic()) },
    {
        key: 'strike',
        icon: Strikethrough,
        run: () => run((c) => c.toggleStrike()),
    },
    {
        key: 'h1',
        icon: Heading1,
        run: () => run((c) => c.toggleHeading({ level: 1 })),
    },
    {
        key: 'h2',
        icon: Heading2,
        run: () => run((c) => c.toggleHeading({ level: 2 })),
    },
    {
        key: 'bulletList',
        icon: List,
        run: () => run((c) => c.toggleBulletList()),
    },
    {
        key: 'orderedList',
        icon: ListOrdered,
        run: () => run((c) => c.toggleOrderedList()),
    },
    {
        key: 'blockquote',
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
        <div
            class="gap-0.5 p-1 flex flex-wrap items-center border-b border-border bg-muted/40"
        >
            <button
                v-for="tool in tools"
                :key="tool.key"
                type="button"
                :disabled="disabled"
                class="size-7 rounded inline-flex items-center justify-center transition-colors hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none"
                :class="
                    active[tool.key]
                        ? 'bg-accent text-accent-foreground'
                        : 'text-muted-foreground'
                "
                @click="tool.run()"
            >
                <component :is="tool.icon" class="size-4" />
            </button>
        </div>
        <div
            ref="element"
            class="kx-tiptap min-h-32 px-3 py-2 text-sm [&_.ProseMirror]:min-h-28 [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:text-lg [&_h2]:font-semibold [&_ul]:pl-5 [&_ol]:pl-5 [&_blockquote]:pl-3 text-foreground [&_.ProseMirror]:outline-none [&_blockquote]:border-l-2 [&_blockquote]:border-border [&_blockquote]:text-muted-foreground [&_ol]:list-decimal [&_ul]:list-disc"
        />
    </div>
</template>
