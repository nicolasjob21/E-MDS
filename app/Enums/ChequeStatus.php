<?php

namespace App\Enums;

/**
 * Where a cheque is in its life. One ordered flow, enforced server-side:
 *
 *   (blank) --Print Draft--> For Checking --Approve--> For Final Print --Final Print--> For Signature
 *                 ↑                  └──Return (comment)──> For Compliance ──Print Draft──┘ (repeatable)
 *   For Signature --Assign Cheque to ACIC--> Approved ─┬─▶ Released to Payee
 *                                                      └─▶ Forwarded to Teller → Accepted by Teller → Completed
 *
 * `Available` sits outside the flow: a number the bank printed and an admin registered as part of
 * a book. Using a cheque claims the **lowest available** number and starts the flow with **no
 * status shown** (stored as `registered`). Its details can be edited only then, and while it is
 * For Compliance.
 *
 * Ways out, each needing a reason: **Cancel** (before an ACIC) and **Spoil** (once on one — the
 * payment moves to a replacement cheque on the next available number). `Stale` and `Replaced`
 * come from the 90-day validity clock, which runs against every status a cheque can sit in.
 */
enum ChequeStatus: string
{
    /** Registered as part of a book; not yet claimed. Outside the flow. */
    case Available = 'available';

    /** Claimed from the book, with its details; no draft printed yet. Shown with no status. */
    case Registered = 'registered';

    /** A draft has been printed and is with the admin in charge (a Super Admin) for checking. */
    case ForChecking = 'for_checking';

    /** The draft was returned with a comment: the preparer corrects it and prints a new draft. */
    case ForCompliance = 'for_compliance';

    /** The draft was approved; the cheque may be printed for real. */
    case ForFinalPrint = 'for_final_print';

    /** Printed and confirmed; eligible for "Assign Cheque to ACIC". */
    case ForSignature = 'for_signature';

    /** Legacy — the old flow's routing step. Kept so older timeline rows still read. */
    case OutForSignature = 'out_for_signature';

    /** Legacy — the old flow's receipt step. Kept so older timeline rows still read. */
    case Received = 'received';

    /** Legacy — the old flow's "awaiting an ACIC", now For Signature. Kept for older timeline rows. */
    case ForAcic = 'for_acic';

    /**
     * On an ACIC and signed off. From here the admin releases it to the payee, or forwards the
     * whole ACIC to a teller.
     */
    case Approved = 'approved';

    /** Handed to the payee or their representative. Final. */
    case ReleasedToPayee = 'released_to_payee';

    /** Its ACIC is with the tellers, unclaimed. */
    case ForwardedToTeller = 'forwarded_to_teller';

    /** A teller has claimed the ACIC and is holding it. */
    case AcceptedByTeller = 'accepted_by_teller';

    /** The bank sent its ACIC back. It goes round again once the issue is fixed. */
    case ReturnedByBank = 'returned_by_bank';

    /** Its ACIC was forwarded by the accepting teller to Land Bank. */
    case ForwardedToLandBank = 'forwarded_to_land_bank';

    /** Its ACIC was forwarded by the accepting teller to the payee. */
    case ForwardedToPayee = 'forwarded_to_payee';

    /** RTS'd by the teller: this check needs putting right before its ACIC goes out again. */
    case Returned = 'returned';

    /** Credited and confirmed by the bank. Final. */
    case Completed = 'completed';

    /** Cancelled before it reached an ACIC. Final. */
    case Cancelled = 'cancelled';

    /**
     * Spoiled once on an ACIC: the number is used up and the payment moves to a replacement
     * cheque (`replaced_by_id`). The number is never reassigned. Final. (Formerly "Voided".)
     */
    case Spoiled = 'spoiled';

    /** Past its 90-day validity. Replaceable, but otherwise final. */
    case Stale = 'stale';

    /** A stale cheque a replacement was issued for. Final. */
    case Replaced = 'replaced';

