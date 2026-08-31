<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ForwardAcicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is gated by the `admin` middleware.
    }

    /**
     * The ACIC is handed either to a system user (normally the teller) or to someone with no
     * account, in which case the name of whoever accepted it is typed in. Exactly one of the
     * two is required.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The user the ACIC is being handed to.
            'received_by' => [
                'nullable',
                'required_without:received_name',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
            // Free-text name, for a recipient who is not a system user.
            'received_name' => [
                'nullable',
                'required_without:received_by',
                'prohibits:received_by',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'received_by.required_without' => 'Choose who is receiving this ACIC, or type the name of whoever accepted it.',
            'received_by.exists' => 'That user does not exist or is inactive.',
            'received_name.required_without' => 'Type the name of whoever accepted this ACIC.',
            'received_name.prohibits' => 'Give either a user or a name, not both.',
        ];
    }
}
