<?php

namespace App\Exceptions;

use RuntimeException;

class CoMakerConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Your changes were not saved because this Co-Maker was updated by another user. Please reload the latest information before trying again.');
    }
}
