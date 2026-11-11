import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * A typed lookup (a debounce timer that ends in a request) must go through
 * `useKinetixSearch` or `createKinetixLatest`: typing outruns the round-trip,
 * so without "latest wins" an older answer paints over a newer one, and
 * without the shared guards a burst of keystrokes is a burst of requests.
 *
 * A file that pairs `setTimeout` with `kinetixFetch`/`fetch` and uses neither
 * is reported here. A timer that is NOT a typing debounce (a poll, a retry
 * with its own sequencing) is listed below with the reason it is fine.
 */
const root = path.resolve(__dirname, '../../resources/js');

const EXEMPT: Record<string, string> = {
    'components/KinetixPdfTemplate.vue':
        'the pause only sets an iframe src; the browser owns that request',
    'composables/useKinetixFormReactivity.ts':
        'form recompute with its own sequence counter and field versioning',
    'composables/useKinetixTableReorder.ts':
        'persists a drag result; not a typed lookup',
    'composables/useKinetixPrecognition.ts':
        'KNOWN PENDING ISSUE, not exempt for good: out-of-order validations of one field can still land late; remove this entry when it gets latest-wins',
};

const sourceFiles = (dir: string): string[] =>
    fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            return sourceFiles(full);
        }

        return /\.(vue|ts)$/.test(entry.name) ? [full] : [];
    });

// An actual call, not a mention in a comment or an import.
const GUARDS = /\b(?:createKinetixLatest|useKinetixSearch)\s*(?:<[^>(]*>)?\(/;
const withoutComments = (source: string): string =>
    source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');
const TIMER = /\bsetTimeout\(/;
const REQUEST = /\bkinetixFetch\b|[^.\w]fetch\(/;

const scanned = ['components', 'composables'].flatMap((dir) =>
    sourceFiles(path.join(root, dir)),
);

describe('typed lookups are guarded', () => {
    it('finds the sources to scan', () => {
        expect(scanned.length).toBeGreaterThan(100);
    });

    it('every debounce-to-request file uses useKinetixSearch or createKinetixLatest', () => {
        const offenders = scanned
            .filter((file) => {
                const source = withoutComments(fs.readFileSync(file, 'utf8'));

                return (
                    TIMER.test(source) &&
                    REQUEST.test(source) &&
                    !GUARDS.test(source)
                );
            })
            .map((file) => path.relative(root, file))
            .filter((file) => !(file in EXEMPT));

        expect(offenders).toEqual([]);
    });

    it('keeps the exemptions honest: each one still pairs a timer with a request', () => {
        const stale = Object.keys(EXEMPT).filter((file) => {
            const full = path.join(root, file);

            if (!fs.existsSync(full)) {
                return true;
            }

            const source = withoutComments(fs.readFileSync(full, 'utf8'));

            return !(
                TIMER.test(source) &&
                REQUEST.test(source) &&
                !GUARDS.test(source)
            );
        });

        expect(stale).toEqual([]);
    });

    it('the migrated callers use the shared composable', () => {
        const migrated = [
            'components/KinetixCombobox.vue',
            'components/KinetixCheckboxList.vue',
            'components/KinetixRelationManager.vue',
            'components/KinetixTags.vue',
            'components/NotificationRouting/KinetixNotificationGroupDialog.vue',
            'composables/useKinetixHelp.ts',
            'composables/useKinetixTeams.ts',
        ];

        for (const file of migrated) {
            const source = withoutComments(
                fs.readFileSync(path.join(root, file), 'utf8'),
            );

            expect(source, file).toMatch(GUARDS);
        }
    });
});
