<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Models\ChequeLog;
use App\Models\User;

class ActivityLogger
{
    /** The username recorded when the system acted on its own — the nightly validity sweep. */
    public const SYSTEM = 'system';

    /**
     * Append an immutable entry to the audit log.
     *
     * `$user` is null for the things the system does unprompted, which still belong in the
     * trail: those entries are attributed to "system" with no user id.
     */
    public function log(
        ?User $user,
        ChequeAction $action,
        ?int $chequeNumber = null,
        ?string $description = null,
    ): ChequeLog {
        return ChequeLog::create([
            'user_id' => $user?->id,
            'username' => $user?->username ?? self::SYSTEM,
            'cheque_number' => $chequeNumber,
            'action' => $action,
            'description' => $description,
        ]);
    }
}
