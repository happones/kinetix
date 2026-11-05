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
import {
    isSafeKinetixHref,
    sanitizeKinetixHtml,
    sanitizeKinetixHtmlFragment,
} from '@/composables/useKinetixHtmlSanitizer';

/**
 * Zero-dependency rich text editor: a contenteditable surface with a small
 * formatting toolbar. Emits HTML. The default driver — works out of the box with
 * no extra packages.
 *
 * The stored value, external value changes, pasted and dropped content all pass
 * through the Kinetix HTML sanitizer before they reach the surface, so a stored
 * payload cannot run script when the form opens. Sanitize on the server too
 * before rendering the saved HTML anywhere else.
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
        surface.value.replaceChildren(sanitizeKinetixHtmlFragment(props.value));
    }
});

// Reflect external value changes without clobbering the caret while typing:
// the echo of what the surface just emitted (or its sanitized form) is a no-op.
watch(
    () => props.value,
    (next) => {
        const element = surface.value;
        const html = next ?? '';

        if (
            !element ||
            html === element.innerHTML ||
            sanitizeKinetixHtml(html) === element.innerHTML
        ) {
            return;
        }

        element.replaceChildren(sanitizeKinetixHtmlFragment(html));
    },
);

function sync(): void {
    emit('update:value', surface.value?.innerHTML ?? '');
}

/** Moves the caret to where a drop landed, when the browser can tell. */
function placeCaretAt(x: number, y: number): void {
    const selection = window.getSelection();
    const doc = document as Document & {
        caretPositionFromPoint?: (
            x: number,
            y: number,
        ) => { offsetNode: Node; offset: number } | null;
        caretRangeFromPoint?: (x: number, y: number) => Range | null;
    };

    if (!selection) {
        return;
    }

    let range: Range | null = null;
    const position = doc.caretPositionFromPoint?.(x, y);

    if (position) {
        range = document.createRange();
        range.setStart(position.offsetNode, position.offset);
    } else {
        range = doc.caretRangeFromPoint?.(x, y) ?? null;
    }

    if (range && surface.value?.contains(range.startContainer)) {
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
    }
}

/** Inserts transferred content: HTML only once sanitized, otherwise plain text. */
function insertTransfer(data: DataTransfer | null | undefined): void {
    if (!data) {
        return;
    }

    const html = data.getData('text/html');

    if (html) {
        const clean = sanitizeKinetixHtml(html);

        if (clean) {
            document.execCommand('insertHTML', false, clean);
        }
    } else {
        const text = data.getData('text/plain');

        if (text) {
            document.execCommand('insertText', false, text);
        }
    }

    sync();
}

function onPaste(event: ClipboardEvent): void {
    event.preventDefault();

    if (!props.disabled) {
        insertTransfer(event.clipboardData);
    }
}

/**
 * A drag that starts inside the surface moves content that is already
 * sanitized, so the browser's native move runs (intercepting it would copy the
 * selection instead of moving it). Only drops from outside are sanitized.
 */
let draggingFromSurface = false;

function onDragStart(): void {
    draggingFromSurface = true;
}

function onDragEnd(): void {
    draggingFromSurface = false;
}

function onDrop(event: DragEvent): void {
    if (draggingFromSurface && !props.disabled) {
        draggingFromSurface = false;

        return;
    }

    event.preventDefault();

    if (props.disabled) {
        return;
    }

    surface.value?.focus();
    placeCaretAt(event.clientX, event.clientY);
    insertTransfer(event.dataTransfer);
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
    const url = window.prompt('URL')?.trim();

    // Only http(s), mailto and relative targets: a `javascript:` link would run
    // for whoever clicks it later.
    if (url && isSafeKinetixHref(url)) {
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
            @paste="onPaste"
            @dragstart="onDragStart"
            @dragend="onDragEnd"
            @drop="onDrop"
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
