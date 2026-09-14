<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Advisory only: this exact Bank / Coop parent activity already carries a target with the same
 * inquiry type, institution and branch. Nothing is created when this is thrown — the CI either
 * reviews the existing record or deliberately proceeds via Continue Anyway (allow_duplicate),
 * because the same institution can legitimately be visited twice for one person.
 *
 * Deliberately NOT a unique constraint: Continue Anyway has to stay possible.
 */
class DuplicateCiActivityBankTargetException extends RuntimeException
{
    public function __construct(public readonly int $existingTargetId, string $targetLabel)
    {
        parent::__construct($targetLabel.' has already been added to this Bank / Coop Check. Please review the existing record or choose Continue Anyway.');
    }
}
