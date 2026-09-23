<?php

namespace App\Enums;

/**
 * Where an ACIC stands with the tellers and the bank — the teller's own axis, alongside
 * `AcicStatus` (which tracks the ACIC's own life: open, used, approved, …).
 *
 *   Pending → Accepted by Teller → Forwarded to Land Bank → Completed (credited)
 *                                          │
 *                                          └─▶ Returned by Bank ─┬─▶ Forwarded to Land Bank again
 *                                                                └─▶ back to the admin
 *
 * Null means the ACIC has never been sent to a teller. Returning it to the admin clears the
 * axis back to null, so the next forward starts a fresh cycle.
 */
enum AcicTellerStatus: string
{
    /** Forwarded, unclaimed. Every teller sees it. */
    case Pending = 'pending';

    /** One teller has claimed it. Only they may act on it from here. */
    case AcceptedByTeller = 'accepted_by_teller';

    /** Lodged with Land Bank, awaiting the credit. */
    case ForwardedToLandBank = 'forwarded_to_land_bank';

    /** The bank sent it back. Fix it and lodge it again, or hand it to the admin. */
    case ReturnedByBank = 'returned_by_bank';

    /** Credited and confirmed by the bank. Final. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::AcceptedByTeller => 'Accepted by Teller',
            self::ForwardedToLandBank => 'Forwarded to Land Bank',
            self::ReturnedByBank => 'Returned by Bank',
            self::Completed => 'Completed',
        };
    }

    /**
     * The whole transition table. Anything not listed is refused.
     *
     * @return list<self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Pending => [self::AcceptedByTeller],
            // Return to Admin clears the axis, so it is not a state here. A teller who hands
            // the ACIC over and gets the credit in one visit closes it straight from here,
            // recording when it was handed to the bank as part of that.
            self::AcceptedByTeller => [self::ForwardedToLandBank, self::Completed],
            self::ForwardedToLandBank => [self::Completed, self::ReturnedByBank],
            // Re-forward after the bank sent it back; every cycle is kept in the history.
            self::ReturnedByBank => [self::ForwardedToLandBank],
            self::Completed => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->nextStates(), true);
    }

    /** Credited. Nothing further is allowed — no edit, return, re-forward or void. */
    public function isFinal(): bool
    {
        return $this === self::Completed;
    }

    /** May the teller holding it hand it back to the admin? Not once the bank has credited it. */
    public function canReturnToAdmin(): bool
    {
        return in_array($this, [self::AcceptedByTeller, self::ReturnedByBank], true);
    }
}
