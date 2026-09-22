<?php

namespace App\Services;

use App\Enums\AcicStatus;
use App\Enums\ChequeStatus;
use App\Enums\LddapStatus;
use App\Enums\RequestStatus;
use App\Http\Resources\ChequeResource;
use App\Models\Acic;
use App\Models\Cheque;
use App\Models\ChequeLog;
use App\Models\ChequeUpdateRequest;
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
                'next' => $next ? new ChequeResource($next) : null,
            ],
            'lddaps' => [
                'counts' => $this->lddapCounts(),
                'awaiting_acic' => Lddap::query()->where('status', LddapStatus::Approved)->whereNull('acic_id')->count(),
                'on_acic' => Lddap::query()->whereNotNull('acic_id')->count(),
            ],
            'acics' => [
                'counts' => $this->acicCounts(),
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
                $this->item('lddap_action', 'LDDAPs awaiting your action', 'Back from routing — Approve, RTS or Cancel', Lddap::query()->where('status', LddapStatus::ReturnedForAcic), '/lddaps?status=returned_for_acic', 'accent'),
                $this->item('cheque_review', 'Cheques awaiting review', 'Used or received, not yet reviewed', Cheque::query()->whereIn('status', [ChequeStatus::Used, ChequeStatus::Received]), '/cheques?status=used', 'accent'),
                $this->item('update_requests', 'Update requests pending', 'Corrections proposed by staff', null, '/admin/update-requests', 'brand', ChequeUpdateRequest::query()->where('status', RequestStatus::Pending)->count() + LddapUpdateRequest::query()->where('status', RequestStatus::Pending)->count()),
                $this->item('acic_signoff', 'ACICs to sign off', 'Used, awaiting approval before they can be forwarded or printed', Acic::query()->where('status', AcicStatus::Used), '/acics?tab=all', 'brand'),
            ],
            $user->isTeller() => [
                $this->item('cheques_to_receive', 'Cheques to receive', 'Used, not yet confirmed received', Cheque::query()->where('status', ChequeStatus::Used), '/cheques?status=used', 'accent'),
                $this->item('lddaps_to_receive', 'LDDAPs to receive', 'Carrying a check number, not yet confirmed received', Lddap::query()->whereNotNull('lddap_check_id')->whereNull('received_at'), '/lddaps?status=approved', 'accent'),
                $this->item('acics_forwarded', 'ACICs forwarded to you', 'Awaiting completion', Acic::query()->where('status', AcicStatus::Forwarded), '/acics?tab=forwarded', 'brand'),
            ],
            default => [
                $this->item('my_rts', 'Returned to you (RTS)', 'Correct the details, then forward again', Lddap::query()->where('status', LddapStatus::Rts)->where('used_by', $user->id), '/lddaps?status=rts', 'warn'),
                $this->item('cheques_returned', 'Cheques returned to you', 'Update the details for the admin to confirm', Cheque::query()->where('status', ChequeStatus::Complies)->where('used_by', $user->id), '/cheques?status=complies', 'warn'),
                $this->item('my_registered', 'Registered, not yet forwarded', 'Your LDDAPs still to go out for routing', Lddap::query()->where('status', LddapStatus::Registered)->where('used_by', $user->id), '/lddaps?status=registered', 'brand'),
                $this->item('awaiting_acic', 'Approved LDDAPs awaiting an ACIC', 'Assign them to take their check numbers', Lddap::query()->where('status', LddapStatus::Approved)->whereNull('acic_id'), '/lddaps?status=approved', 'brand'),
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

    /** @return array<string, int> every ACIC status, zero included */
    private function acicCounts(): array
    {
        $rows = Acic::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $counts = [];
        foreach (AcicStatus::cases() as $case) {
            $counts[$case->value] = (int) ($rows[$case->value] ?? 0);
        }

        return ['total' => array_sum($counts)] + $counts;
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
