<?php

namespace App\Console\Commands;

use App\Enums\ChequeAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * Give a user the Super Admin role — the admin in charge of checking cheque drafts.
 *
 * Only a Super Admin can grant the role from the Users page, so the first one has to be made
 * here, by someone with access to the server.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'users:make-super-admin {username : The username to promote}';

    protected $description = 'Give a user the Super Admin role (the admin in charge of cheque drafts)';

    public function handle(ActivityLogger $logger): int
    {
        $user = User::query()->where('username', $this->argument('username'))->first();

        if ($user === null) {
            $this->error("No user with the username '{$this->argument('username')}'.");

            return self::FAILURE;
        }

        if ($user->isSuperAdmin()) {
            $this->info("{$user->name} ({$user->username}) is already a Super Admin.");

            return self::SUCCESS;
        }

        $from = $user->role->label();
        $user->update(['role' => UserRole::SuperAdmin]);
        $logger->log(null, ChequeAction::UpdatedUser, null, "Made '{$user->username}' a Super Admin (was {$from}) from the command line.");

        $this->info("{$user->name} ({$user->username}) is now a Super Admin.");

        return self::SUCCESS;
    }
}
