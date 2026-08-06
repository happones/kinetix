import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Kinetix layers everything teleported to <body> on a documented CSS-var
 * scale (see docs/installation.md § Z-index scale):
 *
 *   --kinetix-z-overlay (100) — full-screen backdrops
 *   --kinetix-z-modal   (100) — dialog / sheet / drawer content
 *   --kinetix-z-popover (120) — popper content: selects, pickers, dropdowns
 *
 * Raw `z-50` / `z-[100]` on portalled content put popovers BEHIND the
 * package's own sheets and modals (both end up siblings under <body>, so the
 * number decides). This scan keeps every component on the scale instead of
 * letting each new one pick a number.
 */
const componentsDir = path.resolve(__dirname, '../../resources/js/components');

// In-flow (non-teleported) droppers that stack inside their own parent — a
// local z-index is correct there. Keep this list SHORT and only for elements
// that are NOT teleported to <body>.
const RAW_Z50_ALLOWLIST = new Set(['KinetixTags.vue']);

const vueFiles = (dir: string): string[] =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            return vueFiles(full);
        }

        return entry.name.endsWith('.vue') ? [full] : [];
    });

describe('z-index scale (published components)', () => {
    const files = vueFiles(componentsDir);

    it('finds the components directory', () => {
        expect(files.length).toBeGreaterThan(50);
    });

    it('no component hardcodes z-[100] — use var(--kinetix-z-modal/overlay,100)', () => {
        const offenders = files.filter((file) =>
            fs.readFileSync(file, 'utf8').includes('z-[100]'),
        );

        expect(offenders.map((f) => path.relative(componentsDir, f))).toEqual(
            [],
        );
    });

    it('no teleported component uses raw z-50 — use var(--kinetix-z-popover,120)', () => {
        const offenders = files.filter((file) => {
            if (RAW_Z50_ALLOWLIST.has(path.basename(file))) {
                return false;
            }

            return /\bz-50\b/.test(fs.readFileSync(file, 'utf8'));
        });

        expect(offenders.map((f) => path.relative(componentsDir, f))).toEqual(
            [],
        );
    });

    it('the key overlay hosts sit on the modal layer and poppers above it', () => {
        const read = (name: string) =>
            fs.readFileSync(path.join(componentsDir, name), 'utf8');

        expect(read('KinetixSheet.vue')).toContain(
            'z-[var(--kinetix-z-modal,100)]',
        );
        expect(read('primitives/KinetixModal.vue')).toContain(
            'z-[var(--kinetix-z-modal,100)]',
        );
        expect(read('KinetixSelect.vue')).toContain(
            'z-[var(--kinetix-z-popover,120)]',
        );
        expect(read('KinetixDateTimePicker.vue')).toContain(
            'z-[var(--kinetix-z-popover,120)]',
        );
        expect(read('KinetixCombobox.vue')).toContain(
            'z-[var(--kinetix-z-popover,120)]',
        );
    });
});
