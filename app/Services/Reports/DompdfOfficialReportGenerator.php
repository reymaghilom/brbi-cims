<?php

namespace App\Services\Reports;

use App\Services\Reports\Contracts\PdfGenerator;
use App\Services\Reports\Data\GeneratedReportArtifact;
use App\Services\Reports\Data\ReportRenderOptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class DompdfOfficialReportGenerator implements PdfGenerator
{
    public function generate(string $template, array $data, ReportRenderOptions $options): GeneratedReportArtifact
    {
        $path = $data['_artifact_path'];
        $html = view($template, ['document' => $data, 'pdfMode' => true])->render();
        $dompdfOptions = new Options;
        $dompdfOptions->set('defaultFont', 'DejaVu Sans');
        $dompdfOptions->set('isRemoteEnabled', false);
        $dompdfOptions->set('isPhpEnabled', false);
        $dompdfOptions->set('chroot', [storage_path('app/private'), public_path()]);
        // Dompdf's own default media type is "screen" (not "print"), so without this it wrongly
        // applies every report stylesheet's `@media screen` rules — including the official-sheet
        // `min-height` meant only for the on-screen preview — to the generated PDF too, forcing
        // page content past the printable page height and producing a near-blank second page.
        $dompdfOptions->set('defaultMediaType', 'print');
        $dompdf = new Dompdf($dompdfOptions);
        $dompdf->setPaper([0, 0, $options->widthInches * 72, $options->heightInches * 72]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $bytes = $dompdf->output();
        throw_unless(Storage::disk(config('cims.report_disk'))->put($path, $bytes), \RuntimeException::class, 'The PDF artifact could not be stored.');

        return new GeneratedReportArtifact($path, 'application/pdf', hash('sha256', $bytes), strlen($bytes));
    }
}
