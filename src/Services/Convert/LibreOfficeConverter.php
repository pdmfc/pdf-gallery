<?php

namespace PDMFC\PdfGallery\Services\Convert;

use PDMFC\PdfGallery\Contracts\DocumentToPdfConverter;
use PDMFC\PdfGallery\Support\CliBinaryResolver;
use PDMFC\PdfGallery\Support\DocumentMimeTypes;
use PDMFC\PdfGallery\Support\TempFile;
use Symfony\Component\Process\Process;

class LibreOfficeConverter implements DocumentToPdfConverter
{
    public function __construct(
        private readonly CliBinaryResolver $binaries,
    ) {}

    public function name(): string
    {
        return 'libreoffice';
    }

    public function supports(string $mimeType, string $filename): bool
    {
        return DocumentMimeTypes::isWord($mimeType, $filename);
    }

    public function isAvailable(): bool
    {
        return $this->binaries->resolveLibreOffice() !== null;
    }

    public function convert(string $absoluteInputPath, string $mimeType, string $filename): string
    {
        $binary = $this->binaries->resolveLibreOffice();

        if ($binary === null) {
            throw new \RuntimeException('LibreOffice não está disponível para converter documentos Word.');
        }

        $outputDir = sys_get_temp_dir().'/pdf-gallery-lo-'.bin2hex(random_bytes(4));

        if (! @mkdir($outputDir, 0700, true) && ! is_dir($outputDir)) {
            throw new \RuntimeException('Não foi possível criar pasta temporária para LibreOffice.');
        }

        try {
            $process = Process::fromShellCommandline(
                'export HOME='.escapeshellarg(sys_get_temp_dir()).' && '
                .escapeshellarg($binary)
                .' --headless --norestore --convert-to pdf '
                .'--outdir '.escapeshellarg($outputDir).' '
                .escapeshellarg($absoluteInputPath)
            );
            $process->setTimeout((int) config('pdf-gallery.convert.timeout_seconds', 120));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'LibreOffice falhou.');
            }

            $baseName = pathinfo($absoluteInputPath, PATHINFO_FILENAME);
            $pdfPath = $outputDir.'/'.$baseName.'.pdf';

            if (! is_file($pdfPath)) {
                $candidates = glob($outputDir.'/*.pdf') ?: [];

                if ($candidates === []) {
                    throw new \RuntimeException('LibreOffice não produziu um PDF.');
                }

                $pdfPath = $candidates[0];
            }

            $content = file_get_contents($pdfPath);

            if (! is_string($content) || ! str_starts_with($content, '%PDF-')) {
                throw new \RuntimeException('LibreOffice não produziu um PDF válido.');
            }

            return $content;
        } finally {
            foreach (glob($outputDir.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($outputDir);
        }
    }
}
