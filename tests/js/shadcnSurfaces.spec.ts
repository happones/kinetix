import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Congruence guard for the shadcn new-york-v4 design line: every reka-ui
 * floating surface (menus, popovers, selects, comboboxes, tooltips) must
 * carry the full v4 animation set — fade + zoom on open/close plus the
 * directional side-slide. A surface that pops in with no motion (or half of
 * it) silently drifts off the design system; this scan makes the drift a CI
 * failure instead of a visual regression someone notices months later.
 */
const componentsDir = path.resolve(__dirname, '../../resources/js/components');

const FLOATING_TAGS =
    /<(SelectContent|DropdownMenuContent|PopoverContent|ComboboxContent|TooltipContent|HoverCardContent)\b/;

/**
 * Surfaces where a piece of the set legitimately doesn't apply:
 * - KinetixSpotlight: its ComboboxContent is INLINE inside the command
 *   palette dialog (always visible, never floats) — side-slide is meaningless;
 *   the dialog itself animates with the modal fade+zoom pair.
 */
const ALLOWLIST = new Set(['KinetixSpotlight.vue']);

const REQUIRED = [
    'data-[state=open]:animate-in',
    'data-[state=closed]:animate-out',
    'data-[state=closed]:fade-out-0',
    'data-[state=open]:fade-in-0',
    'data-[state=closed]:zoom-out-95',
    'data-[state=open]:zoom-in-95',
    'data-[side=bottom]:slide-in-from-top-2',
];

function walk(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        return entry.isDirectory()
            ? walk(full)
            : entry.name.endsWith('.vue')
              ? [full]
              : [];
    });
}

describe('shadcn v4 floating-surface congruence (published components)', () => {
    it('every reka floating surface carries the FULL v4 animation set', () => {
        const offenders: string[] = [];

        for (const file of walk(componentsDir)) {
            const source = fs.readFileSync(file, 'utf8');

            if (!FLOATING_TAGS.test(source)) {
                continue;
            }

            if (ALLOWLIST.has(path.relative(componentsDir, file))) {
                continue;
            }

            const missing = REQUIRED.filter((cls) => !source.includes(cls));

            if (missing.length > 0) {
                offenders.push(
                    `${path.relative(componentsDir, file)} (missing: ${missing.join(', ')})`,
                );
            }
        }

        expect(offenders).toEqual([]);
    });
});
