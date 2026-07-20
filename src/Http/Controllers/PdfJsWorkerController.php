<?php

namespace PDMFC\PdfGallery\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PdfJsWorkerController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $path = dirname(__DIR__, 2).'/Resources/assets/vendor/pdfjs/pdf.worker.min.js';

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
