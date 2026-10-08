<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Happones\Kinetix\Membership\MemberActivationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Delivers a temporary password to its recipient. Mail out of the box; every
 * string is translatable and the layout is the framework's `MailMessage`, so it
 * inherits the app's mail theme.
 *
 * ## Sending it over something other than mail
 *
 * Like {@see MemberActivationNotification}, Kinetix
 * ships `toMail()` and the message TEXT (`lines()`), but does NOT pick an SMS
 * provider — point `credentials.passwords.notification` at a subclass that adds
 * your channel and widen `via()`:
 *
 *     class SmsTemporaryPassword extends TemporaryPasswordNotification
 *     {
 *         public function via(object $notifiable): array { return ['vonage']; }
 *         public function toVonage(object $notifiable): VonageMessage
 *         {
 *             return (new VonageMessage)->content($this->smsContent());
 *         }
 *     }
 */
class TemporaryPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $password,
        public ?Carbon $expiresAt = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('kinetix.temporary_password_subject'))
            ->greeting(__('kinetix.temporary_password_greeting'))
            ->line(__('kinetix.temporary_password_intro'))
            ->line('**'.$this->password.'**');

        if ($this->expiresAt !== null) {
            $mail->line(__('kinetix.temporary_password_expires', [
                'when' => $this->expiresAt->toDayDateTimeString(),
            ]));
        }

        return $mail->line(__('kinetix.temporary_password_outro'));
    }

    /**
     * Plain-text body for SMS-style channels (used by host subclasses).
     */
    public function smsContent(): string
    {
        $text = __('kinetix.temporary_password_intro').' '.$this->password;

        if ($this->expiresAt !== null) {
            $text .= ' — '.__('kinetix.temporary_password_expires', [
                'when' => $this->expiresAt->toDayDateTimeString(),
            ]);
        }

        return $text;
    }
}
