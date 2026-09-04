<?php

namespace App\Services\Storage;

use App\Enums\OfficialReportType;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CiTeamDocumentStorage
{
    public function root(): string
    {
        $configured = config('cims.documents_root');
        if (filled($configured)) {
            $configured = rtrim((string) $configured, '\\/');

            // Older installations may still carry the former explicit root ending in
            // "Clients". Normalize that one legacy suffix so every new write lands directly
            // beneath CI Team while the separate legacy read resolver remains available.
            return preg_replace('#[\\\\/]Clients$#i', '', $configured) ?: $configured;
        }

        $profile = $this->windowsProfileDirectory();
        if ($profile === null) {
            throw new RuntimeException('CIMS_DOCUMENTS_ROOT must be configured when USERPROFILE is unavailable.');
        }

        return rtrim($profile, '\\/').DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'CI Team';
    }

    private function windowsProfileDirectory(): ?string
    {
        $homeDrive = $this->environmentValue('HOMEDRIVE');
        $homePath = $this->environmentValue('HOMEPATH');
        $localAppData = $this->environmentValue('LOCALAPPDATA');
        $roamingAppData = $this->environmentValue('APPDATA');

        $candidates = [
            $this->environmentValue('USERPROFILE'),
            $homeDrive !== null && $homePath !== null ? $homeDrive.$homePath : null,
            $localAppData !== null ? dirname($localAppData) : null,
            $roamingAppData !== null ? dirname(dirname($roamingAppData)) : null,
            $this->environmentValue('HOME'),
            $this->profileFromWindowsTempDirectory((string) ini_get('upload_tmp_dir')),
            $this->profileFromWindowsTempDirectory((string) ini_get('sys_temp_dir')),
            $this->profileFromWindowsTempDirectory(sys_get_temp_dir()),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return rtrim($candidate, '\\/');
            }
        }

        return null;
    }

    private function profileFromWindowsTempDirectory(string $path): ?string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if (! preg_match('#^([A-Za-z]:/Users/[^/]+)/AppData/Local/Temp(?:/|$)#i', $normalized, $matches)) {
            return null;
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $matches[1]);
    }

    private function environmentValue(string $key): ?string
    {
        foreach ([getenv($key), $_SERVER[$key] ?? null, $_ENV[$key] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::build(['driver' => 'local', 'root' => $this->root(), 'throw' => true]);
    }

    public function diskForMedia(MediaReference $media, ?string $path = null): FilesystemAdapter
    {
        if ($media->storage_provider !== MediaReference::STORAGE_PROVIDER_CI_TEAM) {
            return Storage::disk(config('cims.media_disk'));
        }

        $path = $this->relative($path ?? (string) $media->temporary_local_path);
        $disk = $this->disk();
        if ($disk->exists($path)) {
            return $disk;
        }

        $legacyDisk = Storage::build([
            'driver' => 'local',
            'root' => $this->root().DIRECTORY_SEPARATOR.'Clients',
            'throw' => true,
        ]);

        return $legacyDisk->exists($path) ? $legacyDisk : $disk;
    }

    public function clientDirectory(ClientFolder $client): string
    {
        $number = $this->filesystemClientNumber($client);

        return $this->segment($number).' - '.$this->segment($client->display_name ?: 'Unnamed Client');
    }

    private function filesystemClientNumber(ClientFolder $client): string
    {
        $authoritative = trim((string) $client->folder_number);
        if (preg_match('/^BRBI-CI-(\d{4})-(\d+)$/i', $authoritative, $matches)) {
            $sequence = ltrim($matches[2], '0');

            return 'CI-'.$matches[1].'-'.str_pad($sequence === '' ? '0' : $sequence, 3, '0', STR_PAD_LEFT);
        }

        return 'CI-'.str_pad((string) max(0, (int) $client->getKey()), 3, '0', STR_PAD_LEFT);
    }

    public function personDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        $base = $this->clientDirectory($client);
        if ($coMaker === null) {
            return $base;
        }
        if ((int) $coMaker->client_folder_id !== (int) $client->getKey()) {
            throw new RuntimeException('The co-maker does not belong to this client folder.');
        }

        return $base.'/Co-Makers/CM-'.str_pad((string) $coMaker->getKey(), 6, '0', STR_PAD_LEFT).' - '.$this->segment($coMaker->full_name ?: 'Unnamed Co-Maker');
    }

    public function residenceGoogleMapDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectory($client, $coMaker).'/Residence Pictures/Google Map';
    }

    public function residencePicturesDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectory($client, $coMaker).'/Residence Pictures/Pictures';
    }

    public function residenceVideosDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectory($client, $coMaker).'/Residence Pictures/Videos';
    }

    public function businessGoogleMapDirectory(ClientFolder $client, ?CoMaker $coMaker, ResidenceBusinessDocumentation $documentation): string
    {
        return $this->businessPicturesRoot($client, $coMaker, $documentation).'/Google Map';
    }

    public function businessPicturesDirectory(ClientFolder $client, ?CoMaker $coMaker, ResidenceBusinessDocumentation $documentation): string
    {
        return $this->businessPicturesRoot($client, $coMaker, $documentation).'/Pictures';
    }

    public function businessVideosDirectory(ClientFolder $client, ?CoMaker $coMaker, ResidenceBusinessDocumentation $documentation): string
    {
        return $this->businessPicturesRoot($client, $coMaker, $documentation).'/Videos';
    }

    private function businessPicturesRoot(ClientFolder $client, ?CoMaker $coMaker, ResidenceBusinessDocumentation $documentation): string
    {
        $root = $this->personDirectory($client, $coMaker).'/Business Pictures';

        return $root.'/BD-'.str_pad((string) $documentation->getKey(), 6, '0', STR_PAD_LEFT);
    }

    public function cibiReportDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectory($client, $coMaker).'/CIBI Report';
    }

    public function businessReportDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectory($client, $coMaker).'/Business Report';
    }

    public function ciActivitiesDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectory($client, $coMaker).'/CI Activities';
    }

    public function residenceCheckReportDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectory($client, $coMaker).'/Residence Check Report';
    }

    public function businessCheckReportDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectory($client, $coMaker).'/Business Check Report';
    }

    public function documentationDirectory(ClientFolder $client, ?CoMaker $coMaker, string $category, string $kind, ResidenceBusinessDocumentation $documentation): string
    {
        $business = $category === 'business';

        return match ($kind) {
            'map' => $business ? $this->businessGoogleMapDirectory($client, $coMaker, $documentation) : $this->residenceGoogleMapDirectory($client, $coMaker),
            'video' => $business ? $this->businessVideosDirectory($client, $coMaker, $documentation) : $this->residenceVideosDirectory($client, $coMaker),
            default => $business ? $this->businessPicturesDirectory($client, $coMaker, $documentation) : $this->residencePicturesDirectory($client, $coMaker),
        };
    }

    public function officialReportPath(ClientFolder $client, OfficialReportType $type, string $filename, ?CoMaker $coMaker = null): string
    {
        $directory = match ($type) {
            OfficialReportType::Cibi => $this->cibiReportDirectory($client, $coMaker),
            OfficialReportType::BusinessIncomeSource, OfficialReportType::GeneralIncomeSource => $this->businessReportDirectory($client, $coMaker),
            OfficialReportType::ResidenceBusinessPhoto => $this->residenceCheckReportDirectory($client, $coMaker),
        };

        return $this->relative($directory.'/'.$this->segment($filename));
    }

    public function relative(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)) {
            throw new RuntimeException('An invalid document storage path was rejected.');
        }
        foreach (explode('/', $normalized) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('An invalid document storage path was rejected.');
            }
        }

        return $normalized;
    }

    public function segment(string $value): string
    {
        $value = Str::of($value)->ascii()->replaceMatches('/[<>:"\/\\\\|?*\x00-\x1F]/', ' ')->squish()->trim(' .')->limit(120, '')->toString();
        if ($value === '' || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/i', $value)) {
            $value = '_'.($value ?: 'Unnamed');
        }

        return $value;
    }

    public function isLegacyReportPath(string $path): bool
    {
        return str_starts_with(str_replace('\\', '/', $path), 'generated-reports/');
    }
}
