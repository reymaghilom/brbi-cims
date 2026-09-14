<?php

namespace App\Exceptions;

use RuntimeException;

class ResidenceCheckConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Residence Check was updated by another user. Review or reload the latest version before saving again.');
    }
}
