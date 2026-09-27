<?php

namespace App\Services;

use App\Enums\AcicStatus;
use App\Enums\AcicTellerStatus;
use App\Enums\ChequeStatus;
use App\Enums\LddapStatus;
use App\Enums\RequestStatus;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\ChequeLog;
use App\Models\Lddap;
use App\Models\LddapUpdateRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard: what is waiting on the signed-in user, and where every register stands —
 * cheques, LDDAPs, ACICs and the three number series. Read-only; one query per count.
 */
class DashboardService
{
    /** Below this many free numbers a series is flagged as running low. */
    public const LOW_SERIES = 10;

    /** How many audit rows the admin's "recent activity" shows. */
    private const RECENT = 8;

    public function __construct(
        private readonly ChequeService $cheques,
        private readonly LddapService $lddaps,
        private readonly AcicService $acics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $next = $this->cheques->nextAvailable();
        $lddapSeries = $this->lddaps->seriesCounts();
        $acicSeries = $this->acics->series();
        $chequeCounts = $this->cheques->counts();

        return [
            'attention' => $this->attention($user),
            'cheques' => [
                'counts' => $chequeCounts,
            ],
            'lddaps' => [
                'counts' => $this->lddapCounts(),
                'awaiting_acic' => Lddap::query()->where('status', LddapStatus::ForSignature)->whereNull('acic_id')->count(),
                'on_acic' => Lddap::query()->whereNotNull('acic_id')->count(),
            ],
            'acics' => [
                'counts' => $this->acicCounts($user),
            ],
            'series' => [
                'cheques' => [
                    'available' => $chequeCounts[ChequeStatus::Available->value],
                    'next' => $next?->cheque_number,
                    'low' => $chequeCounts[ChequeStatus::Available->value] < self::LOW_SERIES,
                ],
                'lddap_checks' => $lddapSeries + [
                    'next' => $this->lddaps->nextNumbers(1)[0] ?? null,
                    'low' => $lddapSeries['available'] < self::LOW_SERIES,
                ],
                'acic_numbers' => $acicSeries + [
                    'next' => $this->acics->nextNumber(),
                    'low' => $acicSeries['available'] < self::LOW_SERIES,
                ],
            ],
            'recent' => $user->isAdmin() ? $this->recent() : [],
        ];
    }

