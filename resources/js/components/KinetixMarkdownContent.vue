<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';
import { writeToClipboard } from '@/composables/useKinetixClipboard';

/**
 * Markdown rendered by Kinetix on the server (help articles, docs pages):
 * typography, callouts, highlighted code with a copy button, tabbed code
 * groups and tables that scroll on their own. The HTML arrives sanitized;
 * this component only styles it and wires the copy buttons and the tabs
 * (arrow keys, Home/End) by event delegation, so it keeps working when the
 * HTML changes.
 *
 *   <KinetixMarkdownContent :html="page.html" as="article" />
 *
 * Highlighted code ships with a light and a dark theme; the dark one applies
 * under `.dark`. Listeners and attributes fall through to the root element.
 */
withDefaults(
    defineProps<{
        html: string;
        /** The root element: `article` for a page's main content. */
        as?: 'div' | 'article' | 'section';
    }>(),
    { as: 'div' },
);

/*
 * The body's colors, as variables read through Tailwind's theme. Tokens are
 * full colors in some apps and bare HSL triplets in others; `--color-*` is a
 * color in both, and naming it in a class here is what makes Tailwind emit it.
 */
const tokens = [
    '[--kx-md-fg:var(--color-foreground)]',
    '[--kx-md-muted-fg:var(--color-muted-foreground)]',
    '[--kx-md-bg:var(--color-background)]',
    '[--kx-md-muted:var(--color-muted)]',
    '[--kx-md-accent:var(--color-accent)]',
    '[--kx-md-border:var(--color-border)]',
    '[--kx-md-ring:var(--color-ring)]',
    '[--kx-md-primary:var(--color-primary)]',
    '[--kx-md-info:var(--color-info)]',
    '[--kx-md-success:var(--color-success)]',
    '[--kx-md-warning:var(--color-warning)]',
    '[--kx-md-destructive:var(--color-destructive)]',
].join(' ');

const { t } = useI18n();
const { announce } = useKinetixAnnounce();
const root = ref<HTMLElement | null>(null);
const timers = new Set<ReturnType<typeof setTimeout>>();

async function copyCode(button: HTMLElement): Promise<void> {
    const block = button.closest<HTMLElement>('.kx-code-group, .kx-code');
    // A group copies the panel on show; a single block, its own code.
    const pre =
        block?.querySelector<HTMLElement>('.kx-code:not([hidden]) pre') ??
        block?.querySelector<HTMLElement>('pre');

    if (!pre || !(await writeToClipboard(pre.textContent ?? ''))) {
        return;
    }

    button.dataset.copied = 'true';
    announce(t('kinetix.markdown_copied'));

    const timer = setTimeout(() => {
        delete button.dataset.copied;
        timers.delete(timer);
    }, 2000);

    timers.add(timer);
}

function tabsOf(tab: HTMLElement): HTMLElement[] {
    return Array.from(
        tab
            .closest('[role="tablist"]')
            ?.querySelectorAll<HTMLElement>('[role="tab"]') ?? [],
    );
}

function selectTab(tab: HTMLElement, focus = false): void {
    const group = tab.closest('.kx-code-group');

    for (const each of tabsOf(tab)) {
        const selected = each === tab;
        const panel = group?.querySelector<HTMLElement>(
            `#${CSS.escape(each.getAttribute('aria-controls') ?? '')}`,
        );

        each.setAttribute('aria-selected', String(selected));
        each.tabIndex = selected ? 0 : -1;

        if (panel) {
            panel.hidden = !selected;
        }
    }

    if (focus) {
        tab.focus();
    }
}

function onClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    const copy = target.closest<HTMLElement>('[data-kx-copy]');

    if (copy && root.value?.contains(copy)) {
        void copyCode(copy);

        return;
    }

    const tab = target.closest<HTMLElement>('.kx-code-tabs [role="tab"]');

    if (tab && root.value?.contains(tab)) {
        selectTab(tab);
    }
}

function onKeydown(event: KeyboardEvent): void {
    const tab = (event.target as HTMLElement).closest<HTMLElement>(
        '.kx-code-tabs [role="tab"]',
    );

    if (!tab) {
        return;
    }

    const tabs = tabsOf(tab);
    const index = tabs.indexOf(tab);
    const next = {
        ArrowRight: tabs[(index + 1) % tabs.length],
        ArrowLeft: tabs[(index - 1 + tabs.length) % tabs.length],
        Home: tabs[0],
        End: tabs[tabs.length - 1],
    }[event.key];

    if (next) {
        event.preventDefault();
        selectTab(next, true);
    }
}

onBeforeUnmount(() => {
    timers.forEach(clearTimeout);
    timers.clear();
});

