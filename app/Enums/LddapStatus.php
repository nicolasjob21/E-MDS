<?php

namespace App\Enums;

/**
 * The lifecycle of an LDDAP-ADA record.
 *
 * It mirrors the cheque lifecycle step for step, but is the LDDAP's own stored status — the
 * LDDAP draws on an independent check series and has no cheque to inherit a status from.
 */
enum LddapStatus: string
{
    /** A check number has been taken; awaiting the teller and the review. */
    case Used = 'used';

    /** The teller has confirmed receipt. */
    case Received = 'received';

    /** Reviewed and signed off — the only status that can go on an ACIC. Final. */
    case Approved = 'approved';

    /** Returned to the staff member, who has to fix the noted deficiencies. */
    case Compliance = 'compliance';

    /** Reviewed and rejected. Final. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Used => 'Used',
            self::Received => 'Received',
            self::Approved => 'Approved',
            self::Compliance => 'Returned',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The outcomes an admin review can produce.
     *
     * @return list<self>
     */
    public static function reviewOutcomes(): array
    {
        return [self::Approved, self::Compliance, self::Cancelled];
    }

    /** Has an admin already recorded a review outcome? */
    public function isReviewed(): bool
    {
        return in_array($this, self::reviewOutcomes(), true);
    }

    /**
     * Is this a settled, final outcome? "Returned" is not: the LDDAP is waiting on the
     * staff member to comply with the reviewer's notes and can be reviewed again afterwards.
     */
    public function isFinal(): bool
    {
        return $this === self::Approved || $this === self::Cancelled;
    }

    /** Is the LDDAP waiting on the staff member to act on review notes? */
    public function awaitsCompliance(): bool
    {
        return $this === self::Compliance;
    }

    /** Is the LDDAP signed off, i.e. eligible to be linked to an ACIC? */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }
}
