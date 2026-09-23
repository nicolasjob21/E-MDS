<?php

use App\Support\Validity;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The 90-day cheque clock, swept shortly after midnight in Manila: cheques that have run out
 * are marked stale, and cheques with ten days or fewer left get their one-time warning.
 *
 * `withoutOverlapping` guards a long run; the sweep is idempotent anyway, so a missed night
 * is caught up by the next one and a double run changes nothing.
 */
Schedule::command('cheques:sweep-validity')
    ->dailyAt('00:05')
    ->timezone(Validity::TZ)
    ->withoutOverlapping()
    ->onOneServer();
