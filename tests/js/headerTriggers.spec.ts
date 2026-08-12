import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The header triggers all sit in the same strip of an app header, so they share
 * ONE button recipe: `buttonVariants({ variant: 'outline', size: 'icon-sm' })`.
 *
 * They drifted twice before this scan: the notification bell was a `ghost`
 * button (no border, next to four bordered ones) and the spotlight trigger
 * hand-rolled `h-9 w-9` with no border, so it was 4px taller than the row it
 * lived in. Neither is visible in a unit test of the component itself — only
 * side by side, which is what this file stands in for.
 */
const componentsDir = path.resolve(__dirname, '../../resources/js/components');

const TRIGGERS = [
    'KinetixAnnouncements.vue',
    'KinetixAccessibilityMenu.vue',
    'KinetixModeToggle.vue',
    'KinetixNotificationTrigger.vue',
    'KinetixSpotlightTrigger.vue',
    'LanguageSwitcher/LanguageDropdown.vue',
];

const read = (file: string): string =>
    fs.readFileSync(path.join(componentsDir, file), 'utf8');

describe('header trigger buttons', () => {
    it.each(TRIGGERS)('%s builds on the shared button recipe', (file) => {
        const source = read(file);

        expect(source).toContain('buttonVariants(');
        expect(source).toContain("variant: 'outline'");
        expect(source).toContain("'icon-sm'");
    });

    it.each(['KinetixAnnouncements.vue', 'KinetixNotificationTrigger.vue'])(
        '%s pins its unread badge with the shared class',
        (file) => {
            expect(read(file)).toContain('triggerCountBadgeClass');
        },
    );
});
