<?php

namespace App\Enums;

enum OfficialReportType: string
{
    case Cibi = 'cibi';
    case BusinessIncomeSource = 'business_income_source';
    case GeneralIncomeSource = 'general_income_source';
    case ResidenceBusinessPhoto = 'residence_business_photo';

    public function label(): string
    {
        return match ($this) {
            self::Cibi => 'CI / BI Report',
            self::BusinessIncomeSource => 'Business / Income Source Report',
            self::GeneralIncomeSource => 'Sources of Income Declared by Client',
            self::ResidenceBusinessPhoto => 'Residence & Business Photo Report',
        };
    }

    /** Compact label for dense listings (the Reports table badge); label() stays the full title. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Cibi => 'CIBI',
            self::BusinessIncomeSource => 'Business Report',
            self::GeneralIncomeSource => 'Declared Income',
            self::ResidenceBusinessPhoto => 'Residence & Business',
        };
    }

    public function requiresIncomeSource(): bool
    {
        return $this === self::BusinessIncomeSource || $this === self::GeneralIncomeSource;
    }
}
