<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/** The creditor and PCG personnel lists are kept by admins and staff. */
abstract class AccountHolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::SuperAdmin, UserRole::Admin, UserRole::Staff], true);
    }
}
