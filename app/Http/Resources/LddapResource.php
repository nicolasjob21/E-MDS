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
            'orb_no' => $this->orb_no,
            'dv_no' => $this->dv_no,
            'nature_of_payment' => $this->nature_of_payment?->value,
            'nature_of_payment_label' => $this->nature_of_payment?->label(),
            'unit_id' => $this->unit_id,
            'unit_name' => $this->whenLoaded('unit', fn () => $this->unit?->name),

            // The UACS object code — what prints as OBJ CODE on the ACIC.
            'obj_no' => $this->obj_no,
            'amount' => $this->amount,

            // The payee and account as they stood when the record was registered.
            'payee_id' => $this->payee_id,
            'payee_name' => $this->payee_name,
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
            // Which single next step the status allows.
            'can_forward' => $this->status->canForward(),
            'can_receive' => $this->status->canReceive(),
            'awaits_action' => $this->status->awaitsAction(),

            // The most recent forward and return.
            'forward_to' => $this->forward_to,
            'forward_unit_name' => $this->whenLoaded('forwardUnit', fn () => $this->forwardUnit?->name),
            'forwarded_by' => $this->whenLoaded('forwardedBy', fn () => $this->forwardedBy?->only(['id', 'name', 'username'])),
            'date_forwarded' => $this->date_forwarded?->toDateString(),
            'return_unit_name' => $this->whenLoaded('returnUnit', fn () => $this->returnUnit?->name),
            'returned_by' => $this->whenLoaded('returnedBy', fn () => $this->returnedBy?->only(['id', 'name', 'username'])),
            'date_returned' => $this->date_returned?->toDateString(),

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
