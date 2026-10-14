<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(private string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = rtrim(config('field.web_url'), '/').'/#/reset-password?'.http_build_query([
            'token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)->subject('Reset your password')
            ->line('A password reset was requested for your account.')
            ->action('Reset password', $url)
            ->line('This link expires in 60 minutes. If you did not request it, no action is needed.');
    }
}
