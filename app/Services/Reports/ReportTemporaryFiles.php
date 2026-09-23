<?php

namespace App\Services\Reports;

use Dompdf\Options;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Writer\AbstractWriter;
use RuntimeException;

class ReportTemporaryFiles
{
    public function directory(): string
    {
        $directory = storage_path('app/tmp/reports');

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("The report temporary directory could not be created: {$directory}");
        }

        if (! is_writable($directory)) {
            throw new RuntimeException("The report temporary directory is not writable: {$directory}");
        }

        return $directory;
    }

    public function create(string $prefix): string
    {
        $path = @tempnam($this->directory(), $prefix);

        if ($path === false) {
            throw new RuntimeException('A temporary report file could not be created in Laravel storage.');
        }

        return $path;
    }

    public function configureDompdf(Options $options): void
    {
        $directory = $this->directory();

        $options->set('tempDir', $directory);
        $options->set('chroot', [...ReportImageRoots::all(), $directory]);
    }

    public function configurePhpWord(AbstractWriter $writer): void
    {
        $writer->setTempDir($this->directory());
    }

    public function withPhpWordTempDirectory(callable $callback): mixed
    {
        $directory = $this->directory();
        $previousDirectory = Settings::getTempDir();

        Settings::setTempDir($directory);

        try {
            return $callback($directory);
        } finally {
            Settings::setTempDir($previousDirectory);
        }
    }
}
