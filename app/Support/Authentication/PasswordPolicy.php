<?php

namespace App\Support\Authentication;

use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH);
    }
}
