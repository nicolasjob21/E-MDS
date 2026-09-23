<?php

namespace App\Console\Commands;

use App\Models\Cheque;
use App\Services\ChequeExpiryService;
use App\Support\Validity;
use Illuminate\Console\Command;

/**
 * The nightly 90-day sweep. Scheduled just after midnight in Manila (see routes/console.php),
 * and safe to run by hand at any time: both passes are idempotent.
 */
class SweepChequeValidity extends Command
{
    protected $signature = 'cheques:sweep-validity
                            {--dry-run : Report what would change without writing or notifying}';

    protected $description = 'Mark cheques past their 90-day validity as stale and send the one-time 10-day expiry alerts';

    public function handle(ChequeExpiryService $expiry): int
    {
        $today = Validity::today()->toDateString();
        $this->info("Cheque validity sweep — {$today} (".Validity::TZ.')');

        if ($this->option('dry-run')) {
            $this->line('  would mark stale : '.Cheque::query()->expired()->count());
            $this->line('  would alert on   : '.Cheque::query()->expiringSoon()->whereNull('expiry_alert_sent_at')->count());

            return self::SUCCESS;
        }

        ['staled' => $staled, 'alerted' => $alerted] = $expiry->sweep();

        $this->line("  marked stale : {$staled}");
        $this->line("  alerts sent  : {$alerted}");

        return self::SUCCESS;
    }
}
