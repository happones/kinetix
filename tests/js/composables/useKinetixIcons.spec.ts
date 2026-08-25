import { Printer } from '@lucide/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';
import {
    isIconOnlyAction,
    registerIcons,
    registeredIconNames,
    resolveIcon,
} from '@/composables/useKinetixIcons';

describe('resolveIcon', () => {
    it('resolves every prebuilt action icon name to a component', () => {
        // Names set by EditAction/ViewAction/DeleteAction/CreateAction/
        // RestoreAction/ForceDeleteAction/DownloadAction/PreviewAction.
        for (const name of [
            'edit',
            'eye',
            'trash',
            'trash-2',
            'rotate-ccw',
            'plus',
            'download',
        ]) {
            expect(
                resolveIcon(name),
                `icon "${name}" should resolve`,
            ).toBeTruthy();
        }
    });

    it('is case-insensitive', () => {
        expect(resolveIcon('Trash-2')).toBe(resolveIcon('trash-2'));
    });

    it('returns null for empty or unknown names', () => {
        expect(resolveIcon(null)).toBeNull();
        expect(resolveIcon(undefined)).toBeNull();
        expect(resolveIcon('')).toBeNull();
        expect(resolveIcon('definitely-not-an-icon')).toBeNull();
    });
});

describe('registerIcons', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('resolves a name the package does not ship', () => {
        const Custom = () => h('svg');
        expect(resolveIcon('my-house')).toBeNull();

        registerIcons({ 'my-house': Custom });

        expect(resolveIcon('my-house')).toBe(Custom);
    });

    it('matches registered names case-insensitively, like the shipped ones', () => {
        registerIcons({ 'Mixed-Case': Printer });

        expect(resolveIcon('mixed-case')).toBe(Printer);
        expect(resolveIcon('MIXED-CASE')).toBe(Printer);
    });

    it('lets a host override a shipped icon without editing a component', () => {
        const Replacement = () => h('svg');
        expect(resolveIcon('edit')).not.toBe(Replacement);

        registerIcons({ edit: Replacement });

        expect(resolveIcon('edit')).toBe(Replacement);
    });

    it('lists shipped and registered names together', () => {
        registerIcons({ 'listed-icon': Printer });

        const names = registeredIconNames();
        expect(names).toContain('listed-icon');
        expect(names).toContain('edit');
        // Sorted and de-duplicated, so a host test can diff it.
        expect(names).toEqual([...new Set(names)].sort());
    });
});

describe('the commerce vocabulary is shipped', () => {
    it('resolves the ordinary names a line-of-business app declares', () => {
        // These used to resolve to null, which painted an EMPTY icon button.
        for (const name of [
            'store',
            'printer',
            'receipt',
            'truck',
            'refresh-cw',
            'building-2',
            'shield-check',
            'more-horizontal',
            'file-text',
            'help-circle',
            'user-check',
            'utensils-crossed',
            'webhook',
            'wrench',
        ]) {
            expect(
                resolveIcon(name),
                `icon "${name}" should ship`,
            ).toBeTruthy();
        }
    });
});

describe('isIconOnlyAction', () => {
    it('is true for an icon button whose icon resolves', () => {
        expect(isIconOnlyAction({ icon: 'trash-2', isIconButton: true })).toBe(
            true,
        );
    });

    it('is FALSE when the icon cannot be resolved, so the label still renders', () => {
        // The regression: an icon button hides its label, so an unresolvable
        // icon painted a control with no icon AND no label — invisible.
        expect(
            isIconOnlyAction({ icon: 'no-such-icon', isIconButton: true }),
        ).toBe(false);
    });

    it('is false for a normal labelled action', () => {
        expect(isIconOnlyAction({ icon: 'trash-2' })).toBe(false);
        expect(isIconOnlyAction({ isIconButton: true })).toBe(false);
    });
});
