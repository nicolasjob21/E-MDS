<?php

namespace App\Enums;

enum ChequeStatus: string
{
    case Available = 'available';
    case Used = 'used';
    case Received = 'received';

    // Admin review outcomes. Approved and Disapproved are final; Complies ("Returned")
    // sends the cheque back to the staff member to fix the noted deficiencies, after which
    // it can be reviewed again.
    case Approved = 'approved';
    case Complies = 'complies';
    case Disapproved = 'disapproved';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Used => 'Used',
            self::Received => 'Received',
            self::Approved => 'Approved',
            self::Complies => 'Returned',
            self::Disapproved => 'Disapproved',
        };
    }

    /**
     * Every status a cheque can hold once it has left the available pool.
     *
     * @return list<self>
     */
    public static function issued(): array
    {
        return [self::Used, self::Received, self::Approved, self::Complies, self::Disapproved];
    }

    /**
     * The outcomes an admin review can produce.
     *
     * @return list<self>
     */
    public static function reviewOutcomes(): array
    {
        return [self::Approved, self::Complies, self::Disapproved];
    }

    /** Has this cheque been issued (used) at all? */
    public function isIssued(): bool
    {
        return $this !== self::Available;
    }

    /** Has an admin already recorded a review outcome? */
    public function isReviewed(): bool
    {
        return in_array($this, self::reviewOutcomes(), true);
    }

    /**
     * Is this a settled, final outcome? "Returned" is not: the cheque is waiting on the
     * staff member to comply with the reviewer's notes and can be reviewed again afterwards.
     */
    public function isFinal(): bool
    {
        return $this === self::Approved || $this === self::Disapproved;
    }

    /** Is the cheque signed off, i.e. eligible to be linked to an ACIC? */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /** Is the cheque waiting on the staff member to act on review notes? */
    public function awaitsCompliance(): bool
    {
        return $this === self::Complies;
    }
}
