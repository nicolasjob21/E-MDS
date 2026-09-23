<?php

use App\Support\Validity;

return [

    /*
    |--------------------------------------------------------------------------
    | Cheque validity
    |--------------------------------------------------------------------------
    |
    | A cheque is valid for a fixed number of calendar days from its cheque date,
    | reckoned in the office's own timezone. The rule itself lives in
    | App\Support\Validity; these are here so it can be read from config.
    |
    */

    'validity_days' => Validity::DAYS,
    'alert_days' => Validity::ALERT_DAYS,
    'timezone' => Validity::TZ,

    /*
    |--------------------------------------------------------------------------
    | Expiry alert channels
    |--------------------------------------------------------------------------
    |
    | Expiry and stale alerts are always delivered in-app (the header bell).
    | Email is optional and off by default — turn it on with CHEQUE_ALERT_EMAIL=true
    | once a mailer is configured.
    |
    */

    'alert_email' => (bool) env('CHEQUE_ALERT_EMAIL', false),

];
