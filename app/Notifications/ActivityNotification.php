<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A single in-app notification stored in the `notifications` table and surfaced in the
 * header bell dropdown. Deliberately generic: the caller supplies the kind, title, message,
 * and an optional SPA link. `kind` drives the coloured dot in the UI.
 */
class ActivityNotification extends Notification
{
    use Queueable;

    /**
     * @param  'request'|'approved'|'rejected'|'used'|'expiring'|'stale'  $kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $url = null,
        public readonly ?int $chequeNumber = null,
    ) {}

    /**
     * In-app by default — stored in `notifications` and surfaced in the header bell.
     *
     * Expiry and stale alerts may also go out by email, which is an opt-in setting
     * (`cheques.alert_email`, off by default) and needs a configured mailer.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (in_array($this->kind, ['expiring', 'stale'], true)
            && config('cheques.alert_email')
            && filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** The optional email form of an expiry or stale alert. */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->line($this->message)
            ->action('Open the cheque register', url('/cheques'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'cheque_number' => $this->chequeNumber,
        ];
    }
}
