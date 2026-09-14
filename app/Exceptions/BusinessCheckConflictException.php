<?php

namespace App\Exceptions;

use RuntimeException;

class BusinessCheckConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Business Check was updated by another user while you were working on it. Please review the latest information before saving again.');
    }
}
