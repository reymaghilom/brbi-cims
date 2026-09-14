<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Advisory only: a manual Business Check for this exact person already carries the same Business
 * Name, Location and CI Date. Nothing is created when this is thrown — the CI is asked to review
 * the existing record, and may still proceed deliberately via Continue Anyway
 * (allow_similar_duplicate), because one person may legitimately run several businesses.
 */
class SimilarBusinessCheckExistsException extends RuntimeException
{
    public function __construct(public readonly int $existingCheckId)
    {
        parent::__construct('A similar Business Check already exists for this person with the same Business Name, Location, and CI Date. Please review the existing record before creating another one.');
    }
}