defineExpose({ el: root });
</script>

<template>
    <article
        v-if="as === 'article'"
        ref="root"
        :class="['kinetix-markdown', tokens]"
        @click="onClick"
        @keydown="onKeydown"
        v-html="html"
    />
    <section
        v-else-if="as === 'section'"
        ref="root"
        :class="['kinetix-markdown', tokens]"
        @click="onClick"
        @keydown="onKeydown"
        v-html="html"
    />
    <div
        v-else
        ref="root"
        :class="['kinetix-markdown', tokens]"
        @click="onClick"
        @keydown="onKeydown"
        v-html="html"
    />
</template>

<style>
/* Global on purpose: the body is v-html, so every rule would need :deep. Each
   one is namespaced under .kinetix-markdown. */
.kinetix-markdown {
    overflow-wrap: anywhere;
}
.kinetix-markdown h1 {
    font-size: 1.875rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    margin-bottom: 1rem;
    scroll-margin-top: 5rem;
}
.kinetix-markdown h2 {
    font-size: 1.3rem;
    font-weight: 600;
    letter-spacing: -0.01em;
    margin-top: 2.25rem;
    margin-bottom: 0.6rem;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--kx-md-border);
    scroll-margin-top: 5rem;
}
.kinetix-markdown h3 {
    font-size: 1.05rem;
    font-weight: 600;
    margin-top: 1.75rem;
    margin-bottom: 0.4rem;
    scroll-margin-top: 5rem;
}
.kinetix-markdown h4 {
    font-size: 0.95rem;
    font-weight: 600;
    margin-top: 1.25rem;
    margin-bottom: 0.4rem;
    scroll-margin-top: 5rem;
}
.kinetix-markdown p,
.kinetix-markdown ul,
.kinetix-markdown ol {
    margin-bottom: 1rem;
    line-height: 1.7;
    color: var(--kx-md-muted-fg);
}
.kinetix-markdown ul,
.kinetix-markdown ol {
    padding-left: 1.25rem;
}
.kinetix-markdown li {
    margin-bottom: 0.35rem;
    list-style: disc;
}
.kinetix-markdown ol li {
    list-style: decimal;
}
.kinetix-markdown li:has(> input[type='checkbox']) {
    list-style: none;
}
.kinetix-markdown li::marker {
    color: var(--kx-md-border);
}
.kinetix-markdown strong {
    color: var(--kx-md-fg);
    font-weight: 600;
}
.kinetix-markdown a:not(.kx-md-anchor) {
    color: var(--kx-md-primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}
.kinetix-markdown :not(pre) > code {
    font-family: ui-monospace, monospace;
    font-size: 0.85em;
    background: var(--kx-md-muted);
    padding: 0.1rem 0.35rem;
    border-radius: 0.3rem;
}
.kinetix-markdown blockquote {
    border-left: 3px solid var(--kx-md-primary);
    padding: 0.25rem 0 0.25rem 1rem;
    margin: 0 0 1rem;
    color: var(--kx-md-muted-fg);
    font-style: italic;
}
.kinetix-markdown img {
    max-width: 100%;
    height: auto;
    border-radius: 0.75rem;
    border: 1px solid var(--kx-md-border);
    margin: 0.75rem 0 1.5rem;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.08);
}
.kinetix-markdown hr {
    border: 0;
    border-top: 1px solid var(--kx-md-border);
    margin: 2rem 0;
}

/* Heading permalinks (docs): shown on hover/focus, always on touch screens. */
.kinetix-markdown .kx-md-anchor {
    margin-left: 0.4rem;
    padding: 0 0.25rem;
    color: var(--kx-md-muted-fg);
    text-decoration: none;
    opacity: 0;
}
.kinetix-markdown :is(h2, h3, h4):hover .kx-md-anchor,
.kinetix-markdown .kx-md-anchor:focus-visible {
    opacity: 1;
}
@media (hover: none) {
    .kinetix-markdown .kx-md-anchor {
        opacity: 0.6;
    }
}

