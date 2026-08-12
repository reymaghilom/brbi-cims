<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'administrator';
    case CreditInvestigator = 'credit_investigator';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::CreditInvestigator => 'Credit Investigator',
        };
    }
}
