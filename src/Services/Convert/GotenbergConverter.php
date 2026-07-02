<?php

namespace PDMFC\PdfGallery\Services\Convert;

use Illuminate\Support\Facades\Http;
use PDMFC\PdfGallery\Contracts\DocumentToPdfConverter;
use PDMFC\PdfGallery\Support\DocumentMimeTypes;

class GotenbergConverter implements DocumentToPdfConverter
{
    public function name(): string
    {
        return 'gotenberg';
    }

    public function supports(string $mimeType, string $filename): bool
    {
        return DocumentMimeTypes::isWord($mimeType, $filename);
    }

    public function isAvailable(): bool
    {
        $url = $this->baseUrl();

        return $url !== null;
    }

    public function convert(string $absoluteInputPath, string $mimeType, string $filename): string
    {
        $baseUrl = $this->baseUrl();

        if ($baseUrl === null) {
            throw new \RuntimeException('Gotenberg não está configurado (PDF_GALLERY_GOTENBERG_URL).');
        }

        $route = '/forms/libreoffice/convert';

        $response = Http::timeout((int) config('pdf-gallery.convert.timeout_seconds', 120))
            ->attach('files', fopen($absoluteInputPath, 'rb'), basename($filename))
            ->post(rtrim($baseUrl, '/').$route);

        if (! $response->successful()) {
            throw new \RuntimeException('Gotenberg falhou: '.$response->body());
        }

        $content = $response->body();

        if ($content === '' || ! str_starts_with($content, '%PDF-')) {
            throw new \RuntimeException('Gotenberg não devolveu um PDF válido.');
        }

        return $content;
    }

    private function baseUrl(): ?string
    {
        $url = config('pdf-gallery.convert.gotenberg_url');

        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        return $url !== '' ? $url : null;
    }
}
