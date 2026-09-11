<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrganizationOwnerInvitation extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Organization $organization,
        private readonly string $plaintextToken,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/accept-invitation?token='.urlencode($this->plaintextToken);

        return (new MailMessage)
            ->subject('You are invited to '.$this->organization->name)
            ->greeting('You are invited')
            ->line('A platform administrator invited you to become the initial organization owner for '.$this->organization->name.'.')
            ->action('Accept invitation', $url)
            ->line('This invitation expires in seven days and can be used once.');
    }
}
