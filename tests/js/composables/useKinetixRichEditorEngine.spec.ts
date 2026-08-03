import { describe, expect, it } from 'vitest';
import {
    loadKinetixTiptap,
    registerKinetixTiptap,
} from '@/composables/useKinetixRichEditorEngine';

describe('useKinetixRichEditorEngine', () => {
    it('returns null when no loader was registered', async () => {
        expect(await loadKinetixTiptap()).toBeNull();
    });

    it('returns null when the registered loader fails', async () => {
        registerKinetixTiptap(() => Promise.reject(new Error('not installed')));

        expect(await loadKinetixTiptap()).toBeNull();
    });

    it('resolves the engine from the registered loader', async () => {
        class FakeEditor {}
        const kit = { name: 'starter-kit' };

        registerKinetixTiptap(async () => ({
            core: { Editor: FakeEditor as never },
            starterKit: { default: kit },
        }));

        const engine = await loadKinetixTiptap();

        expect(engine?.Editor).toBe(FakeEditor);
        expect(engine?.StarterKit).toBe(kit);
    });

    it('unwraps a named StarterKit export too', async () => {
        const kit = { name: 'named-starter-kit' };

        registerKinetixTiptap(async () => ({
            core: { Editor: class {} as never },
            starterKit: { StarterKit: kit },
        }));

        expect((await loadKinetixTiptap())?.StarterKit).toBe(kit);
    });
});
