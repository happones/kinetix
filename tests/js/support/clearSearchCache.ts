import { beforeEach } from 'vitest';
import { clearKinetixSearchCache } from '@/composables/kinetixSearchCache';

// The lookup answer cache is a module singleton: without this, a spec would
// answer from what the previous one fetched for the same token and term.
beforeEach(() => {
    clearKinetixSearchCache();
});
