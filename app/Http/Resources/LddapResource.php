<?php

namespace App\Http\Resources;

use App\Models\Lddap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the LDDAP table.
 *
 * The check number comes from the LDDAP's own series (`lddap_checks`), not from the cheque
 * register — `check_no` here is unrelated to any `cheque_number`.
 *
 * @mixin Lddap
 */
class LddapResource extends JsonResource
{
    /**
     * The `wtax` and `vat` maps are keyed by rate ("0.01", "0.02", …). Laravel would otherwise
     * treat an all-numeric-string key set as a list and re-index it, losing the rates.
     */
    public $preserveKeys = true;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // `whenLoaded()` yields a MissingValue rather than null, which `?->` would not
        // short-circuit, so the relation is checked directly before it is read through.
        $acic = $this->relationLoaded('acic') ? $this->acic : null;

        return [
            'id' => $this->id,
            // Check No., from the independent LDDAP check series.
            'check_no' => $this->whenLoaded('lddapCheck', fn () => $this->lddapCheck?->check_no),
            'check_date' => $this->check_date?->toDateString(),

            'lddap_no' => $this->lddap_no,

            // The references the disbursement is drawn against.
            'nca_no' => $this->nca_no,
            'obr_no' => $this->obr_no,
            'dv_no' => $this->dv_no,
            'nature_of_payment' => $this->nature_of_payment?->value,
            'nature_of_payment_label' => $this->nature_of_payment?->label(),
            'unit_name' => $this->unit_name,

            // The UACS object code — what prints as OBJ CODE on the ACIC.
            'obj_no' => $this->obj_no,
            'amount' => $this->amount,
            // The teller's Action: who received it (forwarded to the payee), and its RTS outcome.
            'payee_received_by' => $this->payee_received_by,
            'payee_received_on' => $this->payee_received_on?->toDateString(),
            'rts_status' => $this->rts_status,

            // The payee and account as they stood when the record was registered.
            'payee_id' => $this->payee_id,
            'payee_name' => $this->payee_name,
            // "creditor" or "pcg_personnel" — which list the payee was chosen from. Blank on LDDAPs
            // registered before the payee came from those lists.
            'payee_type' => $this->payee_type,
            'payee_type_label' => match ($this->payee_type) {
                'creditor' => 'Creditor',
                'pcg_personnel' => 'PCG Personnel',
                default => null,
            },
            'payee_account_id' => $this->payee_account_id,
            'payee_account_no' => $this->payee_account_no,
            'payee_bank' => $this->payee_bank,
            'acic_ref' => $this->acic_ref,

            // The payment breakdown. `amount` is the net payable; gross less all of these.
            'gross_amount' => $this->gross_amount,
            'wtax' => [
                '0.01' => $this->wtax_1, '0.02' => $this->wtax_2,
                '0.03' => $this->wtax_3, '0.05' => $this->wtax_5,
            ],
            'vat' => [
                '0.01' => $this->vat_1, '0.02' => $this->vat_2, '0.03' => $this->vat_3,
                '0.05' => $this->vat_5, '0.10' => $this->vat_10, '0.12' => $this->vat_12,
                '0.30' => $this->vat_30,
            ],
            'retention' => $this->retention,
            'liquidated_damages' => $this->liquidated_damages,
            'advance_payment' => $this->advance_payment,

            'fwd_to_lbp_at' => $this->fwd_to_lbp_at?->toDateString(),
            'date_loaded' => $this->date_loaded?->toDateString(),
            'note' => $this->note,
            'remarks' => $this->remarks,
            'status' => $this->status->value,
            // Whether "Edit LDDAP Record" is offered: Registered or RTS only.
            'can_edit' => $this->status->canEdit(),

            'used_by' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->only(['id', 'name', 'username'])),
            'used_at' => $this->used_at,

            // Teller receipt confirmation (separate lifecycle event).
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->only(['id', 'name', 'username'])),
            'received_at' => $this->received_at,
            'is_received' => $this->isReceived(),

            // The admin's verdict, when there is one (Approved or Canceled).
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->only(['id', 'name', 'username'])),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'is_final' => $this->status->isFinal(),
            'status_label' => $this->status->label(),
            // Which next step the status allows: RTS / Cancel / Assign on For Signature, Resubmit on RTS.
            'awaits_action' => $this->status->awaitsAction(),
            'can_resubmit' => $this->status->canResubmit(),
            'is_acic_eligible' => $this->status->isAcicEligible() && $this->acic_id === null,
            // (The old Forward / Receive data stays in the database and is not sent to any page.)

            // The cancellation, when there is one.
            'canceled_by' => $this->whenLoaded('canceledBy', fn () => $this->canceledBy?->only(['id', 'name', 'username'])),
            'date_canceled' => $this->date_canceled?->toDateString(),
            'cancel_reason' => $this->cancel_reason,

            // ACIC No. and Forward To / Date.
            'acic_id' => $this->acic_id,
            'acic_number' => $acic?->acic_number,
            'acic_status' => $acic?->status->value,
            'forwarded_at' => $acic?->forwarded_at,
            'forwarded_to' => $acic?->recipientName(),

            // Present only when the counts were loaded (list view).
            'has_pending_update' => $this->when(
                $this->pending_update_count !== null,
                fn () => (int) $this->pending_update_count > 0,
            ),
            // How many times the record has been returned to sender.
            'rts_count' => $this->when($this->rts_count !== null, fn () => (int) $this->rts_count),

            'created_at' => $this->created_at,
        ];
    }
}
