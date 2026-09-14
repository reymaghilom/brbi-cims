<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Advisory only: this exact Asset Check parent activity already carries a target with the same
 * assessor type and office / location. Nothing is created when this is thrown — the CI either
 * reviews the existing record or deliberately proceeds via Continue Anyway (allow_duplicate),
 * because the same office can legitimately be visited twice for one person.
 *
 * Deliberately NOT a unique constraint: Continue Anyway has to stay possible.
 */
class DuplicateCiActivityAssetTargetException extends RuntimeException
{
    public function __construct(public readonly int $existingTargetId)
    {
        parent::__construct('An Asset Check target with the same Assessor Type and Office / Location already exists. Please review the existing target before adding another one.');
    }
}
