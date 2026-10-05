<?php

declare(strict_types=1);

namespace Happones\Kinetix\Flash;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Support\SessionKey;

/**
 * One-shot toasts and in-page alerts for the next page, over Inertia's flash
 * channel:
 *
 *     KinetixFlash::success(__('invoices.sent'));
 *     KinetixFlash::alert(__('billing.payment_failed'), 'danger')
 *         ->description(__('billing.payment_failed_body'));
 *
 *     return back();
 *
 * Flash data is not page props: the browser history never stores it, so Back
 * and Forward can't replay a toast or an alert. `<KinetixToaster>` shows the
 * toasts and `<KinetixFlashAlerts>` the alerts.
 *
 * An alert can outlive the next page. `->keep(2)` shows it on two more page
 * visits, and `->untilDismissed()` shows it until the user closes it. Those live
 * in the session and ride the `kinetix_alerts` prop instead, since they are
 * state rather than a one-off event.
 *
 * inertia-laravel only has a flash channel from 2.0.16 on. On an older 2.x the
 * payload rides the `kinetix_flash` page prop instead (`legacyPayload()`), and
 * the client dedupes it by id so a page restored from history stays quiet.
 *
 * @phpstan-type FlashToast array{id: string, type: string, message: string, description: string|null, duration: int|null}
 * @phpstan-type FlashAlert array{id: string, title: string, description: string|null, color: string, variant: string, icon: string|null, dismissible: bool, persistent: bool}
 * @phpstan-type StoredAlert array{alert: FlashAlert, showings: int|null}
 */
class KinetixFlash
{
    /** The key under Inertia's page-level `flash`. */
    public const FLASH_KEY = 'kinetix';

    /** Where the payload waits when Inertia has no flash channel (< 2.0.16). */
    public const LEGACY_SESSION_KEY = 'kinetix_flash';

    /** Session key of the alerts that outlive one page. */
    public const ALERTS_SESSION_KEY = 'kinetix_flash_alerts';

    /** Session key of the stable ids the user already closed. */
    public const DISMISSED_SESSION_KEY = 'kinetix_flash_dismissed';

    public const TOAST_TYPES = ['success', 'error', 'warning', 'info'];

    public const ALERT_COLORS = ['success', 'danger', 'warning', 'info', 'primary', 'gray'];

    public const ALERT_VARIANTS = ['soft', 'outline', 'accent', 'solid'];

    /**
     * How many closed ids the session remembers — enough for any real page,
     * bounded so a long session can't grow it forever.
     */
    protected const DISMISSED_LIMIT = 50;

    /**
     * A toast on the next page. `$duration` is in ms; null = the toaster's own
     * default.
     */
    public static function toast(string $message, string $type = 'success', ?string $description = null, ?int $duration = null): void
    {
        static::push('toasts', [
            'id'          => (string) Str::uuid(),
            'type'        => in_array($type, self::TOAST_TYPES, true) ? $type : 'success',
            'message'     => $message,
            'description' => $description,
            'duration'    => $duration !== null && $duration > 0 ? $duration : null,
        ]);
    }

    public static function success(string $message, ?string $description = null): void
    {
        static::toast($message, 'success', $description);
    }

    public static function error(string $message, ?string $description = null): void
    {
        static::toast($message, 'error', $description);
    }

    public static function warning(string $message, ?string $description = null): void
    {
        static::toast($message, 'warning', $description);
    }

    public static function info(string $message, ?string $description = null): void
    {
        static::toast($message, 'info', $description);
    }

    /**
     * An in-page alert on the next page. Sent when the returned builder goes
     * out of scope (or on `->send()`), so a chain needs no terminator.
     */
    public static function alert(string $title, string $color = 'info'): PendingFlashAlert
    {
        return new PendingFlashAlert($title, $color);
    }

    /**
     * Append to the one-shot flash. Inertia merges flash keys shallowly, so a
     * second toast in the same request would replace the first without this.
     *
     * @param 'toasts'|'alerts'    $bucket
     * @param array<string, mixed> $entry
     */
    public static function push(string $bucket, array $entry): void
    {
        $current = static::flashed();

        $entries   = is_array($current[$bucket] ?? null) ? $current[$bucket] : [];
        $entries[] = $entry;

        $current[$bucket] = $entries;

        if (static::inertiaHasFlash()) {
            Inertia::flash(self::FLASH_KEY, $current);

            return;
        }

        session()->flash(self::LEGACY_SESSION_KEY, $current);
    }

