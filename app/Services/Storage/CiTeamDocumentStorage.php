<?php

namespace App\Services\Storage;

use App\Enums\OfficialReportType;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
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

        return $this->ciTeamDiskFor($path ?? (string) $media->temporary_local_path);
    }

    /**
     * Picks the right local disk for one stored evidence path without consulting any global
     * setting: a legacy `client-media/...` path was written by PrivateMediaStorage::store() onto
     * the configured media disk, while anything else lives in the CI Team document tree. This is
     * what keeps pictures saved before an Evidence Storage switch readable afterwards.
     */
    public function evidenceDisk(string $path): FilesystemAdapter
    {
        return $this->isLegacyMediaPath($path)
            ? Storage::disk(config('cims.media_disk'))
            : $this->ciTeamDiskFor($path);
    }

    public function isLegacyMediaPath(string $path): bool
    {
        return str_starts_with(str_replace('\\', '/', $path), 'client-media/');
    }

    /** Resolves a CI Team relative path against the current root, falling back to the former explicit "Clients" root for installations that still hold files there. */
    private function ciTeamDiskFor(string $path): FilesystemAdapter
    {
        $path = $this->relative($path);
        $disk = $this->disk();
        if ($disk->exists($path)) {
            return $disk;
        }

        // Read-only fallback: building a local adapter CREATES its root directory, so the legacy
        // root is only ever opened when it genuinely exists already. Otherwise a failed lookup for
        // a file that was never there would leave an empty "Clients" folder behind.
        $legacyRoot = $this->root().DIRECTORY_SEPARATOR.'Clients';
        if (! is_dir($legacyRoot)) {
            return $disk;
        }

        $legacyDisk = Storage::build(['driver' => 'local', 'root' => $legacyRoot, 'throw' => true]);

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
        return $this->personDirectoryUnder($this->clientDirectory($client), $client, $coMaker);
    }

    /**
     * Top-level directory for LOCAL field evidence: the client's name on its own, which is what CI
     * staff actually browse by. Official report paths keep their existing "CI-#### - NAME" folder —
     * this deliberately does not rename anything that was already written.
     *
     * Two client folders can legitimately carry the same name, and two different names can sanitize
     * down to the same segment, so the plain name is only granted to the single folder that owns it
     * unambiguously: the earliest-created folder whose display name needs no sanitizing. Every other
     * folder keeps its own client number as a suffix, which is what makes it impossible for one
     * client's evidence to land in another client's directory.
     */
    public function evidenceClientDirectory(ClientFolder $client): string
    {
        $rawName = trim((string) ($client->display_name ?: 'Unnamed Client'));
        $name = $this->segment($rawName);

        if ($name === $rawName && ! $this->clientNameIsShared($client, $rawName)) {
            return $name;
        }

        return $name.' - '.$this->segment($this->filesystemClientNumber($client));
    }

    public function evidencePersonDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->personDirectoryUnder($this->evidenceClientDirectory($client), $client, $coMaker);
    }

    /** True when any OTHER client folder would claim the same plain-name directory. */
    private function clientNameIsShared(ClientFolder $client, string $rawName): bool
    {
        return ClientFolder::withTrashed()
            ->where('display_name', $rawName)
            ->whereKeyNot($client->getKey())
            ->exists();
    }

    private function personDirectoryUnder(string $base, ClientFolder $client, ?CoMaker $coMaker): string
    {
        if ($coMaker === null) {
            return $base;
        }
        if ((int) $coMaker->client_folder_id !== (int) $client->getKey()) {
            throw new RuntimeException('The co-maker does not belong to this client folder.');
        }

        return $base.'/Co-Makers/CM-'.str_pad((string) $coMaker->getKey(), 6, '0', STR_PAD_LEFT).' - '.$this->segment($coMaker->full_name ?: 'Unnamed Co-Maker');
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

    /**
     * Local homes for the three administrator-controlled evidence kinds, each nested inside the
     * exact Applicant/Co-Maker directory beneath that client's own name (evidenceClientDirectory).
     * Nothing here creates a directory — the folder only appears when a real file is written into
     * it, so resolving a path for a page render leaves the filesystem untouched.
     */
    public function residenceCheckPicturesDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->evidencePersonDirectory($client, $coMaker).'/Residence Check Report/Pictures';
    }

    public function residenceCheckMapDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->evidencePersonDirectory($client, $coMaker).'/Residence Check Report/Google Map';
    }

    public function businessCheckPicturesDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->evidencePersonDirectory($client, $coMaker).'/Business Check Report/Pictures';
    }

    public function businessCheckMapDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->evidencePersonDirectory($client, $coMaker).'/Business Check Report/Google Map';
    }

    public function ciActivityProofDirectory(ClientFolder $client, ?CoMaker $coMaker = null): string
    {
        return $this->evidencePersonDirectory($client, $coMaker).'/CI Activities/Supporting Proof';
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
