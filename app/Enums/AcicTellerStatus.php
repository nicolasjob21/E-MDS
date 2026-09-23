<?php

namespace App\Enums;

/**
 * Where an ACIC stands with the tellers and the bank — the teller's own axis, alongside
 * `AcicStatus` (which tracks the ACIC's own life: open, used, approved, …).
 *
 *   Pending → Accepted by Teller → Completed
 *                                      │
 *                                      └─▶ Returned by Bank ─┬─▶ Completed again
 *                                                            └─▶ back to the admin
 *
 * Lodging the ACIC with Land Bank is not a resting state: **Confirm and Complete** records when
 * it went over the counter and closes it in the same step.
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

    /** The bank sent it back. Put it right and complete it again, or hand it to the admin. */
    case ReturnedByBank = 'returned_by_bank';

    /**
     * Lodged with Land Bank and closed. Not the end of the road: the bank may still send it
     * back, which is the one way out of here.
     */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::AcceptedByTeller => 'Accepted by Teller',
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
            self::AcceptedByTeller => [self::Completed],
            // The bank can send back an ACIC that was already closed.
            self::Completed => [self::ReturnedByBank],
            // Put it right and complete it again; every cycle is kept in the history.
            self::ReturnedByBank => [self::Completed],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->nextStates(), true);
    }

    /** Has the ACIC been lodged and closed? The bank may still return it. */
    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    /** May the teller complete it from here — the first time, or after a bank return? */
    public function canComplete(): bool
    {
        return in_array($this, [self::AcceptedByTeller, self::ReturnedByBank], true);
    }

    /** May the teller holding it hand it back to the admin? Not once the bank has credited it. */
    public function canReturnToAdmin(): bool
    {
        return in_array($this, [self::AcceptedByTeller, self::ReturnedByBank], true);
    }
}
