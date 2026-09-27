<?php

namespace App\Enums;

/** The step a routing-history entry records. */
enum LddapRoutingAction: string
{
    case Registered = 'registered';
    case Rts = 'rts';
    case Resubmitted = 'resubmitted';
    case Canceled = 'canceled';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';

    // The ACIC's teller steps, written on every LDDAP on it.
    case ForwardedToTeller = 'forwarded_to_teller';
    case AcceptedByTeller = 'accepted_by_teller';
    case Completed = 'completed';
    case ReturnedByBank = 'returned_by_bank';
    case ReturnedToAdmin = 'returned_to_admin';
    case ForwardedToLandBank = 'forwarded_to_land_bank';
    case ForwardedToPayee = 'forwarded_to_payee';
    case Returned = 'returned';

    // Retired steps — only in older history rows (never shown). Older teller steps were written
    // as Forwarded too; those are shown, named by the status they moved to.
    case Forwarded = 'forwarded';
    case Received = 'received';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Added',
            self::Resubmitted => 'Resubmitted',
            self::Assigned => 'Assigned to ACIC',
            self::Unassigned => 'Taken off the ACIC',
            self::ForwardedToTeller => 'Forwarded to Teller',
            self::AcceptedByTeller => 'Accepted',
            self::Completed => 'Completed',
            self::ReturnedByBank => 'Returned by Bank',
            self::ReturnedToAdmin => 'Returned to Admin',
            self::ForwardedToLandBank => 'Forwarded to LBP',
            self::ForwardedToPayee => 'Forwarded to Payee',
            self::Returned => 'Returned (RTS)',
            self::Forwarded => 'Forwarded',
            self::Received => 'Received',
            self::Approved => 'Approved',
            self::Rts => 'Returned to sender',
            self::Canceled => 'Canceled',
        };
    }
}
