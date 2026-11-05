<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Permissions\PermissionTeam;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Asks a report's own `authorize()` about a stored report class and a user,
 * in the right team: the request's (the requester) or the row's (whoever a
 * run is launched as or delivered to, judged in the team the run belongs
 * to, as the console scheduler has no request team at all).
 *
 * @internal
 */
final class ReportAccess
{
    /**
     * The report a stored `report_class` names, or null when it names no
     * concrete Report subclass (a class renamed or removed since).
     */
    public static function instance(string $class): ?Report
    {
        if (! class_exists($class) || ! is_subclass_of($class, Report::class)) {
            return null;
        }

        if ((new \ReflectionClass($class))->isAbstract()) {
            return null;
        }

        return new $class;
    }

    /**
     * Whether `$user` may run the report class, in the current permission
     * context. An unknown class is never allowed.
     */
    public static function allowsClass(string $class, mixed $user): bool
    {
        $report = self::instance($class);

        return $report !== null && $report->authorize($user instanceof Authenticatable ? $user : null);
    }

    /**
     * Whether `$user` may run `$report` inside `$teamId`'s permission context
     * (spatie teams), the user's loaded roles dropped on the way in and out.
     */
    public static function allowsInTeam(Report $report, mixed $user, int|string|null $teamId): bool
    {
        return PermissionTeam::run(
            $teamId,
            static fn (): bool => $report->authorize($user instanceof Authenticatable ? $user : null),
            $user instanceof Model ? $user : null,
        );
    }
}
