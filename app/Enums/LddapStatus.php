<?php

namespace App\Enums;

/**
 * An LDDAP-ADA record's status, in order:
 *
 *   Add LDDAP → For Signature ──Assign LDDAP to ACIC──► Approved (on the ACIC) → the teller's half
 *                   │  ↑
 *                   │  └── Resubmit (corrected) ── RTS
 *                   ├── RTS ──────────────────────►┘
 *                   └── Cancel ──► Canceled
 *
 * Only the next valid step is ever offered. The full trail is kept in `lddap_routing_history`.
 */
enum LddapStatus: string
{
    /** Added, and waiting to be assigned to an ACIC — the only status before one. */
    case ForSignature = 'for_signature';

    /**
     * Returned to sender (by an admin, with its own form). The details are corrected, then
     * **Resubmit** sends it back to For Signature. A record may be RTS'd any number of times;
     * every one is its own history entry.
     */
    case Rts = 'rts';

    /** On an ACIC, not yet forwarded to the tellers. Set by "Assign LDDAP to ACIC". */
    case Approved = 'approved';

    // ---- retired: only in older history rows -------------------------------------------

    /** Retired — the old "added" status, now For Signature. */
    case Registered = 'registered';

    /** Retired — the old Forward step. Its data is kept, never shown. */
    case ForOut = 'for_out';

    /** Retired — the old Receive step. Its data is kept, never shown. */
    case ReturnedForAcic = 'returned_for_acic';

    // ---- the teller's half, once the ACIC it sits on goes out ----------------------------

    /** Its ACIC has been forwarded to the tellers, unclaimed. */
    case ForwardedToTeller = 'forwarded_to_teller';

    /** A teller has claimed its ACIC. */
    case AcceptedByTeller = 'accepted_by_teller';

    /** Its ACIC was forwarded by the accepting teller to Land Bank. */
    case ForwardedToLandBank = 'forwarded_to_land_bank';

    /** Its ACIC was forwarded by the accepting teller to the payee. */
    case ForwardedToPayee = 'forwarded_to_payee';

    /** RTS'd by the teller: this check needs putting right before its ACIC goes out again. */
    case Returned = 'returned';

    /** Retired (the old Confirm and Complete flow): the bank sent its ACIC back. */
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
            self::ForSignature => 'For Signature',
            self::Registered => 'Registered',
            self::ForOut => 'For Out',
            self::ReturnedForAcic => 'Returned for ACIC',
            self::Rts => 'RTS',
            self::Approved => 'Approved',
            self::ForwardedToTeller => 'Forwarded to Teller',
            self::AcceptedByTeller => 'Accepted',
            self::ForwardedToLandBank => 'Forwarded to LBP',
            self::ForwardedToPayee => 'Forwarded to Payee',
            self::Returned => 'Returned',
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

    /** Is the record travelling with its ACIC, through the tellers and the bank? */
    public function isWithTeller(): bool
    {
        return in_array($this, [
            self::ForwardedToTeller, self::AcceptedByTeller, self::ReturnedByBank,
        ], true);
    }

    /** May it be put on an ACIC? Only a For Signature record. */
    public function isAcicEligible(): bool
    {
        return $this === self::ForSignature;
    }

    /** May a correction still be proposed or applied? Not once it is closed. */
    public function isEditable(): bool
    {
        return $this !== self::Canceled;
    }

    /**
     * May the record be opened in "Edit LDDAP Record" — the full form, saved directly? While it
     * is For Signature (not yet on an ACIC), or RTS'd back. Not once it is on an ACIC or canceled.
     */
    public function canEdit(): bool
    {
        return $this === self::ForSignature || $this === self::Rts;
    }

    // ---- the one next step each status allows -------------------------------------------

    /** RTS and Cancel are taken on a For Signature record, and only there. */
    public function awaitsAction(): bool
    {
        return $this === self::ForSignature;
    }

    /** Resubmit sends a corrected RTS record back to For Signature. */
    public function canResubmit(): bool
    {
        return $this === self::Rts;
    }
}
