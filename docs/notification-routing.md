# Notification Routing

Kinetix Notification Routing lets each **team** choose who receives each
notification type and on which channels: "low-stock alerts go to the Support
leads group by email and in-app, and to every member who can see products
in-app". Your code sends the notification once; Kinetix works out the people.

It builds on [Notification Preferences](/notification-preferences): the types
you register there are the types a team can route. A person's own opt-outs still
apply, except for the channels a type makes **mandatory** in code.

- **Audiences:** a member, a role, a group (a named set of members), or
  everyone **eligible** (every member who holds the type's required ability).
- **Channels:** the ones the type is delivered on, among `mail`, `database` and
  `broadcast` (whatever `notification_preferences.channels` lists).
- **Team members only.** Recipients are always current members of the team,
  fallback people included (see [Who the team's people are](#who-the-teams-people-are)).

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-notification-routing-migrations
php artisan migrate
```

```php
'notification_routing' => [
    'enabled' => env('KINETIX_NOTIFICATION_ROUTING_ENABLED', false),
    'teams'   => env('KINETIX_NOTIFICATION_ROUTING_TEAMS'), // null = inherit kinetix.teams

    'queue'          => env('KINETIX_NOTIFICATION_ROUTING_QUEUE'), // null = default queue
    'chunk'          => 100,   // people notified per delivery job
    'max_recipients' => 500,   // the most one notification reaches

    // The team's people: [Class::class, 'method'](teamKey) returning users,
    // or null for the team model's `members_relation` (see "Who the team's
    // people are").
    'members'          => null,
    'members_relation' => 'members',
    'name_attribute'   => 'name',

    'preview_throttle' => '30,1',
],
```

Everything is opt-in. Apps upgrading from an earlier version change nothing
until they publish the migrations and turn the module on. Callbacks are callable
arrays (or invokable class strings), never closures, so `config:cache` keeps
working.

Administering routing needs the `notification_routing.manage` ability (see
[Permissions](/permissions)). With `kinetix.teams` on and
`notification_routing.teams = false`, the rules are app-wide and the feature is
platform-level.

---

## Declaring types

Register the type for the preferences matrix, then declare what routing needs
to know about it, in code:

```php
use Happones\Kinetix\NotificationPreferences\KinetixNotificationPreferences;
use Happones\Kinetix\NotificationRouting\KinetixNotificationRouting;

KinetixNotificationPreferences::types(['inventory.low-stock' => 'Low stock']);

KinetixNotificationRouting::type('inventory.low-stock')
    ->requires('products.viewAny')   // ability a recipient must hold
    ->mandatory(['mail']);           // channels a user cannot opt out of
```

- **`requires($ability)`** is judged against each recipient **when the
  notification is sent**, with the team's roles in effect. Whoever a rule
  reaches but lacks it is left out, even when named directly. It is never
  skipped. The `eligible` audience is everyone who passes it.
- **`mandatory([...])`** keeps those channels on whatever the user chose: the
  preferences matrix shows them on and locked, and turning one off answers a
  422. It is code-only: no screen and no endpoint can set it, and an admin can
  route a channel but never override an opt-out. Reserve it for things the person
  must receive (a security alert, a billing notice). **Never mark a marketing
  type mandatory.**
- A type delivered on some channels only (`deliverOn()`) limits the channels a
  rule may use.

Kinetix's own types (`kinetix.*`) go to the person who started the export or
import and are not routable.

### What can be routed

By default every registered type except `kinetix.*` is offered. Narrow that in
code, per type:

```php
KinetixNotificationRouting::type('personal.document')->routable(false);

KinetixNotificationRouting::type('job_board_applications')
    ->requires('candidates.viewAny')
    ->availableWhen([Content::class, 'routingAvailable']);
```

- **`routable(false)`** hides the type from the screen, answers a rule or a
  preview for it with a 422 and **ignores rules already saved** for it when
  sending (the fallback applies). `routable()` opts a `kinetix.*` type in.
- **`availableWhen($callback)`** decides per team:
  `fn (int|string|null $teamKey, string $type): bool`. The team key is the
  team's primary key, and **null when the app has no teams**, so handle it.
  False hides the type, refuses new rules (422), answers an edit of an existing
  rule with a 404 (deleting stays possible) and ignores the saved rules when
  sending. It is asked for every routable type on the screen request (a
  preview asks again for the previewed type) and once per delivery, with the
  team's real key even when
  `notification_routing.teams` is off.
- Keep both callbacks cheap: `availableWhen` runs on every screen load and
  send, `fallbackUsing` on every send without rules. They are resolved on each
  call (a class is not kept alive between requests).
- Callbacks are callable arrays, invokable class-strings or closures (arrays
  in files you may `config:cache`). One that cannot be resolved throws
  `InvalidArgumentException` when registered.

### Who gets it when nobody is chosen

A type with no rule goes to the fallback. Three ways to give one, first match
wins:

1. `->fallback($users)` on the sender (see below).
2. `fallbackUsing($callback)` on the type:
   `fn (int|string|null $teamKey, ?object $notification): iterable<Model>`.
   Called only when no rule applies, with the team pinned for the ability
   checks. Its users are held to the team's members (someone from another team
   is dropped, with teams on). The notification is `null` in the preview.
3. Nothing: nobody is notified and a warning is logged.

```php
KinetixNotificationRouting::type('job_board_applications')
    ->fallbackDescription('notifications.fallback.job_board') // key or text, translated per request
    ->fallbackUsing([Fallbacks::class, 'jobBoard']);
```

`fallbackDescription()` is the sentence the screen shows under the type while
nobody is chosen (for example "Goes to the hiring managers"). Without it the
screen says it goes to the app's default recipients (when `fallbackUsing` is
set) or asks to add recipients.

---

## Sending

```php
KinetixNotificationRouting::for('inventory.low-stock', $team->getKey())
    ->fallback($team->owners)               // only when the team has no rule for the type
    ->notify(new LowStockAlert($product));  // queued; notifyNow() runs in-process
```

`$team->getKey()` is the team's **primary key** (`null` when the app is not
team-scoped). The team is captured when you call `notify()`: the queued worker
never infers it.

Any Laravel notification works, as long as it can render the channel
(`toMail()` for mail, `toArray()` or `toDatabase()` for database, `toBroadcast()`
or `toArray()` for broadcast). A channel it cannot render is skipped, not
errored. To send a Kinetix in-app notification instead:

```php
KinetixNotificationRouting::for('inventory.low-stock', $team->getKey())
    ->send(Notification::make()->title('Stock is low')->warning());
```

### How a notification is delivered

1. `ResolveAudience` runs on `notification_routing.queue`. In ONE pass it loads
   the team's rules for the type, the holders of the named roles, the members
   of the named groups and the named people. Every audience is **intersected
   with the team's current members**, so a role assignment or group row of
   someone who left reaches nobody. `ResolveAudience` is never retried (its
   `failed()` logs), so a retry cannot notify the same people twice.
2. Each person's channels are the **union** of their rules' channels, kept to the
   channels the type is delivered on (and the notification can render). A person
   reached by several rules gets **one** notification.
3. People failing `requires` are dropped. The ability is judged **as that team's
   work**: while it resolves, `KinetixTeams::currentTeam()` /
   `currentTeamKey()` and the `owner_bypass` check answer with the routing team
   (not the user's own `currentTeam`), with spatie teams on or off, and spatie's
   roles and permissions of the people are loaded in two queries per batch of
   200. A gate or policy of your own that reads `$user->currentTeam` directly
   still sees the user's current team: have it ask `KinetixTeams::currentTeam()`.
4. Preferences are read for everyone in one query. Opt-outs remove channels,
   except the type's mandatory ones. A person left with no channel is skipped; a
   notification is never sent with an empty channel list, so Laravel never falls
   back to the notification's own `via()`.
5. People are grouped by identical channel set and delivered in chunks by
   `DeliverRouted` jobs (`notification_routing.chunk`). The notification's
   `shouldSend()` still runs. One person's failure is reported and does not stop
   the others; delivery jobs are not retried, so nobody is notified twice.
6. No rules for the type: the `fallback()` people are used, with the type's
   channels; with neither, nobody is notified and a warning is logged.
   `max_recipients` caps one notification's audience (a warning is logged): the
   people are kept in member-ref order, so the cut is deterministic, and it can
   drop people even on a type with mandatory channels.

The database channel is replaced by a channel that stamps the team on the stored
row, so a host notification sent to someone in two teams shows only in the bell
of the team it was routed in.

The number of queries does not grow with the audience (a handful for the rules,
roles, groups and preferences, plus two per 200 people when the type has a
`requires`); the ability itself is judged per person in memory.

`shouldSend()`, `NotificationSending` / `NotificationSent` and anything else that
sees the channel read `TeamStampedDatabaseChannel::class` where Laravel would
say `'database'`.

### Who the team's people are

Routing never reaches anyone outside the team.

- **Teams on** (`kinetix.teams`): the people are the team model's
  `members_relation` (found through your user model's teams relation), or what
  your `notification_routing.members` callable returns. If neither can be
  resolved, the list is **empty and nobody is notified** (never every user), and
  `kinetix:doctor` reports an error. Set `members`.
- **No teams, no `members` callable:** the user model is the audience, however
  big. Named members, role holders and group members are loaded by key (nobody
  is lost to a window of rows); the `eligible` audience is read in key order,
  in chunks, only as far as `max_recipients` needs after `requires` is judged.
  Pair a huge user table with a `members` callable to say who counts.
- **No teams, with a `members` callable:** the callable's users are a closed list
  like a team's.

### Member identity

A person is identified by their model **and** key: a **member ref**, the opaque
string `{morphClass}|{key}` (`App\Models\User|5`), so a `Client` #5 is never a
`User` #5. Member rules, group members, the audiences endpoint, `member_ids` and
the recipients preview all use refs. Treat them as opaque and
`encodeURIComponent` them in a URL.

### Previewing recipients

```php
$plan = KinetixNotificationRouting::for('inventory.low-stock', $team->getKey())->recipients();

foreach ($plan->recipients as $recipient) {
    $recipient->user; $recipient->channels;
}
foreach ($plan->skipped as $skipped) {
    $skipped->user; $skipped->reason; // SkipReason::Ineligible | OptedOut | NoChannels
}
$plan->skippedCount; // how many were left out in all
$plan->fellBack;     // no rule applied: the recipients are the fallback's
```

A dry run: nothing is sent. `skipped` is a sample of at most 50 people
(`RecipientPlan::SKIPPED_SAMPLE`) and `skippedCount` is the total: with no teams, the
users a `requires` ability rules out are counted while the table is read, not
kept. Pass a notification to `recipients($notification)`
to also drop channels it cannot render.

---

## Roles on the screen

The role picker offers the team's roles and the global ones, **without the
super-admin role** (it bypasses every check, so a rule for it means nothing; a
rule that names it is a 404). Other protected roles stay valid: "all admins" is
a fine audience. Rules already stored for super-admin are left alone.

Each role shows a label: your `permissions.role_label` callback first, then the
translated admin/editor/viewer presets, then the role's own name when it has
none. Return `null` from the callback for roles it doesn't label so the
default applies.
See [Roles & Permissions](permissions.md#role-labels).

---

## Endpoints

JSON, registered under your Kinetix prefix (team-aware when `kinetix.teams` is
on), all asking for `notification_routing.manage`. Every id is looked up inside
the current team: another team's rule, group, role or member is a 404.

| Method | Route (under `{prefix}/notification-routing`) | Name (`kinetix.notification-routing.`) |
| ------ | --------------------------------------------- | -------------------------------------- |
| `GET` | `/` | `index` |
| `GET` | `audiences?search=` | `audiences` |
| `GET` | `recipients?type=` | `recipients` |
| `POST` | `routes` | `routes.store` |
| `PUT` | `routes/{route}` | `routes.update` |
| `DELETE` | `routes/{route}` | `routes.destroy` |
| `GET` | `groups` | `groups.index` |
| `POST` | `groups` | `groups.store` |
| `PUT` | `groups/{group}` | `groups.update` |
| `DELETE` | `groups/{group}` | `groups.destroy` |
| `POST` | `groups/{group}/members` | `groups.members.store` |
| `DELETE` | `groups/{group}/members/{member}` | `groups.members.destroy` |

`audiences` returns one page of at most 200 members, by name, narrowed by a name
`search`, with `membersComplete` true when that page is every match (and false
when capped); `members=0` skips the members (`members: []`,
`membersComplete: false`) while roles and groups still come, for a screen that only
needs them on mount. The search (at most 100 characters; if `name_attribute` is not a column,
members are ordered by key and the search is ignored). The recipients preview
returns `skippedCount` with the `skipped` sample. A group member's `user_id`
column follows `HostKeys::user`, so a second user model with a different key
type than that column cannot be stored in a group. An id that cannot be a key
of its model (letters for an integer key) is a 404. Saving a rule for an audience that already has one for the type
updates it in place, however many requests race (a lock and the unique key
cover it). Group member writes cost a fixed number of queries whatever the
number of people. Deleting a group deletes the rules that pointed at it. The recipients
preview is throttled (`preview_throttle`).

The payload types are `NotificationRoutingTypeData`, `NotificationRouteData`,
`NotificationGroupData`, `NotificationAudienceData` and
`NotificationRecipientsData` (TypeScript: `KinetixNotificationRoutingType`,
`KinetixNotificationRoute`, `KinetixNotificationGroup`,
`KinetixNotificationAudience`, `KinetixNotificationRecipients`).

---

## The components

Drop-in Vue components, copied with the rest of the Kinetix components:

```vue
<script setup lang="ts">
import KinetixNotificationGroups from '@/components/kinetix/KinetixNotificationGroups.vue';
import KinetixNotificationRouting from '@/components/kinetix/KinetixNotificationRouting.vue';
</script>

<template>
    <KinetixNotificationRouting />
    <KinetixNotificationGroups />
</template>
```

- **`<KinetixNotificationRouting>`** shows a section per routable type with its
  audiences (member, role, group or everyone eligible) and their channels. An
  admin adds an audience (only the type's own channels are offered), edits its
  channels, removes it after a confirmation, or previews who the type reaches
  right now: who is notified on which channels and who is left out and why
  (not allowed, opted out, no channel left). A type's mandatory channels are
  shown with a "Mandatory" badge and an explanation, and are never editable.
  An audience that no longer exists is marked "Removed". On a phone the rows
  stack as cards.
- **`<KinetixNotificationGroups>`** lists the groups and creates, edits and
  deletes them, with a member picker over the team's members. Deleting warns
  that the rules pointing at the group go with it.
- Both take `hide-title` to skip their heading, and both belong behind the
  `notification_routing.manage` ability (the server enforces it).
- **`<KinetixNotificationPreferences>`** shows a mandatory channel checked and
  `aria-disabled` (it stays focusable for keyboard and screen-reader users),
  with the reason beside the type. Clicking it changes nothing and posts
  nothing.

Screens of the same team share their state: the rules, the people a rule can
point at and the groups live in one store per route prefix. A group created,
renamed or deleted on the groups screen shows up on the routing screen at once
(deleted: its rules leave too), and an open recipients preview refreshes.
Switching team (a new prefix) moves to that team's store, and the store is
dropped when its last screen unmounts.

Member ids are opaque strings (`{modelType}|{key}`): the components never parse
or compare them as numbers, and `encodeURIComponent` them in URLs.

### `useKinetixNotificationRouting()`

For a custom screen. It returns the shared `types`, `channels`, `audiences` and
`groups`, plus per-call state:

| Member | What it does |
| ------ | ------------ |
| `loading` / `loaded` / `loadFailed` | state of `load()` (the rules and channels) |
| `groupsLoading` / `groupsLoaded` / `groupsLoadFailed` | state of `loadGroups()` |
| `load()`, `loadGroups()` | fetch the rules, or the groups |
| `loadAudiences({ members, force })` | fetch roles, groups and (unless `members: false`, which sends `?members=0`) the first 200 members; skipped when the members are already loaded, unless `force` |
| `searchMembers(query)` | server-side member search; an older search resolves to the newest result |
| `addRoute`, `updateRoute`, `removeRoute` | write rules |
| `previewRecipients(type)`, `closePreview()` | the dry run (`preview`, `previewType`, `previewLoading`, `previewFailed`) |
| `createGroup`, `updateGroup`, `deleteGroup` | write groups |
| `addMembers(group, refs)` | `POST groups/{group}/members` |
| `removeMember(group, ref)` | `DELETE groups/{group}/members/{ref}` (the ref is encoded) |

Writes throw the request error: use `notificationRoutingErrors(error, fallback)`
for the per-field messages of a 422 (a nested key such as `channels.0` folds into
`channels`) and `notificationRoutingMessage(error, fallback)` for a single
message: the server's text only where it is written for people, else `fallback`.

---

## Events

| Event | When | Carries |
| ----- | ---- | ------- |
| `AudienceResolved` | the audience of a notification was worked out | type, team, recipients per channel, skipped count: no names, no keys |
| `RoutedNotificationSent` | one chunk was delivered | type, team, channels, sent, failed |
| `NotificationRouteChanged` | an admin created, changed or removed a rule or a group, or changed its members | action, subject, team, actor, before, after (for an activity log; members as refs, added in `after.members`, removed in `before.members`) |
| `Gdpr\Events\GdprPurged` | an account was purged ([GDPR](/gdpr#purge-event)) | the user; routing removes that model's rows for them from every group and member rule |

---

## Checking your setup

`php artisan kinetix:doctor` reports a missing table, a type whose `requires()`
ability is not defined (a Gate definition, a registered Kinetix ability or a
spatie permission all count), team members that cannot be resolved, `mandatory()` channels the type isn't delivered on, a
`sync` queue, Notification Preferences being off and a closure in
`notification_routing.members`.

See also [Notifications](/notifications) and
[Notification Preferences](/notification-preferences).
