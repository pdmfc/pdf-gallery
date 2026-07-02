<?php

use Illuminate\Support\Facades\Route;
use PDMFC\PdfGallery\Http\Controllers\PdfGalleryController;
use PDMFC\PdfGallery\Http\Middleware\EnsurePdfGalleryUserAccess;

$browserMiddleware = array_values(array_unique(array_merge(
    (array) config('pdf-gallery.routes.browser_middleware', ['web']),
    [EnsurePdfGalleryUserAccess::class],
)));

Route::middleware($browserMiddleware)->group(function () {
    Route::get('/pdf-gallery/csrf-token', static fn () => response()->json([
        'token' => csrf_token(),
    ]))->name('pdf-gallery.csrf-token');

    Route::get('/pdf-gallery/documents', [PdfGalleryController::class, 'listDocuments'])
        ->name('pdf-gallery.documents');
    Route::get('/pdf-gallery/files/{userId}/{filename}', [PdfGalleryController::class, 'showFile'])
        ->where('filename', '.*')
        ->name('pdf-gallery.file');
    Route::get('/pdf-gallery/thumbs/{userId}/{filename}', [PdfGalleryController::class, 'showThumbnail'])
        ->where('filename', '.*')
        ->name('pdf-gallery.thumb');
    Route::get('/pdf-gallery/preview/{userId}/{filename}', [PdfGalleryController::class, 'showPreview'])
        ->where('filename', '.*')
        ->name('pdf-gallery.preview');
    Route::post('/pdf-gallery/upload', [PdfGalleryController::class, 'upload'])
        ->name('pdf-gallery.upload');
    Route::delete('/pdf-gallery/documents', [PdfGalleryController::class, 'delete'])
        ->name('pdf-gallery.delete');
    Route::post('/pdf-gallery/reorder', [PdfGalleryController::class, 'reorder'])
        ->name('pdf-gallery.reorder');
    Route::post('/pdf-gallery/merge', [PdfGalleryController::class, 'merge'])
        ->name('pdf-gallery.merge');
    Route::post('/pdf-gallery/merge/save', [PdfGalleryController::class, 'mergeAndStore'])
        ->name('pdf-gallery.merge-save');
    Route::post('/pdf-gallery/extract', [PdfGalleryController::class, 'extract'])
        ->name('pdf-gallery.extract');
    Route::post('/pdf-gallery/extract/save', [PdfGalleryController::class, 'extractAndStore'])
        ->name('pdf-gallery.extract-save');
    Route::post('/pdf-gallery/qrcode', [PdfGalleryController::class, 'getQrCode'])
        ->name('pdf-gallery.qrcode');
});