/* Callouts: the icon carries the status color, the text stays foreground. */
.kinetix-markdown .kx-callout {
    --kx-callout: var(--kx-md-info);
    margin: 0 0 1.25rem;
    padding: 0.75rem 1rem;
    border: 1px solid color-mix(in oklab, var(--kx-callout) 35%, transparent);
    border-left: 4px solid var(--kx-callout);
    border-radius: 0.5rem;
    background: color-mix(in oklab, var(--kx-callout) 7%, transparent);
}
.kinetix-markdown .kx-callout-tip {
    --kx-callout: var(--kx-md-success);
}
.kinetix-markdown .kx-callout-important {
    --kx-callout: var(--kx-md-primary);
}
.kinetix-markdown .kx-callout-warning {
    --kx-callout: var(--kx-md-warning);
}
.kinetix-markdown .kx-callout-caution {
    --kx-callout: var(--kx-md-destructive);
}
.kinetix-markdown .kx-callout p,
.kinetix-markdown .kx-callout ul,
.kinetix-markdown .kx-callout ol {
    color: var(--kx-md-fg);
}
.kinetix-markdown .kx-callout > :last-child {
    margin-bottom: 0;
}
.kinetix-markdown .kx-callout-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
    font-weight: 600;
}
.kinetix-markdown .kx-callout-icon {
    width: 1rem;
    height: 1rem;
    flex-shrink: 0;
    color: var(--kx-callout);
}

/* Code blocks and groups */
.kinetix-markdown .kx-code,
.kinetix-markdown .kx-code-group {
    margin: 0 0 1.25rem;
    border: 1px solid var(--kx-md-border);
    border-radius: 0.5rem;
    overflow: hidden;
    overflow-wrap: normal;
}
.kinetix-markdown .kx-code-group .kx-code {
    margin: 0;
    border: 0;
    border-radius: 0;
}
.kinetix-markdown .kx-code-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    min-height: 2.25rem;
    padding: 0 0.25rem 0 0.75rem;
    border-bottom: 1px solid var(--kx-md-border);
    background: var(--kx-md-muted);
    font-size: 0.75rem;
    color: var(--kx-md-muted-fg);
}
.kinetix-markdown .kx-code-label {
    font-family: ui-monospace, monospace;
}
.kinetix-markdown .kx-code-tabs {
    display: flex;
    min-width: 0;
    margin-left: -0.75rem;
    overflow-x: auto;
}
.kinetix-markdown .kx-code-tab {
    min-height: 2.25rem;
    padding: 0 0.75rem;
    border-bottom: 2px solid transparent;
    white-space: nowrap;
    color: var(--kx-md-muted-fg);
    cursor: pointer;
}
.kinetix-markdown .kx-code-tab[aria-selected='true'] {
    border-bottom-color: var(--kx-md-primary);
    color: var(--kx-md-fg);
    font-weight: 500;
}
.kinetix-markdown .kx-code-tab:focus-visible,
.kinetix-markdown .kx-code-copy:focus-visible,
.kinetix-markdown .kx-table-scroll:focus-visible {
    outline: 2px solid var(--kx-md-ring);
    outline-offset: -2px;
}
.kinetix-markdown .kx-code-copy {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.375rem;
    color: var(--kx-md-muted-fg);
    cursor: pointer;
}
.kinetix-markdown .kx-code-copy:hover {
    background: var(--kx-md-accent);
    color: var(--kx-md-fg);
}
.kinetix-markdown .kx-code-copy svg {
    width: 1rem;
    height: 1rem;
}
.kinetix-markdown .kx-code-copy-done,
.kinetix-markdown .kx-code-copy[data-copied] .kx-code-copy-idle {
    display: none;
}
.kinetix-markdown .kx-code-copy[data-copied] .kx-code-copy-done {
    display: block;
    color: var(--kx-md-success);
}
.kinetix-markdown .kx-code pre {
    margin: 0;
    padding: 0.875rem 1rem;
    overflow-x: auto;
    font-family: ui-monospace, monospace;
    font-size: 0.8125rem;
    line-height: 1.6;
}
.kinetix-markdown .kx-code-plain {
    background: var(--kx-md-bg);
    color: var(--kx-md-fg);
}
.dark .kinetix-markdown {
    color-scheme: dark;
}
.dark .kinetix-markdown .phiki {
    background-color: var(--phiki-dark-background-color) !important;
}
.dark .kinetix-markdown .phiki,
.dark .kinetix-markdown .phiki span {
    color: var(--phiki-dark-color) !important;
}

/* Tables scroll on their own instead of widening the page. */
.kinetix-markdown .kx-table-scroll {
    margin: 0 0 1.25rem;
    overflow-x: auto;
    overflow-wrap: normal;
    border: 1px solid var(--kx-md-border);
    border-radius: 0.5rem;
}
.kinetix-markdown table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.kinetix-markdown th,
.kinetix-markdown td {
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid var(--kx-md-border);
    text-align: left;
    vertical-align: top;
}
.kinetix-markdown th {
    background: var(--kx-md-muted);
    color: var(--kx-md-fg);
    font-weight: 600;
}
.kinetix-markdown tr:last-child td {
    border-bottom: 0;
}
.kinetix-markdown td {
    color: var(--kx-md-muted-fg);
}
</style>
