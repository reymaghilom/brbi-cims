<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'administrator';
    case CreditInvestigator = 'credit_investigator';
    case SeniorCreditInvestigator = 'senior_credit_investigator';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::CreditInvestigator => 'Credit Investigator',
            self::SeniorCreditInvestigator => 'Senior Credit Investigator',
        };
    }

    /**
     * Whether this role does Credit Investigator field work — the shared CI workspace,
     * its folders and its scheduled-activity reminders.
     *
     * A Senior Credit Investigator is a Credit Investigator with one extra permission
     * (see canManageCibiSignatory()), not a separate kind of user, so every capability
     * gate asks this instead of comparing against CreditInvestigator directly. Queries
     * that build a candidate list — who may be assigned a folder, added as a
     * contributor, or picked as a CIBI signatory — deliberately keep their own explicit
     * role filters and are not covered here.
     */
    public function worksAsCreditInvestigator(): bool
    {
        return $this === self::CreditInvestigator || $this === self::SeniorCreditInvestigator;
    }

    /**
     * The roles a CIBI report's signatory (Prepared By / CI-in-charge) may hold.
     *
     * Signing a report is field work, so both Credit Investigator grades qualify and an
     * Administrator deliberately does not. This is the eligibility to *be* a signatory and is
     * separate from canManageCibiSignatory(), which is the permission to *change* one — the
     * dropdown and the server-side validation rule both read this single list.
     *
     * @return array<int, string>
     */
    public static function cibiSignatoryRoles(): array
    {
        return self::creditInvestigatorRoles();
    }

    /** @return array<int, string> */
    public static function creditInvestigatorRoles(): array
    {
        return [self::CreditInvestigator->value, self::SeniorCreditInvestigator->value];
    }

    /**
     * Whether this role may reassign a CIBI report's signatory (CI-in-charge).
     *
     * Administrators have always been able to; Senior Credit Investigators are trusted
     * with the same single permission. A plain Credit Investigator is not.
     */
    public function canManageCibiSignatory(): bool
    {
        return $this === self::Administrator || $this === self::SeniorCreditInvestigator;
    }
}
