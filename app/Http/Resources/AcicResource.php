<?php

namespace App\Http\Resources;

use App\Models\Acic;
use App\Support\AmountInWords;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Acic
 */
class AcicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'acic_number' => $this->acic_number,
            'status' => $this->status->value,
            'used_by' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->only(['id', 'name', 'username'])),
            'used_at' => $this->used_at,
            'created_at' => $this->created_at,
            'forwarded_at' => $this->forwarded_at,
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->only(['id', 'name', 'username'])),
            // Free-text recipient, used when the ACIC was handed to someone who is not a user.
            'received_name' => $this->received_name,
            'forwarded_to' => $this->recipientName(),
            'completed_at' => $this->completed_at,
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy?->only(['id', 'name', 'username'])),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->only(['id', 'name', 'username'])),
            // Sitting with the teller, waiting to be completed.
            'awaits_teller' => $this->status->awaitsTeller(),
            // What the ACIC carries. An ACIC may hold cheques, LDDAPs, or both.
            'cheque_count' => $this->whenCounted('cheques'),
            'lddap_count' => $this->whenCounted('lddaps'),
            'cheques' => ChequeResource::collection($this->whenLoaded('cheques')),
            'lddaps' => LddapResource::collection($this->whenLoaded('lddaps')),

            // Everything the printed ACIC form needs that isn't on the record itself. Only the
            // detail view loads the memberships this is computed from.
            'form' => $this->when(
                $this->relationLoaded('cheques') && $this->relationLoaded('lddaps'),
                fn () => $this->form(),
            ),

            // What the ACIC carries, and where it stands with the tellers and the bank.
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'teller_status' => $this->teller_status?->value,
            'teller_status_label' => $this->teller_status?->label(),
            'forwarded_to_land_bank_at' => $this->forwarded_to_land_bank_at,
            'transmittal_no' => $this->transmittal_no,
            'land_bank_note' => $this->land_bank_note,
            'returned_by_bank_at' => $this->returned_by_bank_at,
            'bank_return_reason' => $this->bank_return_reason,
            'credited_at' => $this->credited_at,
            'bank_confirmation_no' => $this->bank_confirmation_no,
            'confirmed_by' => $this->confirmedBy?->only(['id', 'name']),
            'completion_note' => $this->completion_note,
            'total_records' => ($this->cheque_count ?? 0) + ($this->lddap_count ?? 0),

            // Branch B — the ACIC as a whole goes to the tellers.
            'forwarded_to_teller_at' => $this->forwarded_to_teller_at,
            'forwarded_to_teller_by' => $this->whenLoaded('forwardedToTellerBy', fn () => $this->forwardedToTellerBy?->only(['id', 'name'])),
            'forward_note' => $this->forward_note,
            // Null while it is still Pending for every teller.
            'accepted_by' => $this->acceptedBy?->only(['id', 'name']),
            'accepted_at' => $this->accepted_at,
            'deposit_date' => $this->deposit_date?->toDateString(),
            'deposit_bank' => $this->deposit_bank,
            'deposit_reference' => $this->deposit_reference,
            'deposit_note' => $this->deposit_note,
            'returned_to_admin_at' => $this->returned_to_admin_at,
            'return_reason' => $this->return_reason,
        ];
    }

    /**
     * The printed form's header block, totals and signatories.
     *
     * @return array<string, mixed>
     */
    private function form(): array
    {
        $rows = $this->cheques->count() + $this->lddaps->count();
        $total = $this->cheques->sum(fn ($c) => (float) $c->amount)
            + $this->lddaps->sum(fn ($l) => (float) $l->amount);

        $prepared = $this->created_at;

        return [
            'bank_name' => config('acic.bank.name'),
            'bank_branch' => config('acic.bank.branch'),
            'bank_address' => config('acic.bank.address'),
            'agency_name' => config('acic.agency.name'),
            'agency_address' => config('acic.agency.address'),

            'date_prepared' => $prepared?->format('n/j/Y'),
            // The form's ACIC number reads YY-MM-SEQ, e.g. 25-10-248.
            'acic_no' => $prepared === null
                ? (string) $this->acic_number
                : sprintf('%s-%s-%d', $prepared->format('y'), $prepared->format('m'), $this->acic_number),

            'org_code' => config('acic.org_code'),
            'funding_source' => config('acic.funding_source'),
            'area_code' => config('acic.area_code'),
            'allocation_no' => config('acic.allocation_no'),
            'account_no' => config('acic.account_no'),

            'total_amount' => number_format($total, 2, '.', ','),
            'total_checks' => $rows,
            'amount_in_words' => AmountInWords::pesos($total),

            'certified_by' => config('acic.certified_by'),
            'approved_by' => config('acic.approved_by'),
            'filename' => sprintf(
                '%s %s-%d.txt',
                config('acic.filename_prefix'),
                $prepared?->format('m') ?? '00',
                $this->acic_number,
            ),
        ];
    }
}
