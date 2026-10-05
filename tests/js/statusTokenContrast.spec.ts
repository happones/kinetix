import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Contrast guard for the shipped status tokens (resources/css/kinetix.css),
 * in BOTH themes — one mode passing proves nothing about the other.
 *
 * Every status color is used three ways, and each must reach WCAG AA (4.5:1,
 * all of them are body-size text somewhere):
 *
 *   - as text on the page        (`text-success` links, icons, emphasis)
 *   - as text on its own 10% tint (`statusBadgeClass` pills)
 *   - as a solid fill under its `-foreground` (buttons, `solid` alerts)
 *
 * plus the shadcn destructive recipe Kinetix uses for white-on-red controls
 * (`bg-destructive text-white dark:bg-destructive/60`), and the neutral text
 * the alerts put on those tints.
 */
const css = fs.readFileSync(
    path.resolve(__dirname, '../../resources/css/kinetix.css'),
    'utf8',
);

type Rgb = [number, number, number];

function block(selector: string): string {
    const start = css.indexOf(`${selector} {`);
    const end = css.indexOf('}', start);

    return css.slice(start, end);
}

function tokens(selector: string): Record<string, Rgb> {
    const out: Record<string, Rgb> = {};
    const pattern = /--([a-z0-9-]+):\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%;/g;

    for (const [, name, h, s, l] of block(selector).matchAll(pattern)) {
        out[name] = hsl(Number(h), Number(s), Number(l));
    }

    return out;
}

function hsl(h: number, s: number, l: number): Rgb {
    const sat = s / 100;
    const light = l / 100;
    const k = (n: number) => (n + h / 30) % 12;
    const a = sat * Math.min(light, 1 - light);
    const f = (n: number) =>
        light - a * Math.max(-1, Math.min(k(n) - 3, Math.min(9 - k(n), 1)));

    return [f(0), f(8), f(4)];
}

function luminance([r, g, b]: Rgb): number {
    const lin = (c: number) =>
        c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;

    return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

function contrast(a: Rgb, b: Rgb): number {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (hi + 0.05) / (lo + 0.05);
}

/** `bg-x/NN` composited over the page, as the browser paints it. */
function over(color: Rgb, page: Rgb, alpha: number): Rgb {
    return color.map((c, i) => alpha * c + (1 - alpha) * page[i]) as Rgb;
}

const WHITE: Rgb = [1, 1, 1];
const AA = 4.5;
const STATUSES = ['success', 'warning', 'info', 'destructive'] as const;

describe.each([
    ['light', ':root'],
    ['dark', '.dark'],
])('status tokens — %s theme', (theme, selector) => {
    const root = tokens(':root');
    const t = { ...root, ...(selector === ':root' ? {} : tokens(selector)) };
    const page = t.background;

    it.each(STATUSES)('%s reads as text on the page', (status) => {
        expect(contrast(t[status], page)).toBeGreaterThanOrEqual(AA);
    });

    it.each(STATUSES)('%s reads as text on its own badge tint', (status) => {
        const tint = over(t[status], page, 0.1);

        expect(contrast(t[status], tint)).toBeGreaterThanOrEqual(AA);
    });

    it.each(STATUSES)(
        '%s carries its -foreground as a solid fill',
        (status) => {
            expect(
                contrast(t[`${status}-foreground`], t[status]),
            ).toBeGreaterThanOrEqual(AA);
        },
    );

    it('carries white on the destructive recipe', () => {
        const fill =
            theme === 'dark' ? over(t.destructive, page, 0.6) : t.destructive;

        expect(contrast(WHITE, fill)).toBeGreaterThanOrEqual(AA);
    });

    it.each(STATUSES)(
        'alert body text (foreground/80) reads on the %s tint',
        (status) => {
            const tint = over(t[status], page, 0.1);
            const text = over(t.foreground, tint, 0.8);

            expect(contrast(text, tint)).toBeGreaterThanOrEqual(AA);
        },
    );

    it('muted text reads on the page', () => {
        expect(contrast(t['muted-foreground'], page)).toBeGreaterThanOrEqual(
            AA,
        );
    });
});
