<?php

namespace PDMFC\PdfGallery\Services\Convert;

use FPDF;
use PDMFC\PdfGallery\Contracts\DocumentToPdfConverter;
use PDMFC\PdfGallery\Support\DocumentMimeTypes;
use PDMFC\PdfGallery\Support\TempFile;

class FpdfImageConverter implements DocumentToPdfConverter
{
    private const FPDF_NATIVE_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_GIF,
    ];

    public function name(): string
    {
        return 'fpdf';
    }

    public function supports(string $mimeType, string $filename): bool
    {
        return DocumentMimeTypes::isImage($mimeType, $filename);
    }

    public function isAvailable(): bool
    {
        return class_exists(FPDF::class);
    }

    public function convert(string $absoluteInputPath, string $mimeType, string $filename): string
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('FPDF não está disponível.');
        }

        [$imagePath, $tempPath] = $this->resolveRenderableImagePath($absoluteInputPath);

        try {
            $info = @getimagesize($imagePath);

            if ($info === false) {
                throw new \RuntimeException('Imagem ilegível ou formato não suportado.');
            }

            [$pxW, $pxH] = $info;
            $dpi = 96;
            $imgMmW = max(1.0, $pxW * 25.4 / $dpi);
            $imgMmH = max(1.0, $pxH * 25.4 / $dpi);

            $pageW = 210.0;
            $pageH = 297.0;
            $scale = min($pageW / $imgMmW, $pageH / $imgMmH);
            $drawW = $imgMmW * $scale;
            $drawH = $imgMmH * $scale;
            $offsetX = ($pageW - $drawW) / 2;
            $offsetY = ($pageH - $drawH) / 2;

            $pdf = new FPDF('P', 'mm', 'A4');
            $pdf->AddPage();
            $pdf->Image($imagePath, $offsetX, $offsetY, $drawW, $drawH);

            $content = $pdf->Output('S');

            if (! is_string($content) || ! str_starts_with($content, '%PDF-')) {
                throw new \RuntimeException('FPDF não produziu um PDF válido.');
            }

            return $content;
        } finally {
            TempFile::cleanup($tempPath);
        }
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveRenderableImagePath(string $absoluteInputPath): array
    {
        $info = @getimagesize($absoluteInputPath);

        if ($info === false) {
            throw new \RuntimeException('Imagem ilegível ou formato não suportado.');
        }

        $imageType = $info[2] ?? 0;

        if ($this->isFpdfNativeType($imageType) && $this->fpdfCanEmbedFile($absoluteInputPath, $imageType)) {
            return [$absoluteInputPath, null];
        }

        if (! extension_loaded('gd')) {
            throw new \RuntimeException('Formato de imagem não suportado sem extensão GD PHP.');
        }

        $image = $this->loadImageWithGd($absoluteInputPath, $imageType);

        if ($image === false) {
            throw new \RuntimeException('Não foi possível ler a imagem.');
        }

        $tempPath = TempFile::path('jpg');

        try {
            if (! imagejpeg($image, $tempPath, 90)) {
                throw new \RuntimeException('Não foi possível preparar a imagem para conversão.');
            }
        } finally {
            imagedestroy($image);
        }

        return [$tempPath, $tempPath];
    }

    private function isFpdfNativeType(int $imageType): bool
    {
        return in_array($imageType, self::FPDF_NATIVE_TYPES, true);
    }

    private function fpdfCanEmbedFile(string $absoluteInputPath, int $imageType): bool
    {
        if ($imageType !== IMAGETYPE_JPEG) {
            return true;
        }

        $handle = @fopen($absoluteInputPath, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 12);
        fclose($handle);

        return is_string($header) && str_starts_with($header, "\xFF\xD8\xFF");
    }

    private function loadImageWithGd(string $absoluteInputPath, int $imageType): \GdImage|false
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absoluteInputPath),
            IMAGETYPE_PNG => @imagecreatefrompng($absoluteInputPath),
            IMAGETYPE_GIF => @imagecreatefromgif($absoluteInputPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absoluteInputPath) : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($absoluteInputPath) : false,
            IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM => @imagecreatefromstring((string) file_get_contents($absoluteInputPath)),
            default => @imagecreatefromstring((string) file_get_contents($absoluteInputPath)),
        };
    }
}
