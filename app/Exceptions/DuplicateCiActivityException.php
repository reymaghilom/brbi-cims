<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Advisory only: this exact person already has a CI Activity of this type in this folder.
 *
 * BRBI-CIMS is collaborative — another CI may already be working the same activity — so this is a
 * WARNING, not the hard block it used to be. Nothing is created when this is thrown; the CI either
 * reviews the existing entry or deliberately proceeds via Continue Anyway (allow_duplicate), and
 * the second activity is then an independent row with its own creator, status and history.
 *
 * Deliberately NOT a unique constraint, and deliberately NOT applied to Edit: a stale edit must
 * still be refused outright, because overwriting another CI's newer save loses their work.
 */
class DuplicateCiActivityException extends RuntimeException
{
    public function __construct(public readonly int $existingActivityId, ?int $coMakerId)
    {
        parent::__construct(
            'A similar activity already exists for this '.($coMakerId === null ? 'Applicant' : 'Co-Maker')
            .'. Another CI may already be working on it. Please review the existing entry before continuing.'
        );
    }
}
