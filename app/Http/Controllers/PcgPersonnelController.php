<?php

namespace App\Http\Controllers;

use App\Models\PcgPersonnel;

class PcgPersonnelController extends AccountHolderController
{
    protected function model(): string
    {
        return PcgPersonnel::class;
    }
}
