import { describe, expect, it } from 'vitest';
import {
    KINETIX_SUPERSEDED,
    createKinetixLatest,
} from '@/composables/kinetixLatest';

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

describe('createKinetixLatest', () => {
    it('resolves the newest run and supersedes the older one, whatever the order', async () => {
        const latest = createKinetixLatest();
        const first = deferred<string>();
        const second = deferred<string>();

        const a = latest.run(() => first.promise);
        const b = latest.run(() => second.promise);

        second.resolve('new');
        first.resolve('old');

        expect(await b).toBe('new');
        expect(await a).toBe(KINETIX_SUPERSEDED);
    });

    it('aborts the previous run through its signal', () => {
        const latest = createKinetixLatest();
        const signals: AbortSignal[] = [];

        void latest.run((signal) => {
            signals.push(signal);

            return new Promise<void>(() => {});
        });
        void latest.run((signal) => {
            signals.push(signal);

            return new Promise<void>(() => {});
        });

        expect(signals[0].aborted).toBe(true);
        expect(signals[1].aborted).toBe(false);
    });

    it('owns the pending flag by the newest run only', async () => {
        const latest = createKinetixLatest();
        const first = deferred<string>();
        const second = deferred<string>();

        const a = latest.run(() => first.promise);
        const b = latest.run(() => second.promise);

        first.resolve('old');
        await a;
        expect(latest.pending.value).toBe(true);

        second.resolve('new');
        await b;
        expect(latest.pending.value).toBe(false);
    });

    it('resolves the sentinel instead of throwing when aborted', async () => {
        const latest = createKinetixLatest();
        const run = latest.run((signal) => {
            return new Promise<string>((_, reject) => {
                signal.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError')),
                );
            });
        });

        latest.cancel();

        expect(await run).toBe(KINETIX_SUPERSEDED);
        expect(latest.pending.value).toBe(false);
    });

    it('swallows the error of a superseded run but rethrows the newest', async () => {
        const latest = createKinetixLatest();
        const first = deferred<string>();
        const second = deferred<string>();

        const a = latest.run(() => first.promise);
        const b = latest.run(() => second.promise);

        first.reject(new Error('stale'));
        expect(await a).toBe(KINETIX_SUPERSEDED);

        second.reject(new Error('boom'));
        await expect(b).rejects.toThrow('boom');
        expect(latest.pending.value).toBe(false);
    });

    it('cancel() drops a run that was still going to resolve', async () => {
        const latest = createKinetixLatest();
        const only = deferred<string>();
        const run = latest.run(() => only.promise);

        latest.cancel();
        only.resolve('late');

        expect(await run).toBe(KINETIX_SUPERSEDED);
    });
});
