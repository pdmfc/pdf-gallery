<?php

namespace PDMFC\PdfGallery\Contracts;

interface DocumentToPdfConverter
{
    public function name(): string;

    public function supports(string $mimeType, string $filename): bool;

    public function isAvailable(): bool;

    public function convert(string $absoluteInputPath, string $mimeType, string $filename): string;
}
