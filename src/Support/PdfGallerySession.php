<?php

namespace PDMFC\PdfGallery\Support;

class PdfGallerySession
{
    public static function primeGalleryUser(string|int $userId): void
    {
        session([
            (string) config(
                'pdf-gallery.authorization.session_user_id_key',
                'pdf_gallery_user_id'
            ) => (string) $userId,
        ]);
    }
}
