<?php

namespace App\Enums;

enum ChequeStatus: string
{
    case Available = 'available';
    case Used = 'used';
}
