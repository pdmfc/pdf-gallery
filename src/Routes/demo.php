<?php

use Illuminate\Support\Facades\Route;
use PDMFC\PdfGallery\Support\PdfGallerySession;

/*
|--------------------------------------------------------------------------
| Rotas de demonstração (opcional)
|--------------------------------------------------------------------------
|
| Activadas com PDF_GALLERY_DEMO_ROUTES=true ou demo_routes na config.
| Em produção, defina as páginas Inertia no projeto host (auth, userId, etc.).
|
*/

Route::get('/pdf-gallery', function () {
    $userId = request()->query('userId', 1);
    PdfGallerySession::primeGalleryUser($userId);

    return inertia('PdfGalleryPage', ['userId' => $userId]);
})->name('pdf-gallery.demo');
