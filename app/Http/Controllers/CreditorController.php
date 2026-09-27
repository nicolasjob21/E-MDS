<?php

namespace App\Http\Controllers;

use App\Models\Creditor;

class CreditorController extends AccountHolderController
{
    protected function model(): string
    {
        return Creditor::class;
    }
}
