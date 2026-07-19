<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Models\ChequeLog;
use App\Models\User;

class ActivityLogger
{
    /**
     * Append an immutable entry to the audit log.
     */
    public function log(
        User $user,
        ChequeAction $action,
        ?int $chequeNumber = null,
        ?string $description = null,
    ): ChequeLog {
        return ChequeLog::create([
            'user_id' => $user->id,
            'username' => $user->username,
            'cheque_number' => $chequeNumber,
            'action' => $action,
            'description' => $description,
        ]);
    }
}
