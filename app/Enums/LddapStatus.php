<?php

namespace App\Enums;

/**
 * The routing of an LDDAP-ADA record, in order:
 *
 *   Registered → For Out → Returned for ACIC → Approved | RTS | Canceled
 *                   ↑                              │
 *                   └──────── (edit) ──────────────┘
 *
 * Each step is taken by a named action — Forward, Receive, then Approve / RTS / Cancel — and
 * only the next valid step is ever offered. The full trail is kept in `lddap_routing_history`.
 */
enum LddapStatus: string
{
    /** Created through "Add LDDAP". Can be forwarded. */
    case Registered = 'registered';

    /** Forwarded out for processing. Can be received back. */
    case ForOut = 'for_out';

    /** Received back; awaiting the admin's action — Approve, RTS or Cancel. */
    case ReturnedForAcic = 'returned_for_acic';

    /**
     * Returned to sender. The details can be corrected, and it is then forwarded again — For
     * Out, Returned for ACIC, and the admin's action once more. A record may be RTS'd any
     * number of times; every one is its own history entry.
     */
    case Rts = 'rts';

    /** Signed off. Available to "Assign LDDAP to ACIC". */
    case Approved = 'approved';

    // ---- the teller's half, once the ACIC it sits on goes out ----------------------------

    /** Its ACIC has been forwarded to the tellers, unclaimed. */
    case ForwardedToTeller = 'forwarded_to_teller';

    /** A teller has claimed its ACIC. */
    case AcceptedByTeller = 'accepted_by_teller';

    /** Its ACIC is lodged with Land Bank, awaiting the credit. */
    case ForwardedToLandBank = 'forwarded_to_land_bank';

    /** The bank sent its ACIC back. It goes round again once the issue is fixed. */
    case ReturnedByBank = 'returned_by_bank';

    /** Credited and confirmed by the bank. Final. */
    case Completed = 'completed';

    /**
     * Closed by an admin, with a reason. Read-only from here on: no edit, forward, RTS, approve
     * or ACIC assignment. The LDDAP number stays used. Final.
     */
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::ForOut => 'For Out',
            self::ReturnedForAcic => 'Returned for ACIC',
            self::Rts => 'RTS',
            self::Approved => 'Approved',
            self::ForwardedToTeller => 'Forwarded to Teller',
            self::AcceptedByTeller => 'Accepted by Teller',
            self::ForwardedToLandBank => 'Forwarded to Land Bank',
            self::ReturnedByBank => 'Returned by Bank',
            self::Completed => 'Completed',
            self::Canceled => 'Canceled',
        };
    }

    /** The true end of the line: credited by the bank, or closed. */
    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Canceled;
    }

    /**
     * Is this an outcome of the admin's review — the moment somebody signs the record off?
     * Distinct from `isFinal()`: an approved record is signed off but still has its whole
     * teller and bank life ahead of it.
     */
    public function isReviewOutcome(): bool
    {
        return $this === self::Approved || $this === self::Canceled;
    }

    /** Is the record travelling with its ACIC, through the tellers and the bank? */
    public function isWithTeller(): bool
    {
        return in_array($this, [
            self::ForwardedToTeller, self::AcceptedByTeller,
            self::ForwardedToLandBank, self::ReturnedByBank,
        ], true);
    }

    /** Is the LDDAP signed off, i.e. eligible to be linked to an ACIC? */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /** May a correction still be proposed or applied? Not once it is closed. */
    public function isEditable(): bool
    {
        return $this !== self::Canceled;
    }

    /**
     * May the record be opened in "Edit LDDAP Record" — the full form, saved directly? Only
     * while it is in the registrant's hands: Registered, or RTS'd back to them. Once it is out
     * for routing, awaiting action, approved or canceled, it is not.
     */
    public function canEdit(): bool
    {
        return $this === self::Registered || $this === self::Rts;
    }

    // ---- the one next step each status allows -------------------------------------------

    /** Forward is the step out of Registered — and out of RTS, once the record is corrected. */
    public function canForward(): bool
    {
        return $this === self::Registered || $this === self::Rts;
    }

    public function canReceive(): bool
    {
        return $this === self::ForOut;
    }

    /** Approve, RTS and Cancel are all taken from here, and only from here. */
    public function awaitsAction(): bool
    {
        return $this === self::ReturnedForAcic;
    }
}
