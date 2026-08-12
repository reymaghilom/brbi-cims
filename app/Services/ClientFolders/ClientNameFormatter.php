<?php

namespace App\Services\ClientFolders;

class ClientNameFormatter
{
    public function format(string $lastName, string $firstName, ?string $middleName = null, ?string $suffix = null): string
    {
        $givenNames = collect([$firstName, $middleName, $suffix])
            ->filter(fn (?string $value): bool => filled($value))
            ->implode(' ');

        return "{$lastName}, {$givenNames}";
    }
}
