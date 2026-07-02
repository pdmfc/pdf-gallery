<?php

namespace PDMFC\PdfGallery\Support;

class TempFile
{
    public static function write(string $contents, string $extension = 'bin'): string
    {
        $path = self::path($extension);
        file_put_contents($path, $contents);

        return $path;
    }

    public static function path(string $extension = 'bin'): string
    {
        $extension = ltrim($extension, '.');
        $base = tempnam(sys_get_temp_dir(), 'pdf-gallery-');

        if ($base === false) {
            throw new \RuntimeException('Não foi possível criar ficheiro temporário.');
        }

        $path = $extension !== '' ? $base.'.'.$extension : $base;
        @unlink($base);

        return $path;
    }

    public static function cleanup(?string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
