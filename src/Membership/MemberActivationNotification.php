<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The single-use, expiring activation link sent to a provisioned member. The URL
 * is a Laravel temporary signed route, so there is no bespoke token to store or
 * leak — validity is the signature plus the provision's `Pending` status.
 *
 * ## Sending it over something other than mail
 *
 * `via()` returns whatever channel the caller asked for, so the same
 * notification can go out by SMS. What Kinetix deliberately does NOT do is pick
 * an SMS provider: Vonage, Twilio and the local gateways each define their own
 * channel and their own message object, they are mutually incompatible, and the
 * right one is a business decision about coverage and price in your country.
 *
 * So Kinetix ships `toMail()` and the message TEXT, and your channel's method
 * is a few lines in a subclass pointed at by
 * `membership.activation_notification`:
 *
 *     class SmsActivation extends MemberActivationNotification
 *     {
 *         public function toVonage(object $notifiable): VonageMessage
 *         {
 *             return (new VonageMessage)->content($this->smsContent());
 *         }
 *     }
 *
 * A channel whose `to…()` method is missing is caught before anything is sent —
 * the link is handed back to the admin instead, never silently dropped.
 */
class MemberActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $activationUrl,
        public MemberProvision $provision,
        /** The notification channel this instance goes out on. */
        public string $channel = 'mail',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) config('app.name', 'Kinetix');

        return (new MailMessage)
            ->subject(__('kinetix.member_activation_subject', ['app' => $app]))
            ->line(__('kinetix.member_activation_intro', ['app' => $app]))
            ->action(__('kinetix.member_activation_button'), $this->activationUrl)
            ->line(__('kinetix.member_activation_expiry'));
    }

    /**
     * The activation message as one line of plain text, for whichever SMS
     * channel you use to wrap in its own message object.
     *
     * Kept deliberately short: a signed URL is already long, and every
     * character before it pushes the message towards a second (billable)
     * segment.
     */
    public function smsContent(): string
    {
        return __('kinetix.member_activation_sms', [
            'app' => (string) config('app.name', 'Kinetix'),
            'url' => $this->activationUrl,
        ]);
    }
}
