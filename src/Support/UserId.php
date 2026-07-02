<?php

namespace PDMFC\PdfGallery\Support;

class UserId
{
    public static function sanitize(string|int $userId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $userId);

        if ($safe === '') {
            throw new \InvalidArgumentException('ID de utilizador inválido.');
        }

        return $safe;
    }
}
