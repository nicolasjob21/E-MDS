<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
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
     * @param  'request'|'approved'|'rejected'|'used'  $kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $url = null,
        public readonly ?int $chequeNumber = null,
    ) {}

    /**
     * Store in the database only — surfaced via the API, no email/broadcast.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
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
