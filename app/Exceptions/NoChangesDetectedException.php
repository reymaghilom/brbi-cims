<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by an Action's execute() when the incoming data, after normalization, matches the
 * record's current persisted state exactly — signaling the caller to skip the save entirely
 * (no audit entry, no Recent Activity, no revision/timestamp bump) and show a neutral "No changes
 * detected" notice instead of a success message.
 */
class NoChangesDetectedException extends RuntimeException
{
    public function __construct(string $message = 'No changes detected. Nothing was updated.')
    {
        parent::__construct($message);
    }
}
