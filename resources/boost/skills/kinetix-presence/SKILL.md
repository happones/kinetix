---
name: kinetix-presence
description: "Real-time online indicators over a Reverb/Pusher presence channel. Kinetix registers the channel auth and shares the channel name; <KinetixOnlineUsers> shows a live facepile, useKinetixPresence() powers online dots. Activates when adding presence / who's-online / online status UI."
license: MIT
metadata:
  author: happones
---

# Kinetix Presence / Online Indicators

## When to Apply

Activate this skill when:
- Showing who's online (a facepile, an online count, a green status dot).
- Adding real-time presence over a Reverb/Pusher presence channel.

## Documentation

For full details, reference `docs/presence.md` (published at https://happones.github.io/kinetix/presence).

## Requirements

Broadcasting must be wired up (`@laravel/echo-vue` + `configureEcho`):

```bash
php artisan kinetix:install --broadcasting
```

## Configuration

```php
'presence' => [
    'enabled' => env('KINETIX_PRESENCE_ENABLED', false),
    'channel' => env('KINETIX_PRESENCE_CHANNEL', 'kinetix-presence'), // team-suffixed when kinetix.teams on
    'name_attribute'   => 'name',
    'avatar_attribute' => 'avatar_url',
],
```

Kinetix registers the presence channel authorization itself (returns each
member's `id`/`name`/`avatar`) — nothing to add in `routes/channels.php`. The
team-resolved channel name is shared as the `kinetix_presence` Inertia prop.

## Frontend

```vue
<KinetixOnlineUsers :max="5" />   <!-- live facepile + "{n} online" -->
```

```ts
const { users, count, isOnline } = useKinetixPresence();
// isOnline(user.id) → green dot
```

`useKinetixPresence(channel?)` joins the presence channel and tracks Echo's
`here`/`joining`/`leaving`, returning `{ users, count, isOnline, channel }`;
leaves on unmount. i18n `presence_online`.
