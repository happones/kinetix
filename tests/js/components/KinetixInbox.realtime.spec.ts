import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Inbox and channel manager realtime: Echo is reached only through the
 * kinetixEcho loader, only when the server says a line broadcasts, and the
 * fallback polls go through useKinetixPolling.
 */
const echo = vi.hoisted(() => {
    const state = { configured: true, imported: 0 };
    const channels = new Map<
        string,
        {
            listen: ReturnType<typeof vi.fn>;
            stopListening: ReturnType<typeof vi.fn>;
        }
    >();
    const leave = vi.fn();
    const channel = (name: string) => {
        if (!channels.has(name)) {
            channels.set(name, {
                listen: vi.fn(),
                stopListening: vi.fn(),
            });
        }

        return channels.get(name)!;
    };

    return { state, channels, leave, channel };
});

vi.mock('@laravel/echo-vue', () => {
    // Runs on the first import only: a spec that never broadcasts must leave
    // this at 0, i.e. echo-vue (and pusher) were never downloaded.
    echo.state.imported += 1;

    return {
        echoIsConfigured: () => echo.state.configured,
        echo: () => ({
            private: (name: string) => echo.channel(name),
            leave: echo.leave,
        }),
    };
});

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
    isKinetixAbort: () => false,
}));

import { useKinetixChannels } from '@/composables/useKinetixChannels';
import { useKinetixInbox } from '@/composables/useKinetixInbox';

const index = (
    broadcast: (string | null)[],
    poll = { list: 15, thread: 5 },
) => ({
    conversations: [],
    hasMore: false,
    channels: broadcast.map((name, i) => ({
        id: i + 1,
        provider: 'whatsapp',
        name: `Line ${i + 1}`,
        status: 'connected',
        broadcast: name,
    })),
    me: { id: 1, seesAll: true },
    poll,
    composer: { maxUploadKb: 1024, accept: '' },
});

/** Mounts the composable in a component so unmount runs its cleanup. */
function host<T>(setup: () => T): { api: T; unmount: () => void } {
    let api!: T;
    const w = mount(
        defineComponent({
            setup() {
                api = setup();

                return () => h('div');
            },
        }),
    );

    return { api, unmount: () => w.unmount() };
}

const listCalls = () =>
    fetchMock.mock.calls.filter(([url]) =>
        String(url).startsWith('/_kinetix/inbox?'),
    );

function setHidden(hidden: boolean): void {
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        get: () => hidden,
    });
    document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
    fetchMock.mockReset();
    echo.leave.mockReset();
    echo.channels.clear();
    echo.state.configured = true;
});

afterEach(() => {
    vi.useRealTimers();
    setHidden(false);
});

