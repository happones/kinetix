/**
 * A small allowlist HTML sanitizer for markup that ends up in the live DOM:
 * the basic rich editor's stored value, pasted and dropped content, and any
 * other component that must insert untrusted HTML.
 *
 * The input is parsed in an inert document (`DOMParser`: no scripts run, no
 * resources load) and rebuilt node by node into a fresh fragment of the live
 * document. Only allowlisted elements are created, so nothing the parser saw
 * (attributes, handlers, namespaces) is ever carried over by reference.
 *
 * - Allowed tags keep no attributes, except `a[href]` with a safe href, which
 *   always gets `rel="noopener noreferrer"`; a link without one is unwrapped.
 * - Dangerous containers (`script`, `style`, `svg`, `iframe`, form controls…)
 *   are dropped together with their content.
 * - Any other tag is unwrapped: its children are kept, the tag is not.
 *
 * This is a client-side convenience for what the editor renders. Content that
 * other users will see must still be sanitized on the server before output.
 */

const ALLOWED_TAGS = new Set([
    'p',
    'br',
    'div',
    'b',
    'strong',
    'i',
    'em',
    'u',
    's',
    'strike',
    'del',
    'h1',
    'h2',
    'ul',
    'ol',
    'li',
    'a',
    'blockquote',
    'code',
]);

const DROPPED_TAGS = new Set([
    'script',
    'style',
    'iframe',
    'object',
    'embed',
    'svg',
    'math',
    'template',
    'noscript',
    'form',
    'input',
    'textarea',
    'select',
    'button',
    'link',
    'meta',
    'base',
]);

const SAFE_PROTOCOLS = new Set(['http:', 'https:', 'mailto:']);

/** Resolves relative hrefs; never requested, only used to read a protocol. */
const RELATIVE_BASE = 'https://kinetix.invalid/';

/** C0/C1 controls and whitespace (incl. zero-width and BOM) a URL parser may skip. */
const IGNORED_URL_CHARACTERS =
    /[\u0000-\u0020\u007F-\u00A0\u1680\u180E\u2000-\u200F\u2028-\u202F\u205F-\u206F\u3000\uFEFF]/g;

const ESCAPES: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

/** Escapes `& < > " '` so the text is safe in element content and quoted attributes. */
export function escapeKinetixHtml(text: string): string {
    return text.replace(/[&<>"']/g, (character) => ESCAPES[character]);
}

/**
 * Whether a link target is http(s), mailto or relative. Control characters and
 * whitespace are removed before the scheme is read, so `java\tscript:` and
 * similar obfuscations are caught.
 */
export function isSafeKinetixHref(href: string): boolean {
    const normalized = href.replace(IGNORED_URL_CHARACTERS, '');

    if (normalized === '') {
        return false;
    }

    try {
        return SAFE_PROTOCOLS.has(new URL(normalized, RELATIVE_BASE).protocol);
    } catch {
        return false;
    }
}

function appendSanitizedChildren(source: Node, target: Node): void {
    for (const child of Array.from(source.childNodes)) {
        if (child.nodeType === Node.TEXT_NODE) {
            target.appendChild(document.createTextNode(child.nodeValue ?? ''));

            continue;
        }

        if (child.nodeType !== Node.ELEMENT_NODE) {
            continue;
        }

        const element = child as Element;
        const tag = element.localName.toLowerCase();

        if (DROPPED_TAGS.has(tag)) {
            continue;
        }

        if (!ALLOWED_TAGS.has(tag)) {
            appendSanitizedChildren(element, target);

            continue;
        }

        const href = tag === 'a' ? element.getAttribute('href') : null;

        // A link without a safe target is unwrapped to its text.
        if (tag === 'a' && (href === null || !isSafeKinetixHref(href))) {
            appendSanitizedChildren(element, target);

            continue;
        }

        const clean = document.createElement(tag);

        if (href !== null) {
            clean.setAttribute('href', href);
            clean.setAttribute('rel', 'noopener noreferrer');
        }

        appendSanitizedChildren(element, clean);
        target.appendChild(clean);
    }
}

/** The sanitized markup as a fragment of the live document, ready to insert. */
export function sanitizeKinetixHtmlFragment(html: string): DocumentFragment {
    const fragment = document.createDocumentFragment();

    if (!html || typeof DOMParser === 'undefined') {
        return fragment;
    }

    const parsed = new DOMParser().parseFromString(html, 'text/html');

    appendSanitizedChildren(parsed.body, fragment);

    return fragment;
}

/** The sanitized markup serialized back to an HTML string. */
export function sanitizeKinetixHtml(html: string): string {
    if (!html || typeof document === 'undefined') {
        return '';
    }

    const container = document.createElement('div');

    container.appendChild(sanitizeKinetixHtmlFragment(html));

    return container.innerHTML;
}
