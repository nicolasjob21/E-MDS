<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The admin's action on a record that is Returned for ACIC — Approve, RTS or Cancel. The route
 * is admin-only; a note is optional except for Cancel, which the service enforces.
 */
class ActOnLddapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