describe('useKinetixInbox realtime', () => {
    it('never loads Echo without a broadcasting line, and polls the list instead', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue(index([null]));
        const { api, unmount } = host(() => useKinetixInbox());

        await api.loadList();
        await flushPromises();
        expect(listCalls()).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(15_000);
        expect(listCalls()).toHaveLength(2);

        // Paused while the tab is hidden…
        setHidden(true);
        await vi.advanceTimersByTimeAsync(45_000);
        expect(listCalls()).toHaveLength(2);

        // …and caught up as soon as it is visible again.
        setHidden(false);
        await flushPromises();
        expect(listCalls()).toHaveLength(3);

        unmount();
        await vi.advanceTimersByTimeAsync(60_000);
        expect(listCalls()).toHaveLength(3);

        expect(echo.state.imported).toBe(0);
    });

    it('listens on every broadcasting line through the shared loader, without polling', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue(index(['inbox.line.1', 'inbox.line.2']));
        const { api, unmount } = host(() => useKinetixInbox());

        await api.loadList();
        await flushPromises();

        expect(echo.state.imported).toBe(1);
        expect(echo.channel('inbox.line.1').listen).toHaveBeenCalledWith(
            '.inbox.updated',
            expect.any(Function),
        );
        expect(echo.channel('inbox.line.2').listen).toHaveBeenCalledTimes(1);

        // A later page load doesn't subscribe twice.
        await api.loadList();
        await flushPromises();
        expect(echo.channel('inbox.line.1').listen).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(60_000);
        expect(listCalls()).toHaveLength(2);

        unmount();
        expect(echo.channel('inbox.line.1').stopListening).toHaveBeenCalledWith(
            '.inbox.updated',
            expect.any(Function),
        );
        expect(echo.leave).toHaveBeenCalledWith('inbox.line.1');
        expect(echo.leave).toHaveBeenCalledWith('inbox.line.2');
    });

    it('two inboxes on one page share a line: it is left after the last one goes', async () => {
        fetchMock.mockResolvedValue(index(['inbox.shared']));
        const first = host(() => useKinetixInbox());
        const second = host(() => useKinetixInbox());

        await first.api.loadList();
        await second.api.loadList();
        await flushPromises();

        expect(echo.channel('inbox.shared').listen).toHaveBeenCalledTimes(2);

        first.unmount();
        expect(echo.leave).not.toHaveBeenCalled();

        second.unmount();
        expect(echo.leave).toHaveBeenCalledTimes(1);
        expect(echo.leave).toHaveBeenCalledWith('inbox.shared');
    });

    it('releases at once when unmounted while Echo is still loading', async () => {
        fetchMock.mockResolvedValue(index(['inbox.race']));
        const { api, unmount } = host(() => useKinetixInbox());

        const loading = api.loadList();
        unmount();
        await loading;
        await flushPromises();

        const channel = echo.channel('inbox.race');
        expect(channel.listen.mock.calls.length).toBe(
            channel.stopListening.mock.calls.length,
        );
        expect(echo.leave).toHaveBeenCalledWith('inbox.race');
    });

    it('falls back to polling the list and the open thread when Echo is not configured', async () => {
        vi.useFakeTimers();
        echo.state.configured = false;
        fetchMock.mockImplementation(async (url: string) =>
            String(url).includes('/messages')
                ? { messages: [], hasMore: false }
                : index(['inbox.unconfigured']),
        );
        const { api, unmount } = host(() => useKinetixInbox());

        await api.loadList();
        await flushPromises();
        expect(
            echo.channel('inbox.unconfigured').listen,
        ).not.toHaveBeenCalled();

        await api.open({ id: 7 } as never);
        await flushPromises();
        const threadCalls = () =>
            fetchMock.mock.calls.filter(([url]) =>
                String(url).startsWith('/_kinetix/inbox/7/messages'),
            ).length;
        const opened = threadCalls();

        await vi.advanceTimersByTimeAsync(5_000);
        expect(threadCalls()).toBe(opened + 1);

        await vi.advanceTimersByTimeAsync(10_000);
        expect(listCalls().length).toBeGreaterThanOrEqual(2);

        // Closing the thread stops its poll.
        api.close();
        const closed = threadCalls();
        await vi.advanceTimersByTimeAsync(20_000);
        expect(threadCalls()).toBe(closed);

        unmount();
    });
});

describe('useKinetixChannels realtime', () => {
    const channelIndex = (broadcast: string | null) => ({
        channels: [],
        providers: [],
        limit: null,
        broadcast,
    });

    it('does not reach Echo when the lines do not broadcast', async () => {
        fetchMock.mockResolvedValue(channelIndex(null));
        const imported = echo.state.imported;
        const { api, unmount } = host(() => useKinetixChannels());

        await api.load();
        api.listen(() => {});
        await flushPromises();

        expect(echo.channels.size).toBe(0);
        expect(echo.state.imported).toBe(imported);
        unmount();
    });

    it('listens through the loader and releases on unmount', async () => {
        fetchMock.mockResolvedValue(channelIndex('kinetix.channels.9'));
        const onUpdate = vi.fn();
        const { api, unmount } = host(() => useKinetixChannels());

        await api.load();
        api.listen(onUpdate);
        api.listen(onUpdate); // idempotent
        await flushPromises();

        const channel = echo.channel('kinetix.channels.9');
        expect(channel.listen).toHaveBeenCalledTimes(1);
        expect(channel.listen).toHaveBeenCalledWith(
            '.channel.updated',
            expect.any(Function),
        );

        channel.listen.mock.calls[0][1]({
            id: 1,
            status: 'connected',
            handle: null,
            qr: null,
        });
        expect(onUpdate).toHaveBeenCalledTimes(1);

        unmount();
        expect(channel.stopListening).toHaveBeenCalledTimes(1);
        expect(echo.leave).toHaveBeenCalledWith('kinetix.channels.9');
    });
});
