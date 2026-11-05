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
});
