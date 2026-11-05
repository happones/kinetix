import { describe, expect, it } from 'vitest';
import {
    escapeKinetixHtml,
    isSafeKinetixHref,
    sanitizeKinetixHtml,
    sanitizeKinetixHtmlFragment,
} from '@/composables/useKinetixHtmlSanitizer';

describe('sanitizeKinetixHtml', () => {
    it('drops an <img onerror> payload', () => {
        const html = sanitizeKinetixHtml(
            '<p>hi</p><img src=x onerror="alert(1)">',
        );

        expect(html).toBe('<p>hi</p>');
    });

    it('drops <script>, <svg onload> and <style> with their content', () => {
        expect(sanitizeKinetixHtml('<script>alert(1)</script><p>ok</p>')).toBe(
            '<p>ok</p>',
        );
        expect(
            sanitizeKinetixHtml(
                '<svg onload="alert(1)"><circle r="2"></circle><text>x</text></svg><p>ok</p>',
            ),
        ).toBe('<p>ok</p>');
        expect(
            sanitizeKinetixHtml(
                '<style>p{color:red}</style><p style="color:red">ok</p>',
            ),
        ).toBe('<p>ok</p>');
        expect(
            sanitizeKinetixHtml(
                '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe><form><input value="a"><button>b</button></form><template><p>t</p></template><p>ok</p>',
            ),
        ).toBe('<p>ok</p>');
    });

    it('strips on* handlers and every other attribute from allowed tags', () => {
        expect(
            sanitizeKinetixHtml(
                '<p onclick="alert(1)" class="x" id="y" style="color:red">a<b onmouseover="alert(2)">b</b></p>',
            ),
        ).toBe('<p>a<b>b</b></p>');
    });

    it('unwraps unknown tags but keeps their text', () => {
        expect(
            sanitizeKinetixHtml(
                '<p><span style="x">one</span> <font>two</font></p>',
            ),
        ).toBe('<p>one two</p>');
    });

    it('strips javascript:, obfuscated javascript: and data: hrefs', () => {
        for (const href of [
            'javascript:alert(1)',
            'JaVa\tscript:alert(1)',
            ' \u0001javascript:alert(1)',
            'java\nscript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
        ]) {
            const container = document.createElement('div');
            container.appendChild(
                sanitizeKinetixHtmlFragment(
                    `<a href="${href.replace(/"/g, '&quot;')}">x</a>`,
                ),
            );

            expect(container.querySelector('[href]')).toBeNull();
            expect(container.textContent).toBe('x');
        }
    });

    it('keeps safe links and forces rel', () => {
        expect(
            sanitizeKinetixHtml(
                '<a href="https://a.test/x" target="_top">x</a>',
            ),
        ).toBe('<a href="https://a.test/x" rel="noopener noreferrer">x</a>');
        expect(sanitizeKinetixHtml('<a href="mailto:a@b.test">m</a>')).toBe(
            '<a href="mailto:a@b.test" rel="noopener noreferrer">m</a>',
        );
        expect(sanitizeKinetixHtml('<a href="/docs#a">r</a>')).toBe(
            '<a href="/docs#a" rel="noopener noreferrer">r</a>',
        );
    });

    it('round-trips the markup the toolbar produces unchanged', () => {
        const toolbar =
            '<h1>Title</h1><h2>Sub</h2><p><b>b</b> <strong>s</strong> <i>i</i> <em>e</em> <u>u</u> <s>s</s> <strike>k</strike> <del>d</del><br></p>' +
            '<ul><li>one</li></ul><ol><li>two</li></ol><blockquote>q</blockquote><div><code>c</code></div>' +
            '<p><a href="https://a.test/" rel="noopener noreferrer">link</a></p>';

        expect(sanitizeKinetixHtml(toolbar)).toBe(toolbar);
    });

    it('returns a fragment owned by the live document', () => {
        const fragment = sanitizeKinetixHtmlFragment('<p>a</p>');

        expect(fragment.ownerDocument).toBe(document);
        expect(fragment.childNodes).toHaveLength(1);
    });
});

describe('isSafeKinetixHref', () => {
    it('allows http, https, mailto and relative links only', () => {
        expect(isSafeKinetixHref('https://a.test')).toBe(true);
        expect(isSafeKinetixHref('http://a.test')).toBe(true);
        expect(isSafeKinetixHref('mailto:a@b.test')).toBe(true);
        expect(isSafeKinetixHref('/relative')).toBe(true);
        expect(isSafeKinetixHref('#anchor')).toBe(true);
        expect(isSafeKinetixHref('javascript:alert(1)')).toBe(false);
        expect(isSafeKinetixHref('JaVa\tscript:alert(1)')).toBe(false);
        expect(isSafeKinetixHref('data:text/html,x')).toBe(false);
        expect(isSafeKinetixHref('')).toBe(false);
    });
});

describe('escapeKinetixHtml', () => {
    it('escapes & < > " and \'', () => {
        expect(escapeKinetixHtml(`<a href="x" title='y'>&</a>`)).toBe(
            '&lt;a href=&quot;x&quot; title=&#39;y&#39;&gt;&amp;&lt;/a&gt;',
        );
    });
});