    /** The status as the UI names it. A cheque with no draft yet shows none. */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Registered => '',
            self::ForChecking => 'For Checking',
            self::ForCompliance => 'For Compliance',
            self::ForFinalPrint => 'For Final Print',
            self::ForSignature => 'For Signature',
            self::OutForSignature => 'Out for Signature',
            self::Received => 'Received',
            self::ForAcic => 'For ACIC',
            self::Approved => 'Approved',
            self::ReleasedToPayee => 'Released to Payee',
            self::ForwardedToTeller => 'Forwarded to Teller',
            self::AcceptedByTeller => 'Accepted',
            self::ReturnedByBank => 'Returned by Bank',
            self::ForwardedToLandBank => 'Forwarded to LBP',
            self::ForwardedToPayee => 'Forwarded to Payee',
            self::Returned => 'Returned',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Spoiled => 'Spoiled',
            self::Stale => 'Stale',
            self::Replaced => 'Replaced',
        };
    }

    /** For a sentence: "Cheque #5 is {described}" — the blank status still needs words there. */
    public function describe(): string
    {
        return $this === self::Registered ? 'awaiting its first draft' : $this->label();
    }

    /**
     * The whole transition table. Anything not listed here is refused, so the flow cannot be
     * skipped forwards or walked backwards — except by RTS and Return to Admin, which are
     * listed like any other move.
     *
     * @return list<self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Available => [self::Registered],
            self::Registered => [self::ForChecking, self::Cancelled, self::Stale],
            self::ForChecking => [self::ForFinalPrint, self::ForCompliance, self::Cancelled, self::Stale],
            // Corrected, then a new draft goes back for checking — as often as it takes.
            self::ForCompliance => [self::ForChecking, self::Cancelled, self::Stale],
            self::ForFinalPrint => [self::ForSignature, self::Cancelled, self::Stale],
            self::ForSignature => [self::Approved, self::Cancelled, self::Stale],
            // For Signature is the way back when the cheque is re-assigned off the ACIC.
            self::Approved => [self::ReleasedToPayee, self::ForwardedToTeller, self::Spoiled, self::Stale, self::ForSignature],
            // The teller steps, and Return to Admin from either of them.
            self::ForwardedToTeller => [self::AcceptedByTeller, self::Approved, self::Stale],
            // The accepting teller forwards it — to Land Bank or to the payee.
            self::AcceptedByTeller => [self::ForwardedToLandBank, self::ForwardedToPayee, self::Approved, self::Stale],
            // The teller's Action: Completed, or RTS — which gives each check its own outcome.
            self::ForwardedToLandBank, self::ForwardedToPayee => [self::Completed, self::Returned, self::Cancelled, self::Stale],
            // Put right, and out again; or back to the admin.
            self::Returned => [self::ForwardedToLandBank, self::ForwardedToPayee, self::Approved, self::Stale],
            // Older rows from the retired Confirm and Complete flow.
            self::ReturnedByBank => [self::ForwardedToLandBank, self::ForwardedToPayee, self::Completed, self::Approved, self::Stale],
            // A released cheque still ages: it can go stale uncashed.
            self::ReleasedToPayee => [self::Stale],
            self::Stale => [self::Replaced],
            default => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->nextStates(), true);
    }

    /** Has the cheque been claimed from the book at all? */
    public function isIssued(): bool
    {
        return $this !== self::Available;
    }

    /** Nothing further can happen, bar a replacement. */
    public function isFinal(): bool
    {
        return $this->nextStates() === [] || $this === self::Stale;
    }

    /** May it be put on an ACIC? Only For Signature cheques are offered. */
    public function isAcicEligible(): bool
    {
        return $this === self::ForSignature;
    }

    /** Its details (payee, account no., unit, amount, date) may be edited only now. */
    public function isEditable(): bool
    {
        return $this === self::Registered || $this === self::ForCompliance;
    }

    /** "Print Draft" submits the cheque for checking: first time, or after a Return. */
    public function canPrintDraft(): bool
    {
        return $this === self::Registered || $this === self::ForCompliance;
    }

    /** The draft printout may be viewed while the cheque is being drafted and checked. */
    public function showsDraft(): bool
    {
        return in_array($this, [self::Registered, self::ForChecking, self::ForCompliance], true);
    }

    /** The clean, final cheque face may be printed from here on. */
    public function showsFinal(): bool
    {
        return $this === self::ForFinalPrint || $this === self::ForSignature || $this->isOnAcic();
    }

    /** Is the cheque on an ACIC, in any of the states that implies? */
    public function isOnAcic(): bool
    {
        return in_array($this, [
            self::Approved, self::ForwardedToTeller, self::AcceptedByTeller,
            self::ForwardedToLandBank, self::ForwardedToPayee, self::Returned,
            self::ReturnedByBank, self::Completed, self::ReleasedToPayee,
        ], true);
    }

    /** Cancel is for a cheque that never reached an ACIC. */
    public function canCancel(): bool
    {
        return in_array($this, [self::Registered, self::ForChecking, self::ForCompliance, self::ForFinalPrint, self::ForSignature], true);
    }

    /** Spoil is for one that did — but not once a teller has it. */
    public function canSpoil(): bool
    {
        return $this === self::Approved;
    }

    /**
     * The statuses the 90-day clock still runs against. A cheque ages from its cheque date
     * wherever it is sitting; only the settled ones are left alone.
     *
     * @return list<self>
     */
    public static function perishable(): array
    {
        return [
            self::Registered, self::ForChecking, self::ForCompliance, self::ForFinalPrint, self::ForSignature,
            self::Approved, self::ForwardedToTeller, self::AcceptedByTeller,
            self::ForwardedToLandBank, self::ForwardedToPayee, self::Returned,
            self::ReturnedByBank, self::ReleasedToPayee,
        ];
    }

    public function isPerishable(): bool
    {
        return in_array($this, self::perishable(), true);
    }

    /** Where the cheque was when it ran out — for the audit entry and the alert's tag. */
    public function stage(): string
    {
        return match ($this) {
            self::Registered => 'no draft printed yet',
            self::ForChecking => 'draft awaiting checking',
            self::ForCompliance => 'draft returned for compliance',
            self::ForFinalPrint => 'approved, not yet printed',
            self::ForSignature => 'printed, awaiting signature and an ACIC',
            self::Approved => 'approved on an ACIC, neither released nor forwarded',
            self::ForwardedToTeller => 'with the tellers, unclaimed',
            self::AcceptedByTeller => 'with a teller, not yet forwarded',
            self::ForwardedToLandBank => 'forwarded to Land Bank, awaiting completion',
            self::ForwardedToPayee => 'forwarded to the payee, awaiting completion',
            self::Returned => 'returned (RTS) by the teller',
            self::ReturnedByBank => 'sent back by the bank',
            self::ReleasedToPayee => 'released, not encashed',
            default => $this->label(),
        };
    }

    /** The short tag beside an "expiring soon" badge. */
    public function tag(): ?string
    {
        return match ($this) {
            self::Registered, self::ForChecking, self::ForCompliance, self::ForFinalPrint, self::ForSignature => 'Unsigned',
            self::ReleasedToPayee => 'With payee',
            self::ForwardedToTeller, self::AcceptedByTeller, self::Returned => 'With teller',
            self::ForwardedToPayee => 'With payee',
            self::ForwardedToLandBank, self::ReturnedByBank => 'With bank',
            self::Approved => 'In office',
            default => null,
        };
    }
}
