<?php

namespace App\Services\Reports;

use App\Models\ClientFolder;
use App\Services\Reports\Data\ReportRenderOptions;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders one combined, print-ready PDF for a batch of selected Residence Checks / Business
 * Checks — the "Download Selected" convenience download on the Residence & Business Report page.
 * Mirrors BusinessBatchPdfExporter: bypasses the versioned GenerateOfficialReport/GeneratedReport
 * artifact system entirely, streaming bytes back directly for this disposable, non-versioned
 * combined download.
 */
class ResidenceBusinessCheckBatchPdfExporter
{
    /** @param  array<int, array<string, mixed>>  $photoSections */
    public function generate(ClientFolder $folder, array $photoSections, string $title): string
    {
        $html = view('reports.official.residence-business-check-batch', [
            'photoSections' => $photoSections,
            'pdfMode' => true,
            'title' => $title,
            'clientFolder' => $folder,
            'personParams' => [],
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', [storage_path('app/private'), public_path()]);
        $options->set('defaultMediaType', 'print');

        $render = ReportRenderOptions::brbiDefault();
        $dompdf = new Dompdf($options);
        $dompdf->setPaper([0, 0, $render->widthInches * 72, $render->heightInches * 72]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }
}
