<?php

use Illuminate\Support\Facades\Route;
use PDMFC\PdfGallery\Support\PdfGallerySession;

Route::get('/pdf-gallery', function () {
    $userId = request()->query('userId', 1);
    PdfGallerySession::primeGalleryUser($userId);

    return inertia('PdfGalleryPage', ['userId' => $userId]);
})->name('pdf-gallery.demo');