    /**
     * What is waiting for the next page — `['toasts' => […], 'alerts' => […]]`
     * — whichever channel carries it.
     *
     * Read off the session store `Inertia::flash()` writes to, not through
     * `Inertia::getFlashed()`, which asks the REQUEST's session: a queued job
     * or a test may not have one.
     *
     * @return array<string, mixed>
     */
    public static function flashed(): array
    {
        $payload = static::inertiaHasFlash()
            ? (session()->get(static::inertiaFlashKey(), [])[self::FLASH_KEY] ?? [])
            : session()->get(self::LEGACY_SESSION_KEY, []);

        return is_array($payload) ? $payload : [];
    }

    /**
     * The payload for the `kinetix_flash` prop — only when Inertia can't carry
     * flash itself; null otherwise, so a current install ships nothing extra.
     *
     * @return array<string, mixed>|null
     */
    public static function legacyPayload(): ?array
    {
        if (static::inertiaHasFlash()) {
            return null;
        }

        $payload = static::flashed();

        return $payload === [] ? null : $payload;
    }

    /**
     * Whether the installed inertia-laravel has `Inertia::flash()` (2.0.16+).
     * Asked of the bound factory: Kinetix supports `^2.0|^3.0`, and only the
     * running app knows which one it got.
     */
    public static function inertiaHasFlash(): bool
    {
        return method_exists(Inertia::getFacadeRoot(), 'flash');
    }

    /**
     * Inertia's flash session key: a class constant on v3, an enum on 2.0.16+,
     * the same string either way.
     */
    protected static function inertiaFlashKey(): string
    {
        return class_exists(SessionKey::class) ? SessionKey::FLASH_DATA : 'inertia.flash_data';
    }

    /**
     * Keep an alert in the session for `$showings` page visits, or until the
     * user closes it when null. A stable id the user already closed is not
     * shown again — re-sending it on every request (a middleware, say) is safe.
     *
     * @param FlashAlert $alert
     */
    public static function remember(array $alert, ?int $showings): void
    {
        if (in_array($alert['id'], static::dismissedIds(), true)) {
            return;
        }

        /** @var array<string, StoredAlert> $stored */
        $stored = session(self::ALERTS_SESSION_KEY, []);

        $stored[$alert['id']] = ['alert' => $alert, 'showings' => $showings];

        session()->put(self::ALERTS_SESSION_KEY, $stored);
    }

    /**
     * The session's alerts for this response, each with its close endpoint. A
     * page visit counts one showing; a prefetch (hover) doesn't, or merely
     * pointing at a link would use the alert up.
     *
     * @return list<array<string, mixed>>
     */
    public static function persistentAlerts(?Request $request = null): array
    {
        $request ??= request();

        /** @var array<string, StoredAlert> $stored */
        $stored = session(self::ALERTS_SESSION_KEY, []);

        if ($stored === []) {
            return [];
        }

        $counts = ! $request->prefetch();
        $shown  = [];
        $kept   = [];

        foreach ($stored as $id => $entry) {
            $shown[] = $entry['alert'] + [
                'dismissUrl' => route('kinetix.flash.dismiss', ['id' => $id], false),
            ];

            if ($entry['showings'] === null || ! $counts) {
                $kept[$id] = $entry;

                continue;
            }

            if ($entry['showings'] > 1) {
                $kept[$id] = ['alert' => $entry['alert'], 'showings' => $entry['showings'] - 1];
            }
        }

        if ($counts) {
            session()->put(self::ALERTS_SESSION_KEY, $kept);
        }

        return $shown;
    }

    /**
     * The user closed it: drop it from the session and remember the id, so a
     * request that sends it again doesn't bring it back.
     */
    public static function dismiss(string $id): void
    {
        static::forget($id);

        $dismissed   = array_values(array_diff(static::dismissedIds(), [$id]));
        $dismissed[] = $id;

        session()->put(self::DISMISSED_SESSION_KEY, array_slice($dismissed, -self::DISMISSED_LIMIT));
    }

    /**
     * Withdraw an alert the app no longer needs (the trial converted, the card
     * was fixed) without counting it as closed by the user.
     */
    public static function forget(string $id): void
    {
        /** @var array<string, StoredAlert> $stored */
        $stored = session(self::ALERTS_SESSION_KEY, []);

        unset($stored[$id]);

        session()->put(self::ALERTS_SESSION_KEY, $stored);
    }

    /**
     * @return list<string>
     */
    protected static function dismissedIds(): array
    {
        $ids = session(self::DISMISSED_SESSION_KEY, []);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
