<?php

namespace App\Services\ClientFolders;

use App\Enums\AddressType;
use App\Models\ClientFolder;
use App\Models\CoMaker;

/**
 * Resolves the authoritative, current address for whichever person a Residence Check is being
 * encoded for — the Applicant's Present Address, or a Co-Maker's own `address` field. This is
 * the single source of truth for that address: it must never be taken from client input, since
 * the Residence Check form only ever displays it read-only.
 *
 * For the Applicant, "Present Address" is whatever the CI/BI Report itself currently shows —
 * see CibiReportFormData::for(), which the CI/BI encoding page uses to build the exact same
 * field. Once a CI/BI Report has been saved, its own `personal_snapshot.present_address` (a
 * required, independently CI-confirmed value) always wins there over the structured
 * client_addresses "present" row, so this resolver matches that same precedence rather than
 * reading client_addresses alone — otherwise the two pages could show two different addresses
 * for the same person, and Residence Check would report "no address" even when CI/BI Report
 * plainly has one on screen.
 */
class PersonAddressResolver
{
    public static function resolve(ClientFolder $folder, ?CoMaker $activePerson): ?string
    {
        if ($activePerson) {
            return filled($activePerson->address) ? $activePerson->address : null;
        }

        $cibiPresentAddress = self::applicantCibiPresentAddress($folder);
        if (filled($cibiPresentAddress)) {
            return $cibiPresentAddress;
        }

        $address = $folder->addresses()->where('address_type', AddressType::Present->value)->first();
        if (! $address) {
            return null;
        }

        $formatted = collect([
            $address->address_line_1,
            $address->address_line_2,
            $address->barangay,
            $address->city_municipality,
            $address->province,
            $address->postal_code,
        ])->filter()->implode(', ');

        return filled($formatted) ? $formatted : null;
    }

    /**
     * The Applicant's own CI/BI Report — never a Co-Maker's, whose record shares the same table
     * with the same "co_maker_id is null means Applicant" convention used everywhere else — and
     * only its present_address, normalized the same way CibiReportFormData::for() treats a
     * literal "N/A" as blank.
     */
    private static function applicantCibiPresentAddress(ClientFolder $folder): ?string
    {
        $snapshot = $folder->cibiReports()->where('co_maker_id', null)->first()?->personal_snapshot ?? [];
        $value = $snapshot['present_address'] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strcasecmp($trimmed, 'N/A') === 0) {
            return null;
        }

        return $trimmed;
    }
}
