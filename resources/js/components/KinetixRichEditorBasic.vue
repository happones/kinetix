<script setup lang="ts">
import {
    Bold,
    Heading1,
    Heading2,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Strikethrough,
    Underline,
} from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';

/**
 * Zero-dependency rich text editor: a contenteditable surface with a small
 * formatting toolbar. Emits HTML. The default driver — works out of the box with
 * no extra packages.
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

const surface = ref<HTMLElement | null>(null);

onMounted(() => {
    if (surface.value && props.value) {
        surface.value.innerHTML = props.value;
    }
});

// Reflect external value changes without clobbering the caret while typing.
watch(
    () => props.value,
    (next) => {
        if (surface.value && (next ?? '') !== surface.value.innerHTML) {
            surface.value.innerHTML = next ?? '';
        }
    },
);

function sync(): void {
    emit('update:value', surface.value?.innerHTML ?? '');
}

function exec(command: string, value?: string): void {
    surface.value?.focus();
    document.execCommand(command, false, value);
    sync();
}

function setBlock(tag: string): void {
    exec('formatBlock', tag);
}

function addLink(): void {
    const url = window.prompt('URL');

    if (url) {
        exec('createLink', url);
    }
}

interface ToolButton {
    key: string;
    icon: unknown;
    run: () => void;
}

const tools: ToolButton[] = [
    { key: 'bold', icon: Bold, run: () => exec('bold') },
    { key: 'italic', icon: Italic, run: () => exec('italic') },
    { key: 'underline', icon: Underline, run: () => exec('underline') },
    { key: 'strike', icon: Strikethrough, run: () => exec('strikeThrough') },
    { key: 'h1', icon: Heading1, run: () => setBlock('<h1>') },
    { key: 'h2', icon: Heading2, run: () => setBlock('<h2>') },
    { key: 'ul', icon: List, run: () => exec('insertUnorderedList') },
    { key: 'ol', icon: ListOrdered, run: () => exec('insertOrderedList') },
    { key: 'link', icon: LinkIcon, run: addLink },
];
</script>

<template>
    <div
        class="shadow-xs overflow-hidden rounded-md border border-input bg-transparent transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
        :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
    >
        <div
            class="gap-0.5 p-1 flex flex-wrap items-center border-b border-border bg-muted/40"
        >
            <button
                v-for="tool in tools"
                :key="tool.key"
                type="button"
                :disabled="disabled"
                class="size-7 rounded inline-flex items-center justify-center text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none"
                @mousedown.prevent="tool.run()"
            >
                <component :is="tool.icon" class="size-4" />
            </button>
        </div>
        <div
            ref="surface"
            :contenteditable="!disabled"
            role="textbox"
            aria-multiline="true"
            :data-placeholder="placeholder ?? ''"
            class="kx-rte min-h-32 px-3 py-2 text-sm [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:text-lg [&_h2]:font-semibold [&_ul]:pl-5 [&_ol]:pl-5 text-foreground outline-none [&_a]:text-primary [&_a]:underline [&_ol]:list-decimal [&_ul]:list-disc"
            @input="sync"
            @blur="sync"
        />
    </div>
</template>

<style scoped>
.kx-rte:empty::before {
    content: attr(data-placeholder);
    color: var(--color-muted-foreground, #888);
    pointer-events: none;
}
</style>
