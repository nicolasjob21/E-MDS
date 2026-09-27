<?php

namespace App\Http\Requests;

use App\Support\PcgUnits;

class IndexAccountHoldersRequest extends AccountHolderRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', PcgUnits::rule()],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
