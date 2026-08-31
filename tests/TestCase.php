<?php

namespace Tests;

use App\Enums\AcicNumberStatus;
use App\Models\AcicNumber;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Register a block of ACIC numbers for a test to draw on.
     *
     * ACIC numbers are a registered series, so nothing can open an ACIC until some exist. Rows
     * are inserted directly rather than through `AcicService::addRange()` so this does not add a
     * user or an audit entry that a test might then be counting.
     */
    protected function seedAcicSeries(int $from = 1, int $to = 50): void
    {
        $now = now();
        $rows = [];

        for ($number = $from; $number <= $to; $number++) {
            $rows[] = [
                'acic_number' => $number,
                'status' => AcicNumberStatus::Available->value,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        AcicNumber::insert($rows);
    }
}
