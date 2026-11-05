import type * as EchoVue from '@laravel/echo-vue';

/**
 * The single place Kinetix's layout hosts reach `@laravel/echo-vue` from:
 * always through a dynamic import, so a host that never turns realtime on
 * never downloads echo-vue or pusher-js. The dynamic import shares the module
 * instance with the host's own static import, so `configureEcho()` called in
 * the host's app.ts is what `echoIsConfigured()` sees here.
 */

type EchoVueModule = typeof EchoVue;

/** The presence-channel surface Kinetix uses (the broadcaster's own channel object). */
export interface KinetixEchoPresenceChannel {
    here(callback: (members: any[]) => void): KinetixEchoPresenceChannel;
    joining(callback: (member: any) => void): KinetixEchoPresenceChannel;
    leaving(callback: (member: any) => void): KinetixEchoPresenceChannel;
}

let loading: Promise<EchoVueModule | null> | null = null;

/** Memoised dynamic import; null on the server or when !echoIsConfigured(). */
export function loadKinetixEcho(): Promise<EchoVueModule | null> {
    if (typeof window === 'undefined') {
        return Promise.resolve(null);
    }

    if (loading === null) {
        loading = import('@laravel/echo-vue').catch((error: unknown) => {
            // A failed chunk download: let a later call try again.
            loading = null;
            console.warn('[kinetix] Could not load @laravel/echo-vue.', error);

            return null;
        });
    }

    return loading.then((module) =>
        module !== null && module.echoIsConfigured() ? module : null,
    );
}

/**
 * private(name).notification(handler); the release calls
 * stopListeningForNotification only — never leave(): the user channel is
 * shared with host code. Null when Echo is unavailable or not configured.
 */
export async function listenForKinetixNotifications(
    name: string,
    handler: (notification: Record<string, unknown>) => void,
): Promise<(() => void) | null> {
    const module = await loadKinetixEcho();

    if (module === null) {
        return null;
    }

    try {
        const channel = module.echo().private(name);
        channel.notification(handler);

        let released = false;

        return () => {
            if (released) {
                return;
            }

            released = true;

            try {
                channel.stopListeningForNotification(handler);
            } catch {
                // The connection is already gone; nothing left to unbind.
            }
        };
    } catch (error) {
        console.warn('[kinetix] Could not listen for notifications.', error);

        return null;
    }
}

const presence = new Map<
    string,
    { count: number; channel: KinetixEchoPresenceChannel }
>();

/**
 * join(name) with a module-level refcount: every caller gets the same channel,
 * and the channel is left once the last caller releases it.
 */
export async function joinKinetixPresence(
    name: string,
): Promise<{ channel: KinetixEchoPresenceChannel; release(): void } | null> {
    const module = await loadKinetixEcho();

    if (module === null) {
        return null;
    }

    let entry = presence.get(name);

    if (entry === undefined) {
        try {
            entry = {
                count: 0,
                channel: module.echo().join(name) as KinetixEchoPresenceChannel,
            };
        } catch (error) {
            console.warn(
                '[kinetix] Could not join the presence channel.',
                error,
            );

            return null;
        }

        presence.set(name, entry);
    }

    entry.count++;

    const joined = entry;
    let released = false;

    return {
        channel: joined.channel,
        release(): void {
            if (released) {
                return;
            }

            released = true;
            joined.count--;

            if (joined.count > 0 || presence.get(name) !== joined) {
                return;
            }

            presence.delete(name);

            try {
                module.echo().leave(name);
            } catch {
                // The connection is already gone.
            }
        },
    };
}
