<?php

namespace PDMFC\PdfGallery\Services\Convert;

use PDMFC\PdfGallery\Contracts\DocumentToPdfConverter;
use PDMFC\PdfGallery\Support\CliBinaryResolver;
use PDMFC\PdfGallery\Support\DocumentMimeTypes;
use PDMFC\PdfGallery\Support\TempFile;
use Symfony\Component\Process\Process;

class GhostscriptImageConverter implements DocumentToPdfConverter
{
    public function __construct(
        private readonly CliBinaryResolver $binaries,
    ) {}

    public function name(): string
    {
        return 'ghostscript';
    }

    public function supports(string $mimeType, string $filename): bool
    {
        return DocumentMimeTypes::isImage($mimeType, $filename);
    }

    public function isAvailable(): bool
    {
        return $this->binaries->resolveGhostscript() !== null;
    }

    public function convert(string $absoluteInputPath, string $mimeType, string $filename): string
    {
        $binary = $this->binaries->resolveGhostscript();

        if ($binary === null) {
            throw new \RuntimeException('Ghostscript não está disponível para converter imagens.');
        }

        $output = TempFile::path('pdf');

        try {
            $process = new Process([
                $binary,
                '-dBATCH',
                '-dNOPAUSE',
                '-dSAFER',
                '-q',
                '-sDEVICE=pdfwrite',
                '-dPDFSETTINGS=/prepress',
                '-sOutputFile='.$output,
                $absoluteInputPath,
            ]);
            $process->setTimeout((int) config('pdf-gallery.convert.timeout_seconds', 120));
            $process->run();

            $content = is_file($output) ? file_get_contents($output) : false;

            if (is_string($content) && str_starts_with($content, '%PDF-')) {
                return $content;
            }

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Ghostscript falhou.');
            }

            throw new \RuntimeException('Ghostscript não produziu um PDF válido.');
        } finally {
            TempFile::cleanup($output);
        }
    }
}
