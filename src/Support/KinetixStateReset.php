<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Happones\Kinetix\Announcements\AnnouncementManager;
use Happones\Kinetix\Billing\PlanCatalog;
use Happones\Kinetix\Credentials\PasswordHistory;
use Happones\Kinetix\Credentials\PasswordObserver;
use Happones\Kinetix\Forms\SearchScope;
use Happones\Kinetix\Help\HelpManager;
use Happones\Kinetix\NotificationPreferences\NotificationPreference;
use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Settings\SettingsManager;
use Happones\Kinetix\Support\Casts\EncryptedWithPlaintextFallback;
use Spatie\Permission\PermissionRegistrar;

/**
 * Clears what must not outlive a single request or queued job.
 *
 * Under PHP-FPM every request starts with empty statics and fresh singletons,
 * so this is a no-op there (which also makes each test case start clean).
 * Under Octane and in queue workers the process lives on: the statics and the
 * memos the app-wide singletons keep would otherwise serve the next request a
 * value another process has since changed.
 *
 * The singletons are reset in place rather than registered `scoped()`: they
 * are injected into other singletons, which would keep the stale instance.
 * `DocsManager` is not listed: it is already `scoped()`, and its memo is keyed
 * by the docs files' fingerprint.
 *
 * Anything new that memoizes per request on a singleton or a static adds its
 * reset here.
 *
 * @internal
 */
final class KinetixStateReset
{
    /**
     * Singletons whose request memo is dropped, when they were resolved.
     *
     * @var list<class-string>
     */
    private const MANAGERS = [
        SettingsManager::class,
        AnnouncementManager::class,
        HelpManager::class,
    ];

    /**
     * @param bool $resetPermissionsTeam also clear spatie's team id. False
     *                                   for a job on a `sync`-driver
     *                                   connection: it runs inside the HTTP
     *                                   request, which still needs its team.
     */
    public static function run(bool $resetPermissionsTeam): void
    {
        // Everything memoized per user or per request object (plans,
        // entitlement verdicts, super-admin and owner verdicts, the request's
        // team) is keyed by an OBJECT and released with it, so this is
        // belt-and-braces — but one call covers every store.
        Memo::flush();

        // The plan catalog is a plain static. Only the request memo is
        // dropped: the opt-in persistent cache is invalidated by plan WRITES.
        PlanCatalog::flushMemo();

        // The password observer memoizes whether the users table carries the
        // policy columns; a migration between requests must be seen.
        PasswordObserver::flush();
        SearchScope::flushMemo();
        PasswordHistory::flush();
        NotificationPreference::flushOwnerTypeColumn();

        // Whether the webhook secret column fits a ciphertext: a migration
        // between jobs or Octane requests must be seen.
        EncryptedWithPlaintextFallback::flush();

        BootWarnings::flush();

        $app = app();

        foreach (self::MANAGERS as $manager) {
            if ($app->resolved($manager)) {
                /** @var SettingsManager|AnnouncementManager|HelpManager $instance */
                $instance = $app->make($manager);
                $instance->flushMemo();
            }
        }

        if ($resetPermissionsTeam && PermissionTeam::active() && $app->resolved(PermissionRegistrar::class)) {
            $app->make(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }
}
