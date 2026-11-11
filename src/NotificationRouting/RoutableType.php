<?php

declare(strict_types=1);

namespace Happones\Kinetix\NotificationRouting;

use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;
use Happones\Kinetix\Support\ConfigCallback;
use InvalidArgumentException;

/**
 * The routing metadata of one notification type: who must hold which ability
 * to receive it, and which channels the user cannot opt out of. Both live in
 * the notification type registry and are set in code only.
 *
 *     KinetixNotificationRouting::type('inventory.low-stock')
 *         ->requires('products.viewAny')
 *         ->mandatory(['mail']);
 */
class RoutableType
{
    public function __construct(
        protected NotificationTypeRegistry $registry,
        protected string $type,
    ) {}

    /**
     * The ability a recipient must hold, judged when the notification is
     * sent: whoever a route reaches but lacks it is left out.
     */
    public function requires(string $ability): static
    {
        $this->registry->require($this->type, $ability);

        return $this;
    }

    /**
     * Channels delivered whatever the user's preferences say. For things the
     * person must receive (a security alert, a billing notice), never for
     * marketing. The UI and the API cannot set this.
     *
     * @param list<string> $channels
     */
    public function mandatory(array $channels): static
    {
        $this->registry->mandate($this->type, $channels);

        return $this;
    }

    /**
     * Whether teams can route the type. `false` hides it from the routing
     * screen, refuses rules for it and ignores any already saved. Kinetix's
     * own types (`kinetix.*`) default to not routable.
     */
    public function routable(bool $routable = true): static
    {
        $this->registry->makeRoutable($this->type, $routable);

        return $this;
    }

    /**
     * Offer routing only to teams for which the callback says so, in the
     * screen and when sending: `fn (int|string|null $teamKey, string $type): bool`
     * (the team key is null when the app has no teams). A callable array, an
     * invokable class-string or a closure.
     *
     * @throws InvalidArgumentException when the callback cannot be resolved
     */
    public function availableWhen(mixed $callback): static
    {
        $this->assertCallable($callback, 'availableWhen');
        $this->registry->availableWhen($this->type, $callback);

        return $this;
    }

    /**
     * Says, on the routing screen, who gets the notification while the team
     * has chosen nobody: a translation key or plain text, translated per request.
     */
    public function fallbackDescription(string $description): static
    {
        $this->registry->describeFallback($this->type, $description);

        return $this;
    }

    /**
     * Who gets the notification when the team has no rule for the type:
     * `fn (int|string|null $teamKey, ?object $notification): iterable<Model>`
     * (the notification is null in the routing preview). A fallback the
     * sender gives with `->fallback()` wins; the result is held to the team's
     * members.
     *
     * @throws InvalidArgumentException when the callback cannot be resolved
     */
    public function fallbackUsing(mixed $callback): static
    {
        $this->assertCallable($callback, 'fallbackUsing');
        $this->registry->fallbackUsing($this->type, $callback);

        return $this;
    }

    /**
     * Fails now, not on the first request, when the callback can't be
     * resolved. The value is kept as given and resolved on every call, so no
     * host instance outlives a request.
     */
    protected function assertCallable(mixed $callback, string $method): void
    {
        if (! self::looksCallable($callback)) {
            throw new InvalidArgumentException(sprintf('%s() for the notification type "%s" needs a callable array, an invokable class-string or a closure.', $method, $this->type));
        }
    }

    /**
     * The shape check only: the host class is never built at boot, when no
     * request or user exists yet ({@see ConfigCallback::resolve()} does that
     * per call).
     */
    protected static function looksCallable(mixed $callback): bool
    {
        if ($callback instanceof \Closure) {
            return true;
        }

        if (is_object($callback)) {
            return method_exists($callback, '__invoke');
        }

        if (is_string($callback)) {
            return class_exists($callback) && method_exists($callback, '__invoke');
        }

        if (! is_array($callback) || count($callback) !== 2) {
            return false;
        }

        [$target, $name] = [$callback[0] ?? null, $callback[1] ?? null];

        if (! is_string($name)) {
            return false;
        }

        return is_object($target)
            ? method_exists($target, $name)
            : is_string($target) && class_exists($target) && method_exists($target, $name);
    }
}
