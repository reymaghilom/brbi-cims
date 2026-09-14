<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * One Residence Check per exact person (Applicant, or one specific Co-Maker) already exists, so
 * this CREATE must not add a second one. Carries that existing record's id so the caller can send
 * the CI to the real Residence Check instead of leaving them on a create form that can never save.
 */
class ResidenceCheckAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly int $existingCheckId)
    {
        parent::__construct('A Residence Check already exists for this person. It was opened by another user while you were adding this one — review the existing Residence Check instead.');
    }
}
