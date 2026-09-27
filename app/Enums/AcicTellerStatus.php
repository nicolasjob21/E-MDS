<?php

namespace App\Enums;

/**
 * Where an ACIC stands with the tellers and the bank — the teller's own axis, alongside
 * `AcicStatus` (which tracks the ACIC's own life: open, used, approved, …).
 *
 *   Pending → Accepted by Teller ─▶ Forwarded to Land Bank ─┬─▶ Completed (final)
 *                        │         ─▶ Forwarded to Payee     ─┤
 *                        │                                    └─▶ RTS ─┬─▶ Forward again
 *                        └─▶ back to the admin                         └─▶ back to the admin
 *
 * Only the teller who accepted the ACIC forwards it and takes the Action (Completed or RTS).
 * Returned by Bank belongs to the retired Confirm and Complete flow; it survives only on older
 * ACICs, which may be forwarded again or returned to the admin.
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

    /** The accepting teller took it to Land Bank. Awaiting the Action. */
    case ForwardedToLandBank = 'forwarded_to_land_bank';

    /** The accepting teller took it to the payee. Awaiting the Action. */
    case ForwardedToPayee = 'forwarded_to_payee';

    /** Returned to sender, with a reason and a status per check. Forward again, or hand back. */
    case Rts = 'rts';

    /** Retired (the old Confirm and Complete flow): the bank sent it back. Older ACICs only. */
    case ReturnedByBank = 'returned_by_bank';

    /** Closed by the teller's Action. Final. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::AcceptedByTeller => 'Accepted',
            self::ForwardedToLandBank => 'Forwarded to LBP',
            self::ForwardedToPayee => 'Forwarded to Payee',
            self::Rts => 'RTS',
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
            // Return to Admin clears the axis, so it is not a state here.
            self::AcceptedByTeller => [self::ForwardedToLandBank, self::ForwardedToPayee],
            self::ForwardedToLandBank, self::ForwardedToPayee => [self::Completed, self::Rts],
            // Put right and forwarded again; every cycle is kept in the history.
            self::Rts, self::ReturnedByBank => [self::ForwardedToLandBank, self::ForwardedToPayee],
            self::Completed => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->nextStates(), true);
    }

    /** Has the ACIC been closed? Final. */
    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    /** May the accepting teller Forward it (to Land Bank or to the payee) from here? */
    public function canForward(): bool
    {
        return in_array($this, [self::AcceptedByTeller, self::Rts, self::ReturnedByBank], true);
    }

    /** Is it out with Land Bank or the payee, awaiting the Action (Completed or RTS)? */
    public function isForwarded(): bool
    {
        return $this === self::ForwardedToLandBank || $this === self::ForwardedToPayee;
    }

    /** May the teller holding it hand it back to the admin? Not while it is out, nor once closed. */
    public function canReturnToAdmin(): bool
    {
        return in_array($this, [self::AcceptedByTeller, self::Rts, self::ReturnedByBank], true);
    }
}
