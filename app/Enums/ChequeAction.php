<?php

namespace App\Enums;

/**
 * Canonical set of auditable actions recorded in cheque_logs.
 */
enum ChequeAction: string
{
    case Login = 'login';
    case Logout = 'logout';
    case UpdatedProfile = 'updated_profile';
    case ChangedPassword = 'changed_password';
    case UsedCheque = 'used_cheque';
    case ReceivedCheque = 'received_cheque';
    case ReviewedCheque = 'reviewed_cheque';
    case RequestedUpdate = 'requested_update';
    case ApprovedUpdate = 'approved_update';
    case RejectedUpdate = 'rejected_update';
    case AddedChequeRange = 'added_cheque_range';
    case AddedLddapCheckRange = 'added_lddap_check_range';
    case RegisteredLddap = 'registered_lddap';
    case ForwardedLddap = 'forwarded_lddap';
    case ReceivedLddapBack = 'received_lddap_back';
    case UsedLddapCheck = 'used_lddap_check';
    case ReceivedLddap = 'received_lddap';
    case ReviewedLddap = 'reviewed_lddap';
    case RequestedLddapUpdate = 'requested_lddap_update';
    case ApprovedLddapUpdate = 'approved_lddap_update';
    case RejectedLddapUpdate = 'rejected_lddap_update';
    case UpdatedLddap = 'updated_lddap';
    case AddedAcicRange = 'added_acic_range';
    case CreatedAcic = 'created_acic';
    case UsedAcic = 'used_acic';
    case ApprovedAcic = 'approved_acic';
    case ReassignedAcic = 'reassigned_acic';
    case ForwardedAcic = 'forwarded_acic';
    case CompletedAcic = 'completed_acic';
    case CreatedUser = 'created_user';
    case UpdatedUser = 'updated_user';
    case DeletedUser = 'deleted_user';
}
