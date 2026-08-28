<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;

/**
 * Resolves every media item's real local file path in a `photo_sections` array (built by
 * OfficialReportDataBuilder) for PDF/DOCX embedding — an already-local image keeps its
 * `image_path` untouched; a Cloudinary-backed one (`image_path` null, `cloud` populated) is
 * downloaded into a fresh temporary file on demand. Web Preview never calls this at all — it uses
 * `web_url` and renders straight from Cloudinary/the browser, so nothing here ever runs on a plain
 * page view.
 *
 * Cleanup timing matters: Dompdf reads image bytes synchronously during render(), so its temp files
 * can be deleted right after; PhpWord's Word2007 writer defers reading until save() finishes (see
 * BuildsOfficialReportDocx::embedImage()'s own docblock), so DOCX callers must not clean up until
 * after that. Each generate() call should use its own resolve()/cleanup() pair — never share one
 * resolver instance's pending temp files across two unrelated report generations.
 */
class ReportMediaResolver
{
    public function __construct(private readonly CloudinaryMediaStorage $cloud) {}

    /** @var list<string> */
    private array $temporaryFiles = [];

    /**
     * @param  array<int, array<string, mixed>>  $photoSections
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $photoSections): array
    {
        foreach ($photoSections as &$section) {
            // Residence Check sections carry a flat `media` list; Business Check sections instead
            // carry `photo_groups` (each with its own nested `photos`) plus a separate flat
            // `competitor_photos` list — see OfficialReportDataBuilder::businessCheckSection().
            if (array_key_exists('media', $section)) {
                foreach ($section['media'] as &$item) {
                    $item['image_path'] = $this->resolveItem($item);
                }
                unset($item);
            }

            // Business Check sections carry their Business Photos (default group plus every
            // additional Photo Group, in saved order) and Competitor Photos pre-chunked into pages
            // by OfficialReportDataBuilder::paginateBusinessPhotos() — `photo_pages` and
            // `competitor_photo_pages` respectively, each a list of {caption, photos}. These (not
            // the flat `competitor_photos` below, which nothing actually renders from) are what
            // _photo-sections.blade.php and BuildsOfficialReportDocx actually embed, so resolving
            // image_path anywhere else would silently leave the real render path still unresolved.
            if (array_key_exists('photo_pages', $section)) {
                foreach ($section['photo_pages'] as &$page) {
                    foreach ($page['photos'] as &$item) {
                        $item['image_path'] = $this->resolveItem($item);
                    }
                    unset($item);
                }
                unset($page);
            }

            if (array_key_exists('competitor_photo_pages', $section)) {
                foreach ($section['competitor_photo_pages'] as &$page) {
                    foreach ($page['photos'] as &$item) {
                        $item['image_path'] = $this->resolveItem($item);
                    }
                    unset($item);
                }
                unset($page);
            }

            if (! empty($section['google_map'])) {
                $section['google_map']['image_path'] = $this->resolveItem($section['google_map']);
            }
        }
        unset($section);

        return $photoSections;
    }

    /** Deletes every temp file resolve() downloaded so far. Safe to call even if resolve() found nothing to download. */
    public function cleanup(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        $this->temporaryFiles = [];
    }

    /** @param  array<string, mixed>  $item */
    private function resolveItem(array $item): ?string
    {
        if (filled($item['image_path'] ?? null)) {
            return $item['image_path'];
        }

        $cloud = $item['cloud'] ?? null;
        if (blank($cloud['public_id'] ?? null)) {
            return null;
        }

        return $this->downloadToTemp($cloud);
    }

    /** @param  array{public_id: string, resource_type?: ?string, delivery_type?: ?string}  $cloud */
    private function downloadToTemp(array $cloud): ?string
    {
        try {
            $url = $this->cloud->deliveryUrl($cloud['public_id'], $cloud['delivery_type'] ?? null);
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return null;
            }

            // Deliberately NOT sys_get_temp_dir(): Dompdf's own `chroot` option only allows it to
            // load local images from inside storage_path('app/private') (and public_path()) — see
            // ResidenceBusinessCheckBatchPdfExporter/DompdfOfficialReportGenerator. A temp file
            // outside that boundary would silently fail to render for PDF, so every caller
            // (PDF or DOCX) gets its Cloudinary downloads from the same safe location.
            $directory = storage_path('app/private/tmp/cloud-media');
            if (! is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
            $temporary = @tempnam($directory, 'brbi-cloud-img-');
            if ($temporary === false || file_put_contents($temporary, $response->body()) === false) {
                return null;
            }
            $this->temporaryFiles[] = $temporary;

            return $temporary;
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
