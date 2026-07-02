<?php

namespace PDMFC\PdfGallery\Services;

use Illuminate\Support\Facades\Storage;
use PDMFC\PdfGallery\Support\CliBinaryResolver;
use PDMFC\PdfGallery\Support\PdfStorage;
use Symfony\Component\Process\Process;

class PdfThumbnailService
{
    public function __construct(
        private readonly PdfStorage $storage,
        private readonly CliBinaryResolver $binaries,
    ) {}

    public function thumbnailFilename(string $pdfFilename): string
    {
        $base = pathinfo($this->storage->safeFilename($pdfFilename), PATHINFO_FILENAME);

        return $base.'_thumb.jpg';
    }

    /**
     * Gera a miniatura se necessário e devolve o caminho absoluto do JPEG.
     */
    public function ensureThumbnail(string|int $userId, string $pdfFilename): ?string
    {
        $pdfFilename = $this->storage->safeFilename($pdfFilename);
        $thumbFilename = $this->thumbnailFilename($pdfFilename);
        $thumbAbsolute = $this->storage->storagePath($userId, $thumbFilename);

        if (is_file($thumbAbsolute) && filesize($thumbAbsolute) > 0) {
            return $thumbAbsolute;
        }

        $pdfAbsolute = $this->storage->storagePath($userId, $pdfFilename);

        if (! is_file($pdfAbsolute)) {
            return null;
        }

        if ($this->generateWithGhostscript($pdfAbsolute, $thumbAbsolute)) {
            return is_file($thumbAbsolute) ? $thumbAbsolute : null;
        }

        return null;
    }

    public function deleteThumbnail(string|int $userId, string $pdfFilename): void
    {
        $thumbFilename = $this->thumbnailFilename($pdfFilename);
        $relative = $this->storage->filePath($userId, $thumbFilename);

        if (Storage::disk($this->storage->disk())->exists($relative)) {
            Storage::disk($this->storage->disk())->delete($relative);
        }
    }

    private function generateWithGhostscript(string $pdfAbsolute, string $thumbAbsolute): bool
    {
        $binary = $this->binaries->resolveGhostscript();

        if ($binary === null) {
            return false;
        }

        $process = new Process([
            $binary,
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-sDEVICE=jpeg',
            '-dJPEGQ=82',
            '-r120',
            '-dFirstPage=1',
            '-dLastPage=1',
            '-sOutputFile='.$thumbAbsolute,
            $pdfAbsolute,
        ]);
        $process->setTimeout(60);
        $process->run();

        return $process->isSuccessful() && is_file($thumbAbsolute) && filesize($thumbAbsolute) > 0;
    }
}
