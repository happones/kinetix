import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The real echo-vue; only the socket client is faked (the null broadcaster
// never opens a connection anyway).
vi.mock('pusher-js', () => ({ default: class {} }));

type EchoLoader = typeof import('@/composables/kinetixEcho');
type EchoVue = typeof import('@laravel/echo-vue');

async function fresh(): Promise<{ loader: EchoLoader; echoVue: EchoVue }> {
    vi.resetModules();

    const echoVue = await import('@laravel/echo-vue');
    const loader = await import('@/composables/kinetixEcho');

    return { loader, echoVue };
}

describe('kinetixEcho', () => {
    beforeEach(() => {
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('resolves null while Echo is not configured', async () => {
        const { loader, echoVue } = await fresh();

        expect(echoVue.echoIsConfigured()).toBe(false);
        await expect(loader.loadKinetixEcho()).resolves.toBeNull();
        await expect(
            loader.listenForKinetixNotifications('App.Models.User.1', () => {}),
        ).resolves.toBeNull();
        await expect(
            loader.joinKinetixPresence('presence-room'),
        ).resolves.toBeNull();
        await expect(
            loader.listenOnKinetixChannel('inbox.1', '.x', () => {}),
        ).resolves.toBeNull();
    });

    it('shares the module instance the host configured', async () => {
        const { loader, echoVue } = await fresh();
        echoVue.configureEcho({ broadcaster: 'null' });

        await expect(loader.loadKinetixEcho()).resolves.toBe(echoVue);
    });

    it('listens for notifications and releases without leaving the channel', async () => {
        const { loader, echoVue } = await fresh();
        echoVue.configureEcho({ broadcaster: 'null' });

        const channel = {
            notification: vi.fn(),
            stopListeningForNotification: vi.fn(),
        };
        const instance = echoVue.echo() as any;
        const privateSpy = vi
            .spyOn(instance, 'private')
            .mockReturnValue(channel);
        const leaveSpy = vi.spyOn(instance, 'leave');
        const handler = vi.fn();

        const release = await loader.listenForKinetixNotifications(
            'App.Models.User.1',
            handler,
        );

        expect(privateSpy).toHaveBeenCalledWith('App.Models.User.1');
        expect(channel.notification).toHaveBeenCalledWith(handler);

        release!();
        release!();
        expect(channel.stopListeningForNotification).toHaveBeenCalledTimes(1);
        expect(channel.stopListeningForNotification).toHaveBeenCalledWith(
            handler,
        );
        expect(leaveSpy).not.toHaveBeenCalled();
    });

    it('refcounts presence joins and leaves at zero', async () => {
        const { loader, echoVue } = await fresh();
        echoVue.configureEcho({ broadcaster: 'null' });

        const instance = echoVue.echo() as any;
        const joinSpy = vi.spyOn(instance, 'join');
        const leaveSpy = vi.spyOn(instance, 'leave');

        const first = await loader.joinKinetixPresence('room');
        const second = await loader.joinKinetixPresence('room');

        expect(joinSpy).toHaveBeenCalledTimes(1);
        expect(first!.channel).toBe(second!.channel);

        first!.release();
        first!.release();
        expect(leaveSpy).not.toHaveBeenCalled();

        second!.release();
        expect(leaveSpy).toHaveBeenCalledTimes(1);
        expect(leaveSpy).toHaveBeenCalledWith('room');

        // A later join starts over.
        await loader.joinKinetixPresence('room');
        expect(joinSpy).toHaveBeenCalledTimes(2);
    });

    it('refcounts private channel listeners and leaves after the last release', async () => {
        const { loader, echoVue } = await fresh();
        echoVue.configureEcho({ broadcaster: 'null' });

        const channel = { listen: vi.fn(), stopListening: vi.fn() };
        const instance = echoVue.echo() as any;
        const privateSpy = vi
            .spyOn(instance, 'private')
            .mockReturnValue(channel);
        const leaveSpy = vi.spyOn(instance, 'leave');
        const one = vi.fn();
        const two = vi.fn();

        const releaseOne = await loader.listenOnKinetixChannel(
            'inbox.line.1',
            '.inbox.updated',
            one,
        );
        const releaseTwo = await loader.listenOnKinetixChannel(
            'inbox.line.1',
            '.inbox.updated',
            two,
        );

        // One subscription, two listeners on it.
        expect(privateSpy).toHaveBeenCalledTimes(1);
        expect(channel.listen).toHaveBeenCalledWith('.inbox.updated', one);
        expect(channel.listen).toHaveBeenCalledWith('.inbox.updated', two);

        releaseOne!();
        releaseOne!();
        expect(channel.stopListening).toHaveBeenCalledTimes(1);
        expect(channel.stopListening).toHaveBeenCalledWith(
            '.inbox.updated',
            one,
        );
        expect(leaveSpy).not.toHaveBeenCalled();

        releaseTwo!();
        expect(leaveSpy).toHaveBeenCalledTimes(1);
        expect(leaveSpy).toHaveBeenCalledWith('inbox.line.1');

        // A later listener subscribes again.
        await loader.listenOnKinetixChannel('inbox.line.1', '.e', () => {});
        expect(privateSpy).toHaveBeenCalledTimes(2);
    });

    it('resolves null when the channel cannot be subscribed', async () => {
        const { loader, echoVue } = await fresh();
        echoVue.configureEcho({ broadcaster: 'null' });
        vi.spyOn(echoVue.echo() as any, 'private').mockImplementation(() => {
            throw new Error('no socket');
        });

        await expect(
            loader.listenOnKinetixChannel('inbox.line.2', '.e', () => {}),
        ).resolves.toBeNull();
    });
});
