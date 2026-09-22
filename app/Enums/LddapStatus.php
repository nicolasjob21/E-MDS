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

    /** Signed off. Available to "Assign LDDAP to ACIC". Final. */
    case Approved = 'approved';

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
            self::Canceled => 'Canceled',
        };
    }

    /** Is this a settled, final outcome? */
    public function isFinal(): bool
    {
        return $this === self::Approved || $this === self::Canceled;
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
