<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One-time login code of the Portal do cliente (PortalService::sendCode). */
class PortalCode extends Notification
{
    public function __construct(
        public string $code,
        public string $companyName,
        public int $minutes,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->code} é o seu código de acesso — {$this->companyName}")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Use o código abaixo para entrar no portal do cliente de {$this->companyName}:")
            ->line("**{$this->code}**")
            ->line("Ele vale por {$this->minutes} minutos. Se não foi você quem pediu, ignore este e-mail.")
            ->salutation($this->companyName);
    }
}
