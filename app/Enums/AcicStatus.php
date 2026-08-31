<?php

namespace App\Enums;

enum AcicStatus: string
{
    /** Created, no cheques assigned yet. */
    case Open = 'open';

    /** Cheques or LDDAPs have been assigned; awaiting sign-off. */
    case Used = 'used';

    /** Signed off. The only status from which an ACIC may be forwarded or printed. */
    case Approved = 'approved';

    /** Forwarded to a recipient; awaiting teller action. */
    case Forwarded = 'forwarded';

    /** The teller has finished the transaction; terminal. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Used => 'Used',
            self::Approved => 'Approved',
            self::Forwarded => 'Forwarded',
            self::Completed => 'Completed',
        };
    }

    /**
     * Can more records — cheques or LDDAPs — still be put on it?
     *
     * **Used says nothing about capacity.** It only means the ACIC already carries something;
     * many records share one ACIC number, so a Used ACIC keeps accepting them. Sign-off is what
     * closes membership: an Approved ACIC is fixed except through an explicit re-assignment.
     */
    public function acceptsRecords(): bool
    {
        return $this === self::Open || $this === self::Used;
    }

    /** Has it been signed off, i.e. may it be forwarded and printed? */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /**
     * May its membership still be changed — a record swapped out for another? Once the ACIC has
     * left the building (forwarded) it is out of the office's hands.
     */
    public function acceptsReassignment(): bool
    {
        return $this === self::Used || $this === self::Approved;
    }

    /** Is it sitting with the teller, waiting to be completed? */
    public function awaitsTeller(): bool
    {
        return $this === self::Forwarded;
    }

    /** Is it settled? */
    public function isFinal(): bool
    {
        return $this === self::Completed;
    }
}
