<?php

namespace App\Notifications;

use App\Models\SyncRun;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte envoyée à l'équipe quand un job de synchronisation part en dead-letter.
 */
class SyncJobDeadLettered extends Notification
{
    public function __construct(public readonly SyncRun $run) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("[Uimad Sync] Échec de synchronisation : {$this->run->type->label()}")
            ->line("Le job #{$this->run->id} ({$this->run->subject}) a échoué après {$this->run->attempts} tentative(s).")
            ->line('Erreur : '.($this->run->error ?? 'inconnue'))
            ->action('Ouvrir le journal', url("/admin/sync-runs/{$this->run->id}"))
            ->line('Corrigez la cause puis utilisez « Rejouer » depuis le tableau de bord.');
    }
}
