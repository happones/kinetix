/**
 * Minimal @laravel/echo-vue stub for the screenshot gallery. Presence
 * immediately reports a fixed set of online members so <KinetixOnlineUsers>
 * renders a populated facepile without a real WebSocket server.
 */
const members = [
    { id: 1, name: 'Ada Lovelace', avatar: null },
    { id: 2, name: 'Grace Hopper', avatar: null },
    { id: 3, name: 'Linus Torvalds', avatar: null },
    { id: 4, name: 'Margaret Hamilton', avatar: null },
    { id: 5, name: 'Alan Turing', avatar: null },
    { id: 6, name: 'Katherine Johnson', avatar: null },
];

function presenceChannel() {
    const channel = {
        here(cb: (list: unknown[]) => void) {
            cb(members);
            return channel;
        },
        joining() {
            return channel;
        },
        leaving() {
            return channel;
        },
    };

    return channel;
}

export function useEchoPresence() {
    return { channel: () => presenceChannel(), leave() {} };
}

export function useEchoNotification() {
    return { listen() {}, stopListening() {} };
}

export function useEcho() {
    return { listen() {}, stopListening() {}, leave() {}, leaveChannel() {} };
}

export function configureEcho() {}
