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
import { ToolbarButton, ToolbarRoot } from 'reka-ui';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
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
 *
 * The toolbar is a labelled ARIA toolbar: one tab stop, arrow keys / Home /
 * End move between the buttons, Enter and Space run them, and the toggles
 * report their state through `aria-pressed`. A button keeps the editing
 * selection (mousedown never takes focus) and restores it when it was
 * reached from the keyboard.
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
const surface = ref<HTMLElement | null>(null);
/** Which toggles the current selection has on. */
const pressed = ref<Record<string, boolean>>({});
/** The last selection inside the surface, restored when the toolbar ran from the keyboard. */
let savedRange: Range | null = null;

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

function selectionInSurface(): Selection | null {
    const selection = window.getSelection?.() ?? null;
    const anchor = selection?.anchorNode ?? null;

    return selection &&
        selection.rangeCount > 0 &&
        anchor &&
        surface.value?.contains(anchor)
        ? selection
        : null;
}

/** queryCommandState throws in some browsers outside an editing host. */
function commandState(command: string): boolean {
    try {
        return document.queryCommandState?.(command) === true;
    } catch {
        return false;
    }
}

function currentBlock(): string {
    try {
        return String(document.queryCommandValue?.('formatBlock') ?? '')
            .replace(/[<>]/g, '')
            .toLowerCase();
    } catch {
        return '';
    }
}

function refreshPressed(): void {
    const block = currentBlock();

    pressed.value = {
        bold: commandState('bold'),
        italic: commandState('italic'),
        underline: commandState('underline'),
        strike: commandState('strikeThrough'),
        h1: block === 'h1',
        h2: block === 'h2',
        ul: commandState('insertUnorderedList'),
        ol: commandState('insertOrderedList'),
    };
}

function onSelectionChange(): void {
    const selection = selectionInSurface();

    if (selection) {
        savedRange = selection.getRangeAt(0).cloneRange();
        refreshPressed();
    }
}

// Tracked only while the surface holds focus: an idle editor costs nothing.
let tracking = false;

function startTracking(): void {
    if (!tracking) {
        document.addEventListener('selectionchange', onSelectionChange);
        tracking = true;
    }
}

function stopTracking(): void {
    if (tracking) {
        document.removeEventListener('selectionchange', onSelectionChange);
        tracking = false;
    }
}

function onSurfaceBlur(): void {
    stopTracking();
    sync();
}

onBeforeUnmount(stopTracking);

function exec(command: string, value?: string): void {
    surface.value?.focus();

    // Reached from the keyboard, focus left the surface: put the selection
    // back where the user left it before formatting.
    if (savedRange && !selectionInSurface()) {
        const selection = window.getSelection?.();
        selection?.removeAllRanges();
        selection?.addRange(savedRange);
    }

    document.execCommand(command, false, value);
    sync();
    refreshPressed();
}

function setBlock(tag: string): void {
    exec('formatBlock', tag);
}

function addLink(): void {
    const url = window.prompt(t('kinetix.editor_link_prompt'))?.trim();

    // Only http(s), mailto and relative targets: a `javascript:` link would run
    // for whoever clicks it later.
    if (url && isSafeKinetixHref(url)) {
        exec('createLink', url);
    }
}

interface ToolButton {
    key: string;
    /** `kinetix.*` key of the button's accessible name. */
    label: string;
    icon: Component;
    /** A toggle reports its state through `aria-pressed`. */
    toggle: boolean;
    run: () => void;
}

const tools: ToolButton[] = [
    {
        key: 'bold',
        label: 'editor_bold',
        icon: Bold,
        toggle: true,
        run: () => exec('bold'),
    },
    {
        key: 'italic',
        label: 'editor_italic',
        icon: Italic,
        toggle: true,
        run: () => exec('italic'),
    },
    {
        key: 'underline',
        label: 'editor_underline',
        icon: Underline,
        toggle: true,
        run: () => exec('underline'),
    },
    {
        key: 'strike',
        label: 'editor_strike',
        icon: Strikethrough,
        toggle: true,
        run: () => exec('strikeThrough'),
    },
    {
        key: 'h1',
        label: 'editor_heading_1',
        icon: Heading1,
        toggle: true,
        run: () => setBlock('<h1>'),
    },
    {
        key: 'h2',
        label: 'editor_heading_2',
        icon: Heading2,
        toggle: true,
        run: () => setBlock('<h2>'),
    },
    {
        key: 'ul',
        label: 'editor_bullet_list',
        icon: List,
        toggle: true,
        run: () => exec('insertUnorderedList'),
    },
    {
        key: 'ol',
        label: 'editor_ordered_list',
        icon: ListOrdered,
        toggle: true,
        run: () => exec('insertOrderedList'),
    },
    {
        key: 'link',
        label: 'editor_link',
        icon: LinkIcon,
        toggle: false,
        run: addLink,
    },
];
</script>

<template>
    <div
        class="shadow-xs overflow-hidden rounded-md border border-input bg-transparent transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
        :class="disabled ? 'cursor-not-allowed opacity-50' : ''"
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
                :aria-pressed="
                    tool.toggle ? pressed[tool.key] === true : undefined
                "
                :data-tool="tool.key"
                class="size-7 rounded inline-flex items-center justify-center text-muted-foreground transition-[color,background-color,box-shadow] outline-none hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none aria-pressed:bg-accent aria-pressed:text-accent-foreground"
                @mousedown.prevent
                @click="tool.run()"
            >
                <component :is="tool.icon" class="size-4" aria-hidden="true" />
            </ToolbarButton>
        </ToolbarRoot>
        <div
            ref="surface"
            :contenteditable="!disabled"
            role="textbox"
            aria-multiline="true"
            :data-placeholder="placeholder ?? ''"
            class="kx-rte min-h-32 px-3 py-2 text-sm [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:text-lg [&_h2]:font-semibold [&_ul]:pl-5 [&_ol]:pl-5 text-foreground outline-none [&_a]:text-primary [&_a]:underline [&_ol]:list-decimal [&_ul]:list-disc"
            @input="sync"
            @focus="startTracking"
            @blur="onSurfaceBlur"
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
