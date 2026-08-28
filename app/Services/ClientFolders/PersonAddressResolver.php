<?php

namespace App\Services\ClientFolders;

use App\Enums\AddressType;
use App\Models\ClientFolder;
use App\Models\CoMaker;

/**
 * Resolves the authoritative, current address for whichever person a Residence Check is being
 * encoded for — the exact person's CI/BI Present Address, with that person's profile address as
 * the fallback. This supplies the initial value for a new Residence Check. The Residence Location
 * remains an editable report snapshot after that prefill.
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
        $cibiPresentAddress = self::cibiPresentAddress($folder, $activePerson);
        if (filled($cibiPresentAddress)) {
            return $cibiPresentAddress;
        }

        if ($activePerson) {
            return filled($activePerson->address) ? $activePerson->address : null;
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
     * The exact person's own CI/BI Report, scoped through this folder and the same "co_maker_id is
     * null means Applicant" convention used everywhere else. Only its present_address is used,
     * normalized the same way CibiReportFormData::for() treats a literal "N/A" as blank.
     */
    private static function cibiPresentAddress(ClientFolder $folder, ?CoMaker $activePerson): ?string
    {
        $snapshot = $folder->cibiReports()->where('co_maker_id', $activePerson?->id)->first()?->personal_snapshot ?? [];
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
