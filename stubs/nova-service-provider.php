<?php

/**
 * Exemplo para Laravel Nova — adicione ao NovaServiceProvider::boot().
 *
 * Os limites ficam disponíveis no frontend via Nova.config('pdfGallery').
 */
use Laravel\Nova\Nova;

Nova::provideToScript([
    'pdfGallery' => [
        'maxFiles' => (int) config('pdf-gallery.gallery.max_files', 100),
        'maxUploadMb' => (int) config('pdf-gallery.gallery.max_upload_mb', 25),
        'mergeMaxFiles' => (int) config('pdf-gallery.merge.max_files', 50),
        'convertEnabled' => (bool) config('pdf-gallery.convert.enabled', false),
        'title' => (string) config('pdf-gallery.ui.title', 'Galeria de PDF'),
        'documentSingular' => (string) config('pdf-gallery.ui.document_singular', 'documento'),
        'documentPlural' => (string) config('pdf-gallery.ui.document_plural', 'documentos'),
    ],
]);
