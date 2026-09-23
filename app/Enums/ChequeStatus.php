<?php

namespace App\Enums;

/**
 * Where a cheque is in its life. One ordered flow, enforced server-side:
 *
 *   Registered → Out for Signature → (Mark as Received) → For ACIC → Approved ─┬─▶ Released to Payee
 *                                                                              └─▶ Forwarded to Teller
 *                                                                                        → Accepted by Teller
 *                                                                                        → Completed
 *
 * `Available` sits outside the flow: it is a number the bank printed and an admin registered as
 * part of a book, waiting to be claimed. Registering a cheque claims the **lowest available**
 * number, which is the register's whole point, and the number is never reused.
 *
 * Three ways out of the flow, each needing a reason: **RTS** (back to Registered), **Cancel**
 * (before a cheque is on an ACIC) and **Void** (once it is). `Stale` and `Replaced` come from
 * the 90-day validity clock, which runs against every status a cheque can be sitting in.
 */
enum ChequeStatus: string
{
    /** Registered as part of a book; not yet claimed. Outside the flow. */
    case Available = 'available';

    /** Claimed from the book, with its payee, amount and date. The flow starts here. */
    case Registered = 'registered';

    /** Out with a signatory. */
    case OutForSignature = 'out_for_signature';

    /**
     * Signed and back in the office.
     *
     * A **pass-through**: "Mark as Received" records the receipt and moves the cheque straight
     * on to For ACIC in the same step, as the flow requires. The value exists so the status
     * history can name the moment, and so older records that stopped here still read correctly.
     */
    case Received = 'received';

    /** Signed, back, and eligible for "Assign Cheque to ACIC". */
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

    /** Credited and confirmed by the bank. Final. */
    case Completed = 'completed';

    /** Cancelled before it reached an ACIC. Final. */
    case Cancelled = 'cancelled';

    /** Voided once on an ACIC. The number stays used and is never reassigned. Final. */
    case Voided = 'voided';

    /** Past its 90-day validity. Replaceable, but otherwise final. */
    case Stale = 'stale';

    /** A stale cheque a replacement was issued for. Final. */
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Registered => 'Registered',
            self::OutForSignature => 'Out for Signature',
            self::Received => 'Received',
            self::ForAcic => 'For ACIC',
            self::Approved => 'Approved',
            self::ReleasedToPayee => 'Released to Payee',
            self::ForwardedToTeller => 'Forwarded to Teller',
            self::AcceptedByTeller => 'Accepted by Teller',
            self::ReturnedByBank => 'Returned by Bank',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Voided => 'Voided',
            self::Stale => 'Stale',
            self::Replaced => 'Replaced',
        };
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
            self::Registered => [self::OutForSignature, self::Cancelled, self::Stale],
            // Received is the step out of here; it resolves to For ACIC in the same breath.
            self::OutForSignature => [self::Received, self::Registered, self::Cancelled, self::Stale],
            // Received is a pass-through; it resolves to For ACIC in the same step.
            self::Received => [self::ForAcic, self::Registered, self::Cancelled, self::Stale],
            self::ForAcic => [self::Approved, self::Registered, self::Cancelled, self::Stale],
            // ForAcic is the way back when the cheque is re-assigned off the ACIC.
            self::Approved => [self::ReleasedToPayee, self::ForwardedToTeller, self::Voided, self::Stale, self::ForAcic],
            // The teller steps, and Return to Admin from either of them.
            self::ForwardedToTeller => [self::AcceptedByTeller, self::Approved, self::Stale],
            // Confirm and Complete closes it straight from here; lodging with the bank is
            // part of that step, not a resting place of its own.
            self::AcceptedByTeller => [self::Completed, self::Approved, self::Stale],
            // The bank may send back an ACIC that was already closed; putting it right
            // completes it again.
            self::Completed => [self::ReturnedByBank],
            self::ReturnedByBank => [self::Completed, self::Approved, self::Stale],
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

    /** May it be put on an ACIC? Only For ACIC cheques are offered. */
    public function isAcicEligible(): bool
    {
        return $this === self::ForAcic;
    }

    /** Is the cheque on an ACIC, in any of the states that implies? */
    public function isOnAcic(): bool
    {
        return in_array($this, [
            self::Approved, self::ForwardedToTeller, self::AcceptedByTeller,
            self::ReturnedByBank, self::Completed, self::ReleasedToPayee,
        ], true);
    }

    /** RTS sends a cheque back to Registered. Allowed only while it is still in the office. */
    public function canRts(): bool
    {
        return in_array($this, [self::OutForSignature, self::Received, self::ForAcic], true);
    }

    /** Cancel is for a cheque that never reached an ACIC. */
    public function canCancel(): bool
    {
        return in_array($this, [self::Registered, self::OutForSignature, self::Received, self::ForAcic], true);
    }

    /** Void is for one that did — but not once a teller has it. */
    public function canVoid(): bool
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
            self::Registered, self::OutForSignature, self::Received, self::ForAcic,
            self::Approved, self::ForwardedToTeller, self::AcceptedByTeller,
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
            self::Registered => 'registered, never routed',
            self::OutForSignature => 'out for signature, never returned',
            self::Received, self::ForAcic => 'awaiting an ACIC',
            self::Approved => 'approved on an ACIC, neither released nor forwarded',
            self::ForwardedToTeller => 'with the tellers, unclaimed',
            self::AcceptedByTeller => 'with a teller, not yet lodged with the bank',
            self::ReturnedByBank => 'sent back by the bank',
            self::ReleasedToPayee => 'released, not encashed',
            default => $this->label(),
        };
    }

    /** The short tag beside an "expiring soon" badge. */
    public function tag(): ?string
    {
        return match ($this) {
            self::Registered, self::OutForSignature => 'Unsigned',
            self::ReleasedToPayee => 'With payee',
            self::ForwardedToTeller, self::AcceptedByTeller => 'With teller',
            self::ReturnedByBank => 'With bank',
            self::Received, self::ForAcic, self::Approved => 'In office',
            default => null,
        };
    }
}