    /**
     * What is waiting on this user, by role — each with where to go to deal with it. Only
     * items with something in them are returned, so an empty list means "nothing waiting".
     *
     * @return list<array{key: string, label: string, hint: string, count: int, to: string, tone: string}>
     */
    private function attention(User $user): array
    {
        $items = match (true) {
            $user->isAdmin() => [
                $this->item('lddap_for_signature', 'LDDAPs For Signature', 'Assign them to an ACIC — or RTS / Cancel', Lddap::query()->where('status', LddapStatus::ForSignature)->whereNull('acic_id'), '/lddaps?status=for_signature', 'accent'),
                $this->item('cheque_checking', 'Drafts for checking', 'Approve each draft, or return it with a comment', Cheque::query()->where('status', ChequeStatus::ForChecking), '/cheques?tab=for_checking', 'accent'),
                $this->item('cheque_compliance', 'Cheques For Compliance', 'Returned drafts — update them and print a new draft', Cheque::query()->where('status', ChequeStatus::ForCompliance), '/cheques?tab=for_compliance', 'warn'),
                $this->item('cheque_final_print', 'Cheques For Final Print', 'Approved drafts — print them for real', Cheque::query()->where('status', ChequeStatus::ForFinalPrint), '/cheques?tab=for_final_print', 'brand'),
                $this->item('cheque_for_signature', 'Cheques For Signature', 'Printed — assign them to an ACIC', Cheque::query()->where('status', ChequeStatus::ForSignature)->whereNull('acic_id'), '/cheques?tab=for_signature', 'brand'),
                $this->item('update_requests', 'Update requests pending', 'LDDAP corrections proposed by staff', LddapUpdateRequest::query()->where('status', RequestStatus::Pending), '/admin/update-requests', 'brand'),
                $this->item('acic_signoff', 'ACICs to sign off', 'Used, awaiting approval before they can be forwarded or printed', Acic::query()->where('status', AcicStatus::Used), '/acics?status=used', 'brand'),
            ],
            $user->isTeller() => [
                $this->item('acics_to_accept', 'ACICs waiting to be accepted', 'Pending — cheque and LDDAP ACICs; the first teller to accept takes it', Acic::query()->where('teller_status', AcicTellerStatus::Pending), '/acics?status=pending', 'accent'),
                $this->item('acics_to_deposit', 'ACICs you are holding', 'Accepted — forward them, or take the Action on those out', Acic::query()->where('accepted_by', $user->id)->whereIn('teller_status', [AcicTellerStatus::AcceptedByTeller, AcicTellerStatus::ForwardedToLandBank, AcicTellerStatus::ForwardedToPayee, AcicTellerStatus::Rts]), '/deposit-queue', 'accent'),
                $this->item('lddaps_to_receive', 'LDDAPs to receive', 'Carrying a check number, not yet confirmed received', Lddap::query()->whereNotNull('lddap_check_id')->whereNull('received_at'), '/lddaps?status=approved', 'accent'),
            ],
            default => [
                $this->item('my_rts', 'Returned to you (RTS)', 'Correct the details, then resubmit', Lddap::query()->where('status', LddapStatus::Rts)->where('used_by', $user->id), '/lddaps?status=rts', 'warn'),
                $this->item('cheques_compliance', 'Your cheques For Compliance', 'Returned drafts — update them and print a new draft', Cheque::query()->where('status', ChequeStatus::ForCompliance)->where('used_by', $user->id), '/cheques?tab=for_compliance', 'warn'),
                $this->item('cheques_final_print', 'Your cheques For Final Print', 'Approved drafts — print them for real', Cheque::query()->where('status', ChequeStatus::ForFinalPrint)->where('used_by', $user->id), '/cheques?tab=for_final_print', 'brand'),
                $this->item('awaiting_acic', 'LDDAPs For Signature', 'Assign them to an ACIC to take their check numbers', Lddap::query()->where('status', LddapStatus::ForSignature)->whereNull('acic_id'), '/lddaps?status=for_signature', 'brand'),
            ],
        };

        return array_values(array_filter($items, fn (array $item) => $item['count'] > 0));
    }

    /**
     * @param  Builder<Cheque>|Builder<Lddap>|Builder<Acic>|null  $query
     * @return array{key: string, label: string, hint: string, count: int, to: string, tone: string}
     */
    private function item(string $key, string $label, string $hint, ?Builder $query, string $to, string $tone, ?int $count = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'hint' => $hint,
            'count' => $count ?? $query?->count() ?? 0,
            'to' => $to,
            'tone' => $tone,
        ];
    }

    /** @return array<string, int> every LDDAP status, zero included */
    private function lddapCounts(): array
    {
        $rows = Lddap::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $counts = [];
        foreach (LddapStatus::cases() as $case) {
            $counts[$case->value] = (int) ($rows[$case->value] ?? 0);
        }

        return ['total' => array_sum($counts)] + $counts;
    }

    /**
     * The ACIC tiles, by the status the ACIC table shows (the teller's, once it is with the
     * tellers), over the ACICs the viewer may see.
     *
     * @return array<string, int>
     */
    private function acicCounts(User $user): array
    {
        $counts = [];
        foreach (['open', 'used', 'approved', 'pending', 'completed'] as $status) {
            $counts[$status] = Acic::query()->visibleTo($user)->inDisplayStatus($status)->count();
        }

        return ['total' => Acic::query()->visibleTo($user)->count()] + $counts;
    }

    /**
     * The latest audit rows, newest first.
     *
     * @return list<array{id: int, username: string, action: string, cheque_number: int|null, description: string|null, created_at: string|null}>
     */
    private function recent(): array
    {
        return ChequeLog::query()
            ->latest('id')
            ->limit(self::RECENT)
            ->get()
            ->map(fn (ChequeLog $log) => [
                'id' => $log->id,
                'username' => $log->username,
                'action' => $log->action->value,
                'cheque_number' => $log->cheque_number,
                'description' => $log->description,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
