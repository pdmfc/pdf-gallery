<?php

namespace PDMFC\PdfGallery\Support;

class DocumentMimeTypes
{
    /** @var list<string> */
    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/tiff',
        'image/bmp',
    ];

    /** @var list<string> */
    private const WORD_MIMES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'application/rtf',
        'text/rtf',
    ];

    /** @var list<string> */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff', 'bmp'];

    /** @var list<string> */
    private const WORD_EXTENSIONS = ['doc', 'docx', 'odt', 'rtf'];

    public static function isPdf(string $mimeType, string $filename, ?string $binary = null): bool
    {
        if ($binary !== null && str_starts_with($binary, '%PDF-')) {
            return true;
        }

        $extension = self::extension($filename);

        if ($extension === 'pdf') {
            return $binary === null || str_starts_with($binary, '%PDF-');
        }

        $mime = strtolower(trim($mimeType));

        return in_array($mime, [
            'application/pdf',
            'application/x-pdf',
            'application/acrobat',
            'applications/vnd.pdf',
            'text/pdf',
        ], true) && ($binary === null || str_starts_with($binary, '%PDF-'));
    }

    public static function isImage(string $mimeType, string $filename): bool
    {
        $mime = strtolower(trim($mimeType));

        if (in_array($mime, self::IMAGE_MIMES, true)) {
            return true;
        }

        return in_array(self::extension($filename), self::IMAGE_EXTENSIONS, true);
    }

    public static function isWord(string $mimeType, string $filename): bool
    {
        $mime = strtolower(trim($mimeType));

        if (in_array($mime, self::WORD_MIMES, true)) {
            return true;
        }

        return in_array(self::extension($filename), self::WORD_EXTENSIONS, true);
    }

    public static function isConvertible(string $mimeType, string $filename): bool
    {
        return self::isImage($mimeType, $filename) || self::isWord($mimeType, $filename);
    }

    public static function category(string $mimeType, string $filename): ?string
    {
        if (self::isImage($mimeType, $filename)) {
            return 'image';
        }

        if (self::isWord($mimeType, $filename)) {
            return 'word';
        }

        return null;
    }

    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
