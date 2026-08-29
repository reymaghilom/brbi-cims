<?php

namespace App\Services\Media;

use App\Exceptions\CloudMediaUploadException;
use Cloudinary\Asset\Image;
use Cloudinary\Cloudinary;
use Cloudinary\Transformation\Resize;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Cloudinary-backed storage for the four media kinds currently migrated to it: Residence/Business
 * Pictures and their Map Screenshots. Mirrors PrivateMediaStorage::store()'s return shape (plus
 * the cloud_* metadata columns) so callers can persist either result with the same field names.
 *
 * Every upload is normalized server-side (Cloudinary "incoming transformation" — the stored master
 * copy itself is already the resized/compressed version, not a full-size original) and stored under
 * `type: authenticated`, the account never has to be public and no asset is servable without a
 * signed URL. enabled() is false whenever no Cloudinary credentials are configured, so callers can
 * transparently keep using local storage instead — see SaveResidenceCheck/SaveBusinessCheck.
 */
class CloudinaryMediaStorage
{
    private const DELIVERY_TYPE = 'authenticated';

    private const MIME_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif',
    ];

    private ?Cloudinary $client = null;

    public function enabled(): bool
    {
        return filled(config('cloudinary.url'))
            || (filled(config('cloudinary.cloud_name')) && filled(config('cloudinary.api_key')) && filled(config('cloudinary.api_secret')));
    }

    /**
     * Uploads one image under BRBI-CIMS/{$folder}/{uuid}, applying config('cloudinary.'.$preset)'s
     * max dimension/quality as an incoming transformation. Throws CloudMediaUploadException on any
     * failure — there is no partial-success state to recover from, so callers keep the same
     * try/catch-and-cleanup convention they already use around PrivateMediaStorage::store(). The
     * underlying SDK/HTTP exception (which can carry request/response details) is reported for
     * investigation but never propagated — a CI-facing UI must only ever see the safe, generic
     * message on CloudMediaUploadException.
     *
     * @return array{file_name:string, mime_type:string, byte_size:int, checksum:string, cloud_public_id:string, cloud_resource_type:string, cloud_delivery_type:string, cloud_format:string, cloud_width:?int, cloud_height:?int}
     */
    public function store(UploadedFile $file, string $folder, string $preset = 'photo'): array
    {
        $settings = config('cloudinary.'.$preset) ?? config('cloudinary.photo');
        $publicId = Str::uuid()->toString();
        $checksum = hash_file('sha256', $file->getRealPath());

        try {
            $response = $this->client()->uploadApi()->upload($file->getRealPath(), [
                'folder' => $this->folderFor($folder),
                'public_id' => $publicId,
                'resource_type' => 'image',
                'type' => self::DELIVERY_TYPE,
                'overwrite' => false,
                'unique_filename' => false,
                'use_filename' => false,
                'transformation' => [
                    'crop' => 'limit',
                    'width' => $settings['max_dimension'],
                    'height' => $settings['max_dimension'],
                    'quality' => $settings['quality'],
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            throw new CloudMediaUploadException;
        }

        $format = (string) ($response['format'] ?? 'jpg');

        return [
            'file_name' => $publicId.'.'.$format,
            'mime_type' => self::MIME_TYPES[strtolower($format)] ?? 'image/jpeg',
            'byte_size' => (int) ($response['bytes'] ?? $file->getSize()),
            'checksum' => $checksum,
            'cloud_public_id' => (string) $response['public_id'],
            'cloud_resource_type' => (string) ($response['resource_type'] ?? 'image'),
            'cloud_delivery_type' => (string) ($response['type'] ?? self::DELIVERY_TYPE),
            'cloud_format' => $format,
            'cloud_width' => isset($response['width']) ? (int) $response['width'] : null,
            'cloud_height' => isset($response['height']) ? (int) $response['height'] : null,
        ];
    }

    /**
     * Best-effort delete — used both for replace/remove cleanup once the owning database change has
     * already succeeded, and for rolling back a newly-uploaded orphan when a later step in the same
     * save fails. Never throws: a failed cleanup must not itself break the save/replace it's
     * cleaning up after, so failures are only reported for later investigation.
     */
    public function destroy(?string $publicId, ?string $resourceType = 'image', ?string $deliveryType = self::DELIVERY_TYPE): void
    {
        if (blank($publicId)) {
            return;
        }

        try {
            $this->client()->uploadApi()->destroy($publicId, [
                'resource_type' => $resourceType ?: 'image',
                'type' => $deliveryType ?: self::DELIVERY_TYPE,
                'invalidate' => true,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /** The main/report delivery URL — the stored asset as-is (already normalized at upload time), never a further transformation, so viewing or embedding it never spends an extra Cloudinary transformation. Signed for `authenticated` delivery; never exposes the API secret itself. */
    public function deliveryUrl(string $publicId, ?string $deliveryType = self::DELIVERY_TYPE): string
    {
        return (string) $this->asset($publicId, $deliveryType);
    }

    /** A small on-the-fly derivative for table/gallery/form previews — reuses the same stored asset (no separate upload), and is the only extra transformation this app ever requests per asset. */
    public function thumbnailUrl(string $publicId, ?string $deliveryType = self::DELIVERY_TYPE): string
    {
        $settings = config('cloudinary.thumbnail');

        return (string) $this->asset($publicId, $deliveryType)
            ->resize(Resize::limitFit()->width($settings['width']))
            ->quality($settings['quality']);
    }

    private function asset(string $publicId, ?string $deliveryType): Image
    {
        return $this->client()->image($publicId)
            ->deliveryType($deliveryType ?: self::DELIVERY_TYPE)
            ->signUrl(true);
    }

    public function folderFor(string $folder): string
    {
        return rtrim((string) config('cloudinary.root_folder', 'BRBI-CIMS'), '/').'/'.trim($folder, '/');
    }

    private function client(): Cloudinary
    {
        return $this->client ??= new Cloudinary($this->configuration());
    }

    private function configuration(): array|string
    {
        if (filled(config('cloudinary.url'))) {
            return (string) config('cloudinary.url');
        }

        return [
            'cloud' => [
                'cloud_name' => config('cloudinary.cloud_name'),
                'api_key' => config('cloudinary.api_key'),
                'api_secret' => config('cloudinary.api_secret'),
            ],
            'url' => ['secure' => config('cloudinary.secure', true)],
        ];
    }
}
