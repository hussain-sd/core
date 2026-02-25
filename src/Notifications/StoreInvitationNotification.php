<?php

namespace SmartTill\Core\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SmartTill\Core\Models\Invitation;

class StoreInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Invitation $invitation
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $store = $this->invitation->store;

        $registrationUrl = route('filament.store.auth.register', [
            'tenant' => $store->slug,
            'token' => $this->invitation->token,
            'email' => $this->invitation->email,
        ]);

        return (new MailMessage)
            ->subject("You're invited to join {$store->name}")
            ->greeting('Hello!')
            ->line("You have been invited to join the store \"{$store->name}\".")
            ->line('Click the button below to accept the invitation and create or sign in to your account:')
            ->action('Accept Invitation', $registrationUrl)
            ->line('This invitation will expire on '.$this->invitation->expires_at->timezone(config('app.timezone'))->toDayDateTimeString().'.')
            ->line('If you did not expect this invitation, you can safely ignore this email.');
    }
}
