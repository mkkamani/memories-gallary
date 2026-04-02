<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewUserAccountCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $plainPassword,
    ) {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $appName = 'Cypherox Memories';

        return (new MailMessage())
            ->subject("Welcome to Memories by Cypherox")
            ->view('emails.users.account-created', [
                'appName' => $appName,
                'name' => $notifiable->name,
                'email' => $notifiable->email,
                'password' => $this->plainPassword,
                'logoUrl' => asset('images/cx-logo-dark.svg'),
                'loginUrl' => route('home'),
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
