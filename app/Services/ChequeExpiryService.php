<?php

namespace App\Services;

use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Support\Validity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * The 90-day clock, swept once a night: cheques that have run out are marked stale, and
 * cheques with ten days or fewer left are alerted on — once each, never again.
 *
 * Both passes are **idempotent**. Staleness is only written where it is not already written,
 * and the alert is stamped on the cheque (`expiry_alert_sent_at`), so running the sweep twice
 * in a day changes nothing and notifies nobody a second time.
 */
class ChequeExpiryService
{
    public function __construct(private readonly ChequeStaleService $flow) {}

    /**
     * Both passes, in order: expire first, then alert — so a cheque that runs out tonight is
     * reported as stale rather than as "expiring soon".
     *
     * @return array{staled: int, alerted: int}
     */
    public function sweep(bool $notify = true): array
    {
        return [
            'staled' => $this->markExpired($notify),
            'alerted' => $this->sendExpiryAlerts($notify),
        ];
    }

    /**
     * Every perishable cheque past its last valid day becomes stale.
     *
     * @return int how many were marked on this run — 0 on a second run the same day
     */
    public function markExpired(bool $notify = true): int
    {
        $marked = 0;

        Cheque::query()->expired()->orderBy('id')->chunkById(200, function ($cheques) use (&$marked, $notify) {
            foreach ($cheques as $cheque) {
                // markStale() re-reads the row under a lock and returns early if another
                // process got there first, so a double run cannot double-log.
                $before = $cheque->status;
                $this->flow->markStale($cheque, null, $notify);

                if ($before->isPerishable()) {
                    $marked++;
                }
            }
        });

        return $marked;
    }

    /**
     * The one-time ten-day warning, for cheques still in play.
     *
     * @return int how many cheques were alerted on
     */
    public function sendExpiryAlerts(bool $notify = true): int
    {
        $sent = 0;

        Cheque::query()
            ->expiringSoon()
            ->whereNull('expiry_alert_sent_at')
            ->with(['usedBy', 'releasedBy', 'forwardedTo'])
            ->orderBy('id')
            ->chunkById(200, function ($cheques) use (&$sent, $notify) {
                foreach ($cheques as $cheque) {
                    // Stamp first: the stamp is what makes this run-twice-safe, and a cheque
                    // whose alert cannot be delivered should still not be alerted on daily.
                    $stamped = Cheque::query()
                        ->whereKey($cheque->getKey())
                        ->whereNull('expiry_alert_sent_at')
                        ->update(['expiry_alert_sent_at' => Carbon::now()]);

                    if ($stamped === 0) {
                        continue;
                    }

                    if ($notify) {
                        $this->alert($cheque);
                    }

                    $sent++;
                }
            });

        return $sent;
    }

    /** Send one cheque's ten-day warning to everyone who can do something about it. */
    private function alert(Cheque $cheque): void
    {
        $days = $cheque->daysLeft() ?? 0;
        $when = $days === 0 ? 'today' : "in {$days} ".($days === 1 ? 'day' : 'days');

        $because = match ($cheque->status) {
            ChequeStatus::Registered, ChequeStatus::OutForSignature => "Pending signature — this cheque will become stale {$when} if not released or forwarded.",
            ChequeStatus::ReleasedToPayee => "Released to {$cheque->received_by_name} on ".
                ($cheque->date_received?->toDateString() ?? '—').
                " — this cheque will become stale {$when} if not encashed. Please follow up with the payee.",
            ChequeStatus::ForwardedToTeller, ChequeStatus::AcceptedByTeller => "Awaiting teller deposit — this cheque will become stale {$when} if not deposited to ".
                AcicService::BANK.'.',
            default => "This cheque will become stale {$when}.",
        };

        $amount = $cheque->amount !== null ? '₱'.number_format((float) $cheque->amount, 2) : '—';

        $detail = sprintf(
            '%s Cheque #%d · %s · %s · dated %s · valid until %s · %s · %s.',
            $because,
            $cheque->cheque_number,
            $cheque->payee_name ?? '—',
            $amount,
            $cheque->cheque_date?->toDateString() ?? '—',
            $cheque->validity_until?->toDateString() ?? '—',
            Validity::countdown($cheque->validity_until) ?? '—',
            $cheque->status->label() ?? '—',
        );

        Notification::send($this->recipients($cheque), new ActivityNotification(
            kind: 'expiring',
            title: "Cheque #{$cheque->cheque_number} expires ".($days === 0 ? 'today' : "in {$days} days"),
            message: $detail,
            url: '/cheques?tab=expiring',
            chequeNumber: $cheque->cheque_number,
        ));
    }

    /**
     * Who hears about it: whoever assigned the number and the admins (who are also this
     * system's signatories), plus the one person the current stage depends on.
     *
     * @return Collection<int, User>
     */
    private function recipients(Cheque $cheque)
    {
        $users = User::query()->activeAdmins()->get();

        $users->push($cheque->usedBy);

        $users->push(match ($cheque->status) {
            // Released: the person who handed it over follows the payee up.
            ChequeStatus::ReleasedToPayee => $cheque->releasedBy,
            // For deposit: the teller sitting on it.
            ChequeStatus::ForwardedToTeller, ChequeStatus::AcceptedByTeller => $cheque->forwardedTo,
            default => null,
        });

        return $users->filter()->unique('id')->values();
    }
}
