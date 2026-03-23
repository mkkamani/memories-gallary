<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);
        $appName = 'Cypherox Memories';
        $passwords = (string) config('auth.defaults.passwords');
        $passwordConfig = (array) config('auth.passwords', []);
        $expireMinutes = (int) data_get($passwordConfig, $passwords . '.expire', 60);

        return (new MailMessage())
            ->subject("Reset your {$appName} password")
            ->view('emails.users.forgot-password', [
                'appName' => $appName,
                'name' => $notifiable->name,
                'logoUrl' => asset('images/cx-logo-dark.svg'),
                'resetUrl' => $resetUrl,
                'expireMinutes' => $expireMinutes,
            ]);
    }
}
