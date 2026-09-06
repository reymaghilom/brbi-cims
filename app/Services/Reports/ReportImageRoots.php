<?php

namespace App\Services\Reports;

use App\Services\Storage\CiTeamDocumentStorage;
use RuntimeException;

/**
 * The filesystem roots Dompdf is allowed to read embedded images from.
 *
 * Dompdf's `chroot` is a security boundary, not a convenience list: any image outside it is silently
 * skipped, with no error. That is exactly what happened to locally stored Residence/Business Check
 * pictures and map screenshots once evidence moved into the CI Team document tree — the files were
 * resolved correctly and then dropped at render time because that tree was not listed here.
 *
 * `storage_path('app/private')` stays first: it holds the legacy media disk and the temporary
 * downloads ReportMediaResolver writes for Cloudinary-backed images.
 */
class ReportImageRoots
{
    /** @return list<string> */
    public static function all(): array
    {
        $roots = [storage_path('app/private'), public_path()];

        try {
            $roots[] = app(CiTeamDocumentStorage::class)->root();
        } catch (RuntimeException) {
            // No document root configured on this installation; the two defaults still apply.
        }

        return array_values(array_unique(array_filter($roots)));
    }
}
