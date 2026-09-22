<?php

namespace App\Http\Requests;

use App\Enums\NatureOfPayment;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The LDDAP record's details — the one form behind both **Register LDDAP Record** and **Edit
 * LDDAP Record**, so the two never drift apart. No check number here: it is only ever set by
 * "Assign LDDAP to ACIC".
 *
 * On an edit the route carries the record, and its own LDDAP number is not a duplicate of
 * itself; whether the record may be edited at all (Registered or RTS only) is the service's
 * call.
 */
class LddapDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::Admin, UserRole::Staff], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Unique across the register; `lddaps_lddap_no_unique` backs this, and the service
            // re-checks under a lock, so a duplicate is refused before it can reach the index.
            'lddap_no' => ['required', 'string', 'max:100', Rule::unique('lddaps', 'lddap_no')->ignore($this->route('lddap'))],

            // The references the disbursement is drawn against.
            'nca_no' => ['required', 'string', 'max:100'],
            'orb_no' => ['required', 'string', 'max:100'],
            'dv_no' => ['required', 'string', 'max:100'],
            'nature_of_payment' => ['required', Rule::enum(NatureOfPayment::class)],

            // The UACS object code — what prints as OBJ CODE on the ACIC.
            'obj_no' => ['nullable', 'string', 'max:100'],

            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'check_date' => ['required', 'date'],

            // A registered payee, chosen from the lookup, and which of their accounts the
            // payment goes to. The account may be left out when the payee has exactly one —
            // the service picks it — and must belong to the payee when given.
            'payee_id' => ['required', 'integer', Rule::exists('payees', 'id')],
            'payee_account_id' => [
                'nullable',
                'integer',
                Rule::exists('payee_accounts', 'id')->where(
                    fn ($q) => $q->where('payee_id', (int) $this->input('payee_id')),
                ),
            ],
            'acic_ref' => ['nullable', 'string', 'max:100'],

            // The payment breakdown. Gross is what the claim is for; everything else comes off
            // it, and the service derives the net payable (`amount`) from them.
            'gross_amount' => ['required', ...self::MONEY, 'min:0.01'],
            'wtax_1' => self::MONEY,
            'wtax_2' => self::MONEY,
            'wtax_3' => self::MONEY,
            'wtax_5' => self::MONEY,
            'vat_1' => self::MONEY,
            'vat_2' => self::MONEY,
            'vat_3' => self::MONEY,
            'vat_5' => self::MONEY,
            'vat_10' => self::MONEY,
            'vat_12' => self::MONEY,
            'vat_30' => self::MONEY,
            'retention' => self::MONEY,
            'liquidated_damages' => self::MONEY,
            'advance_payment' => self::MONEY,

            'fwd_to_lbp_at' => ['nullable', 'date'],
            'date_loaded' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** A non-negative amount with at most two decimals, as the form's number inputs produce. */
    private const MONEY = ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'];

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lddap_no.required' => 'Enter the LDDAP number.',
            'lddap_no.unique' => 'LDDAP number :input has already been registered. Each LDDAP number can be used only once.',
            'nca_no.required' => 'Enter the NCA number.',
            'orb_no.required' => 'Enter the ORB number.',
            'dv_no.required' => 'Enter the DV number.',
            'nature_of_payment.required' => 'Choose the nature of payment.',
            'nature_of_payment.Illuminate\\Validation\\Rules\\Enum' => 'Choose a nature of payment from the list.',
            'unit_id.required' => 'Choose the unit.',
            'unit_id.exists' => 'Choose a unit from the list.',
            'check_date.required' => 'Enter the date issued.',
            'payee_id.required' => 'Choose a registered payee.',
            'payee_id.exists' => 'Choose a payee from the lookup.',
            'payee_account_id.exists' => 'Choose one of the selected payee\'s accounts.',
            'gross_amount.required' => 'Enter the gross amount.',
            'gross_amount.min' => 'The gross amount must be greater than zero.',
            '*.decimal' => 'Amounts carry at most two decimal places.',
        ];
    }
}
