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
 */
class MemberActivationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $activationUrl,
        public MemberProvision $provision,
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
        $app = (string) config('app.name', 'Kinetix');

        return (new MailMessage)
            ->subject(__('kinetix.member_activation_subject', ['app' => $app]))
            ->line(__('kinetix.member_activation_intro', ['app' => $app]))
            ->action(__('kinetix.member_activation_button'), $this->activationUrl)
            ->line(__('kinetix.member_activation_expiry'));
    }
}
