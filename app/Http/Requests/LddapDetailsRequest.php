<?php

namespace App\Http\Requests;

use App\Enums\NatureOfPayment;
use App\Enums\UserRole;
use App\Support\DashedNumber;
use App\Support\PcgUnits;
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
        return in_array($this->user()?->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Staff], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Unique across the register; `lddaps_lddap_no_unique` backs this, and the service
            // re-checks under a lock, so a duplicate is refused before it can reach the index.
            'lddap_no' => ['required', ...DashedNumber::rules(), Rule::unique('lddaps', 'lddap_no')->ignore($this->route('lddap'))],

            // The references the disbursement is drawn against.
            // Exactly 7 digits (0000000), kept as text so leading zeros survive.
            'nca_no' => ['required', 'string', 'regex:/^\d{7}$/'],
            'obr_no' => ['required', 'string', 'max:100'],
            // 00-00-00000, and unique across the register (trimmed); `lddaps_dv_no_unique` backs it.
            'dv_no' => ['required', ...DashedNumber::rules(), Rule::unique('lddaps', 'dv_no')->ignore($this->route('lddap'))],
            'nature_of_payment' => ['required', Rule::enum(NatureOfPayment::class)],

            // The UACS object code — what prints as OBJ CODE on the ACIC.
            'obj_no' => ['nullable', 'string', 'max:100'],

            'unit_name' => ['required', 'string', PcgUnits::rule()],
            'check_date' => ['required', 'date'],

            // The payee, chosen from the Creditors or PCG Personnel list: which list, and which
            // record. The service copies its name, type and account number onto the LDDAP —
            // nothing links back. Required to register; on an edit, leaving it out keeps the
            // payee already saved.
            'payee_type' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', Rule::in(['creditor', 'pcg_personnel'])],
            'payee_ref' => [
                $this->isMethod('post') ? 'required' : 'required_with:payee_type', 'nullable', 'integer',
                // The record must be on the list the type names.
                Rule::exists($this->input('payee_type') === 'pcg_personnel' ? 'pcg_personnel' : 'creditors', 'id'),
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
            'lddap_no.regex' => DashedNumber::message('LDDAP Number'),
            'lddap_no.required' => 'Enter the LDDAP number.',
            'lddap_no.unique' => 'LDDAP number :input has already been registered. Each LDDAP number can be used only once.',
            'nca_no.required' => 'Enter the NCA Code.',
            'nca_no.regex' => 'NCA Code must be exactly 7 digits.',
            'obr_no.required' => 'Enter the OBR Number.',
            'dv_no.required' => 'Enter the DV Number.',
            'dv_no.regex' => DashedNumber::message('DV Number'),
            'dv_no.unique' => 'DV Number already exists.',
            'nature_of_payment.required' => 'Choose the nature of payment.',
            'nature_of_payment.Illuminate\\Validation\\Rules\\Enum' => 'Choose a nature of payment from the list.',
            'unit_name.required' => 'Choose the unit.',
            'unit_name.in' => 'Choose a unit from the list.',
            'check_date.required' => 'Enter the date issued.',
            'payee_type.required' => 'Choose a payee.',
            'payee_type.in' => 'Choose a payee from the list.',
            'payee_ref.required' => 'Choose a payee.',
            'payee_ref.required_with' => 'Choose a payee from the list.',
            'payee_ref.exists' => 'Choose a payee from the list.',
            'gross_amount.required' => 'Enter the gross amount.',
            'gross_amount.min' => 'The gross amount must be greater than zero.',
            '*.decimal' => 'Amounts carry at most two decimal places.',
        ];
    }

    /** The references are compared and stored trimmed. */
    protected function prepareForValidation(): void
    {
        $this->merge(collect(['lddap_no', 'nca_no', 'obr_no', 'dv_no'])
            ->filter(fn ($key) => is_string($this->input($key)))
            ->mapWithKeys(fn ($key) => [$key => trim($this->input($key))])
            ->all());
    }
}
