<?php

namespace PDMFC\PdfGallery\Support;

class GalleryDocument
{
    /** @var list<string> */
    private const PDF_EXTENSIONS = ['pdf'];

    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public static function isPdf(string $filename): bool
    {
        return self::extension($filename) === 'pdf';
    }

    public static function isOffice(string $filename): bool
    {
        return DocumentMimeTypes::isWord(
            self::guessMimeType($filename),
            $filename
        );
    }

    public static function isImage(string $filename): bool
    {
        return DocumentMimeTypes::isImage(
            self::guessMimeType($filename),
            $filename
        );
    }

    public static function kind(string $filename): string
    {
        if (self::isPdf($filename)) {
            return 'pdf';
        }

        if (self::isOffice($filename)) {
            return 'office';
        }

        if (self::isImage($filename)) {
            return 'image';
        }

        return 'other';
    }

    public static function isPreviewable(string $filename): bool
    {
        if (self::isPdf($filename)) {
            return true;
        }

        return self::conversionEnabled() && self::isConvertible($filename);
    }

    public static function isConvertible(string $filename): bool
    {
        return self::isOffice($filename) || self::isImage($filename);
    }

    public static function isListable(string $filename): bool
    {
        if (str_starts_with(basename($filename), '.')) {
            return false;
        }

        if (self::isPdfThumbnail($filename)) {
            return false;
        }

        if (self::isPdf($filename)) {
            return true;
        }

        if (! self::conversionEnabled()) {
            return false;
        }

        return self::isOffice($filename) || self::isImage($filename);
    }

    public static function isPdfThumbnail(string $filename): bool
    {
        $lower = strtolower(basename($filename));

        return str_ends_with($lower, '_thumb.jpg') || str_ends_with($lower, '_thumb.jpeg');
    }

    public static function conversionEnabled(): bool
    {
        return (bool) config('pdf-gallery.convert.enabled', false);
    }

    public static function deferConversion(): bool
    {
        return self::conversionEnabled()
            && (bool) config('pdf-gallery.convert.defer_conversion', true);
    }

    public static function guessMimeType(string $filename): string
    {
        return match (self::extension($filename)) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'rtf' => 'application/rtf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }

    public static function sanitizeStoredFilename(string $filename): string
    {
        $filename = basename($filename);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $extension = self::extension($filename);
        $stem = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $stem);
        $stem = trim((string) $stem, '_');

        if ($stem === '') {
            $stem = 'documento';
        }

        return $extension !== '' ? $stem.'.'.$extension : $stem;
    }
}
