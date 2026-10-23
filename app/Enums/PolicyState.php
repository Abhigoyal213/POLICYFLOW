<?php

namespace App\Enums;

enum PolicyState: string
{
    case RECEIVED = 'RECEIVED';
    case VALIDATED = 'VALIDATED';
    case SUBMITTED = 'SUBMITTED';
    case PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    case ISSUED = 'ISSUED';
    case FAILED = 'FAILED';
    case NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    /**
     * Get the allowed transitions from this state.
     *
     * @return PolicyState[]
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::RECEIVED => [self::VALIDATED],
            self::VALIDATED => [self::SUBMITTED],
            self::SUBMITTED => [self::PENDING_CONFIRMATION, self::ISSUED, self::FAILED, self::NEEDS_RECONCILIATION],
            self::PENDING_CONFIRMATION => [self::ISSUED, self::FAILED, self::NEEDS_RECONCILIATION],
            self::FAILED => [self::SUBMITTED],
            self::NEEDS_RECONCILIATION => [self::ISSUED, self::FAILED],
            self::ISSUED => [], // Terminal state — no transitions out
        };
    }

    /**
     * Check if a transition to the given state is allowed.
     */
    public function canTransitionTo(PolicyState $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether this is a terminal (final) state.
     */
    public function isTerminal(): bool
    {
        return $this === self::ISSUED;
    }
}
