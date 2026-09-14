<?php

namespace App\Support\Authentication;

use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    /** A MINIMUM, never an exact length: any password of 8 or more characters is accepted. */
    public const MIN_LENGTH = 8;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH);
    }
}
