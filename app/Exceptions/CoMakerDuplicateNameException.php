<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Advisory only: another Co-Maker in the same Client Folder already has the same first and last
 * name. Nothing is saved when this is thrown; the user may still proceed deliberately with
 * Continue Anyway (duplicate_confirmed), because different people can share a name. Never a merge.
 */
class CoMakerDuplicateNameException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A Co-Maker with the same first and last name already exists in this Client Folder. Please verify the details before continuing.');
    }
}
