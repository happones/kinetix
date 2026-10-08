<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use InvalidArgumentException;
use LogicException;

/**
 * A freshly issued temporary password — the plaintext, its expiry, and the
 * means to hand it over — returned by {@see KinetixPasswords::issueTemporaryCredential()}.
 *
 * The plaintext exists ONLY on this object, for the moment right after it was
 * issued: the user record stores a hash. So you either deliver it now (copy it
 * to the admin, mail it, text it) or issue a new one — there is no "show it
 * again". It is redacted in logs, traces and `dd()` so it can't leak by
 * accident, and it carries its own validity window ({@see expiresAt}) so the
 * recipient knows how long they have.
 *
 * Reusable anywhere, with no Membership flow required:
 *
 *     $cred = KinetixPasswords::issueTemporaryCredential($user);
 *     $cred->value;        // the plaintext, to show/copy ONCE
 *     $cred->expiresAt;    // when an unused one stops working
 *     $cred->sendMail();   // or ->send($notifiable) / ->sendVia('vonage', $to)
 *
 * @implements Arrayable<string, mixed>
 */
final class TemporaryCredential implements Arrayable
{
    public function __construct(
        /** The plaintext credential. Present only on the object that created it. */
        #[\SensitiveParameter]
        public readonly string $value,
        /** When an UNUSED credential stops working (null = no TTL configured). */
        public readonly ?CarbonInterface $expiresAt = null,
    ) {}

    public static function make(#[\SensitiveParameter] string $value, ?CarbonInterface $expiresAt = null): self
    {
        return new self($value, $expiresAt);
    }

    /**
     * Send the credential to a notifiable (a User/Client/Customer, or any model
     * with the `Notifiable` trait). Uses {@see resolveNotification()} — the
     * configurable {@see TemporaryPasswordNotification} by default, mail only;
     * point `credentials.passwords.notification` at a subclass to add SMS/etc.
     */
    public function send(object $notifiable, ?Notification $notification = null): void
    {
        $notifiable->notify($notification ?? $this->resolveNotification());
    }

    /**
     * Send to an off-model address (`->sendVia('mail', 'ops@acme.dev')`,
     * `->sendVia('vonage', '+52…')`) — the admin's inbox, say, when the
     * credential is for a service account with no notifiable of its own.
     */
    public function sendVia(string $channel, string $route, ?Notification $notification = null): void
    {
        $notification ??= $this->resolveNotification();
        $notifiable = NotificationFacade::route($channel, $route);

        // A channel the notification doesn't deliver over would be dropped
        // without a word, and the credential with it.
        $channels = method_exists($notification, 'via') ? (array) $notification->via($notifiable) : [];

        if (! in_array($channel, $channels, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s does not deliver over [%s]. Point kinetix.credentials.passwords.notification at a subclass whose via() includes it.',
                $notification::class,
                $channel,
            ));
        }

        $notifiable->notify($notification);
    }

    /**
     * Mail it to an address directly, the common case.
     */
    public function sendMail(string $email, ?Notification $notification = null): void
    {
        $this->sendVia('mail', $email, $notification);
    }

    /**
     * The notification used when none is passed: the host-overridable class
     * from config (a {@see TemporaryPasswordNotification} subclass, so it takes
     * the plaintext + expiry), else the built-in mail notification.
     */
    public function resolveNotification(): Notification
    {
        $configured = config('kinetix.credentials.passwords.notification');

        if ($configured !== null && ! (is_string($configured) && is_a($configured, TemporaryPasswordNotification::class, true))) {
            throw new InvalidArgumentException(
                'kinetix.credentials.passwords.notification must be '.TemporaryPasswordNotification::class.' or a subclass of it.',
            );
        }

        /** @var class-string<TemporaryPasswordNotification> $class */
        $class = $configured ?? TemporaryPasswordNotification::class;

        return new $class($this->value, $this->expiresAt);
    }

    /**
     * @return array{value: string, expiresAt: ?string}
     */
    public function toArray(): array
    {
        return [
            'value'     => $this->value,
            'expiresAt' => $this->expiresAt?->toIso8601String(),
        ];
    }

    /** Never let the plaintext end up in a log line or stack trace. */
    public function __toString(): string
    {
        return '[temporary credential redacted]';
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]', 'expiresAt' => $this->expiresAt?->toIso8601String()];
    }

    /**
     * A temporary credential is for the moment it was issued. Serialized into
     * a queue payload, a cache entry or a session, its plaintext would sit in
     * storage — deliver it now ({@see send()}) instead.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A temporary credential cannot be serialized. Deliver it when it is issued: send(), sendMail() or sendVia().');
    }
}
