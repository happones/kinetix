import { describe, expect, it, vi } from 'vitest';
import { useKinetixAnnounce } from '@/composables/useKinetixAnnounce';

describe('useKinetixAnnounce', () => {
    it('creates a hidden polite live region and announces into it', async () => {
        const { announce } = useKinetixAnnounce();

        announce('12 results');

        const region = document.getElementById('kinetix-live-region');
        expect(region).not.toBeNull();
        expect(region?.getAttribute('aria-live')).toBe('polite');
        expect(region?.getAttribute('aria-atomic')).toBe('true');

        await vi.waitFor(() => {
            expect(region?.textContent).toBe('12 results');
        });
    });

    it('switches to assertive for interrupting announcements', async () => {
        const { announce } = useKinetixAnnounce();

        announce('Upload failed', true);

        const region = document.getElementById('kinetix-live-region');
        expect(region?.getAttribute('aria-live')).toBe('assertive');

        await vi.waitFor(() => {
            expect(region?.textContent).toBe('Upload failed');
        });
    });

    it('re-announces an identical repeated message', async () => {
        const { announce } = useKinetixAnnounce();
        const region = document.getElementById('kinetix-live-region');

        announce('Saved');
        await vi.waitFor(() => expect(region?.textContent).toBe('Saved'));

        announce('Saved');
        // Cleared synchronously, re-set on the next frame — the clear/set
        // cycle is what makes assistive tech repeat it.
        expect(region?.textContent).toBe('');
        await vi.waitFor(() => expect(region?.textContent).toBe('Saved'));
    });
});
