/**
 * pusher-js is a peer of @laravel/echo-vue the package does not install. The
 * specs that run the real echo-vue resolve it here (vitest.config.ts alias)
 * and use the `null` broadcaster, which never constructs it.
 */
export default class Pusher {}
