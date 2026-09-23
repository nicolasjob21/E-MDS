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

    // The disposition axis — where a signed cheque physically went, and its 90-day validity.
    case RoutedForSignature = 'routed_for_signature';
    case ReadyForAcic = 'ready_for_acic';
    case RtsCheque = 'rts_cheque';
    case VoidedCheque = 'voided_cheque';
    case AcceptedByTeller = 'accepted_by_teller';
    case ReleasedCheque = 'released_cheque';
    case ForwardedChequeToTeller = 'forwarded_cheque_to_teller';
    case DepositedCheque = 'deposited_cheque';
    case ReturnedChequeFromTeller = 'returned_cheque_from_teller';
    case CancelledCheque = 'cancelled_cheque';
    case SpoiledCheque = 'spoiled_cheque';
    case StaledCheque = 'staled_cheque';
    case ReplacedCheque = 'replaced_cheque';
    case CorrectedRelease = 'corrected_release';
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
