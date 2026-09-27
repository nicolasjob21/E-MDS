<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route is gated by the `admin` middleware.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::enum(UserRole::class), $this->roleRule()],
            'is_active' => ['boolean'],
        ];
    }

    /** Only a Super Admin may make someone a Super Admin. */
    private function roleRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if ($value === UserRole::SuperAdmin->value && ! $this->user()?->isSuperAdmin()) {
                $fail('Only a Super Admin can give someone the Super Admin role.');
            }
        };
    }
}
