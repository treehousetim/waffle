<?php

declare(strict_types=1);

namespace App\Domain\Status;

/**
 * The shared half of every status state machine.
 *
 * A status vocabulary is a backed enum in Domain/ that uses this trait; the
 * enum declares its allowed edges in allowedNext(), and this supplies the
 * checking. Repositories never set a status directly: they call
 * assertCanTransitionTo() first, so an illegal change is rejected before it
 * reaches the database.
 *
 * Enums are backed by the same strings as the `types` lookup rows, so
 * `types_name` and the enum case value are always the same word.
 */
trait HasTransitions
{
    /**
     * @return list<static> the statuses this one may move to
     */
    abstract public function allowedNext(): array;

    /** The noun used in the exception message, e.g. "order". */
    abstract public static function machineName(): string;

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * @throws IllegalTransition
     */
    public function assertCanTransitionTo(self $next): void
    {
        if (!$this->canTransitionTo($next)) {
            throw IllegalTransition::between(
                static::machineName(),
                (string) $this->value,
                (string) $next->value,
            );
        }
    }
}
