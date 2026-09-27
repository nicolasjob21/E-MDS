<?php

namespace App\Http\Requests;

/** Batch Upload: one .xlsx or .csv file. Its rows are checked by AccountHolderService. */
class UploadAccountHoldersRequest extends AccountHolderRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => 'Upload an Excel (.xlsx) or CSV (.csv) file.',
            'file.max' => 'The file may not be larger than 5 MB.',
        ];
    }
}
