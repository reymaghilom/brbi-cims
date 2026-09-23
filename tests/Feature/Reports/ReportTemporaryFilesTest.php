<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\ReportTemporaryFiles;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use Tests\TestCase;

class ReportTemporaryFilesTest extends TestCase
{
    public function test_it_creates_unique_report_files_and_configures_dompdf_and_phpword(): void
    {
        $originalStoragePath = storage_path();
        $isolatedStoragePath = base_path('.tmp/report-temp-tests/'.Str::uuid());
        app()->useStoragePath($isolatedStoragePath);

        try {
            $temporaryFiles = app(ReportTemporaryFiles::class);
            $first = $temporaryFiles->create('report-test-');
            $second = $temporaryFiles->create('report-test-');
            $expectedDirectory = storage_path('app/tmp/reports');

            $this->assertDirectoryExists($expectedDirectory);
            $this->assertFileExists($first);
            $this->assertFileExists($second);
            $this->assertNotSame($first, $second);
            $this->assertSame($this->normalize($expectedDirectory), $this->normalize(dirname($first)));

            $options = new Options;
            $temporaryFiles->configureDompdf($options);
            $this->assertSame($this->normalize($expectedDirectory), $this->normalize($options->getTempDir()));
            $this->assertContains($this->normalize($expectedDirectory), array_map($this->normalize(...), $options->getChroot()));

            $phpWord = new PhpWord;
            $phpWord->addSection()->addText('Report temp test');
            $previousPhpWordDirectory = Settings::getTempDir();
            $observedGlobalDirectory = null;
            $internalWriterDirectory = null;

            $temporaryFiles->withPhpWordTempDirectory(function () use ($temporaryFiles, $phpWord, $first, &$observedGlobalDirectory, &$internalWriterDirectory): void {
                $observedGlobalDirectory = Settings::getTempDir();
                $writer = IOFactory::createWriter($phpWord, 'Word2007');
                $temporaryFiles->configurePhpWord($writer);
                $writer->save($first);
                $internalWriterDirectory = $writer->getTempDir();
            });

            $this->assertSame($this->normalize($expectedDirectory), $this->normalize($observedGlobalDirectory));
            $this->assertStringStartsWith($this->normalize($expectedDirectory).'/PHPWordWriter_', $this->normalize($internalWriterDirectory));
            $this->assertSame($this->normalize($previousPhpWordDirectory), $this->normalize(Settings::getTempDir()));
            $this->assertSame('PK', substr((string) file_get_contents($first), 0, 2));
            $this->assertSame([], File::directories($expectedDirectory), 'PhpWord must clean up its internal PHPWordWriter directory after save().');

            try {
                $temporaryFiles->withPhpWordTempDirectory(fn () => throw new \RuntimeException('Expected test exception.'));
                $this->fail('The callback exception should be rethrown.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Expected test exception.', $exception->getMessage());
            }
            $this->assertSame($this->normalize($previousPhpWordDirectory), $this->normalize(Settings::getTempDir()));

            unlink($first);
            unlink($second);
            $this->assertSame([], array_values(array_diff(scandir($expectedDirectory), ['.', '..'])));
        } finally {
            app()->useStoragePath($originalStoragePath);
            File::deleteDirectory($isolatedStoragePath);
        }
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
