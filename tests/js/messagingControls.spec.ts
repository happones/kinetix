import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The messaging components (lines, inbox, bots) build every choice on the
 * Kinetix/reka primitives — KinetixSelect, KinetixCombobox, KinetixCheckbox,
 * reka RadioGroup, KinetixTooltip — never the browser's own select, datalist,
 * radio, checkbox or title tooltip, which look and behave differently on
 * every platform and skip the design tokens. Text fields and textareas are
 * fine: they're the styled shadcn input.
 */
const componentsDir = path.resolve(__dirname, '../../resources/js/components');

const MESSAGING = [
    'Channels',
    'Inbox',
    'Flows',
    'KinetixChannelManager.vue',
    'KinetixInbox.vue',
    'KinetixFlowEditor.vue',
    'KinetixFlowList.vue',
];

const NATIVE: [string, RegExp][] = [
    ['<select>', /<select\b/],
    ['<datalist>', /<datalist\b/],
    ['radio input', /type="radio"/],
    ['checkbox input', /type="checkbox"/],
    ['title tooltip', /\s:?title="/],
];

const vueFiles = (entry: string): string[] => {
    const full = path.join(componentsDir, entry);

    if (fs.statSync(full).isFile()) {
        return [full];
    }

    return fs
        .readdirSync(full)
        .filter((name) => name.endsWith('.vue'))
        .map((name) => path.join(full, name));
};

/** A component's own tags: the `title` prop of KinetixModal & co. is fine. */
const nativeTags = (source: string): string =>
    (source.split('<template>')[1] ?? '').replace(
        /<[A-Z][\w.]*(?:\s[^>]*)?>/g,
        '',
    );

describe('messaging components use the Kinetix controls', () => {
    const files = MESSAGING.flatMap(vueFiles);

    it('finds the messaging components', () => {
        expect(files.length).toBeGreaterThan(15);
    });

    it.each(NATIVE)('no native %s', (_name, pattern) => {
        const offenders = files.filter((file) =>
            pattern.test(nativeTags(fs.readFileSync(file, 'utf8'))),
        );

        expect(offenders.map((f) => path.relative(componentsDir, f))).toEqual(
            [],
        );
    });
});
