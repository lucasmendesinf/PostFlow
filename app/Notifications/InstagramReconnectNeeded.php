<?php

namespace App\Notifications;

use App\Models\InstagramAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstagramReconnectNeeded extends Notification
{
    use Queueable;

    public function __construct(public InstagramAccount $conta, public string $motivo) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $usuario = $this->conta->username ? '@'.$this->conta->username : 'sua conta';

        return (new MailMessage)
            ->subject("PostFlow: reconecte o Instagram {$usuario}")
            ->greeting('Olá!')
            ->line("Não conseguimos renovar o acesso ao Instagram {$usuario}. Enquanto a conta não for reconectada, os posts dela não serão publicados.")
            ->line("Motivo informado pela Meta: {$this->motivo}")
            ->line('Entre no painel do PostFlow, abra "Contas Instagram" e clique em "Reconectar".');
    }
}
