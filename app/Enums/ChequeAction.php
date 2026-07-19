<?php

namespace App\Enums;

/**
 * Canonical set of auditable actions recorded in cheque_logs.
 */
enum ChequeAction: string
{
    case Login = 'login';
    case Logout = 'logout';
    case UsedCheque = 'used_cheque';
    case CashedCheque = 'cashed_cheque';
    case AddedChequeRange = 'added_cheque_range';
    case CreatedUser = 'created_user';
    case UpdatedUser = 'updated_user';
    case DeletedUser = 'deleted_user';
}
