<?php

namespace PDMFC\PdfGallery\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDMFC\PdfGallery\Http\Controllers\Controller as BaseController;
use PDMFC\PdfGallery\Services\PdfGalleryService;
use PDMFC\PdfGallery\Services\PdfThumbnailService;
use PDMFC\PdfGallery\Services\QrCodeService;
use PDMFC\PdfGallery\Support\CallbackRoute;
use PDMFC\PdfGallery\Support\GalleryDocument;
use PDMFC\PdfGallery\Support\PdfStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PdfGalleryController extends BaseController
{
    public function __construct(
        private readonly PdfGalleryService $galleryService,
        private readonly PdfStorage $storage,
        private readonly PdfThumbnailService $thumbnailService,
        private readonly QrCodeService $qrCodeService,
    ) {}

    public function listDocuments(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required',
        ]);

        $result = $this->galleryService->listDocuments($request->input('user_id'));

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 500);
        }

        return response()->json([
            'success' => true,
            'documents' => $result['documents'] ?? [],
        ]);
    }

    public function showFile(string $userId, string $filename): BinaryFileResponse
    {
        try {
            $userId = $this->storage->sanitizeUserId($userId);
            $filename = $this->storage->safeFilename($filename);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        if (! $this->storage->fileExists($userId, $filename)) {
            abort(404);
        }

        $downloadName = $this->galleryService->resolveDownloadFilename($userId, $filename);

        return response()->file(
            $this->storage->storagePath($userId, $filename),
            [
                'Content-Type' => GalleryDocument::guessMimeType($filename),
                'Content-Disposition' => (GalleryDocument::isPdf($filename) || GalleryDocument::isImage($filename))
                    ? 'inline; filename="'.addslashes($downloadName).'"'
                    : 'attachment; filename="'.addslashes($downloadName).'"',
            ]
        );
    }

    public function showThumbnail(string $userId, string $filename): BinaryFileResponse
    {
        try {
            $userId = $this->storage->sanitizeUserId($userId);
            $filename = $this->storage->safeFilename($filename);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        if (! $this->storage->fileExists($userId, $filename)) {
            abort(404);
        }

        $absolute = $this->thumbnailService->ensureThumbnail($userId, $filename);

        if ($absolute === null || ! is_file($absolute)) {
            abort(404);
        }

        return response()->file($absolute, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function showPreview(string $userId, string $filename): Response
    {
        try {
            $userId = $this->storage->sanitizeUserId($userId);
            $filename = $this->storage->safeFilename($filename);
            $binary = $this->galleryService->previewAsPdfBinary($userId, $filename);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $label = $this->galleryService->resolveDownloadFilename($userId, $filename, asPdf: true);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($label).'"',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required',
            'file' => 'required|file|max:'.((int) config('pdf-gallery.gallery.max_upload_mb', 25) * 1024),
        ]);

        $result = $this->galleryService->storeUpload(
            $request->input('user_id'),
            $request->file('file')
        );

        if (isset($result['error'])) {
            $status = str_contains(strtolower($result['error']), 'limite') ? 422 : 500;

            return response()->json(['error' => $result['error']], $status);
        }

        return response()->json($result);
    }

    public function delete(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required',
            'filename' => 'nullable|string',
            'filenames' => 'nullable|array',
            'filenames.*' => 'string',
        ]);

        $filenames = $request->input('filenames');

        if (! is_array($filenames) || $filenames === []) {
            $single = $request->input('filename');

            if (! is_string($single) || $single === '') {
                return response()->json(['error' => 'Indique o PDF a eliminar.'], 422);
            }

            $filenames = [$single];
        }

        $result = $this->galleryService->deleteDocuments($request->input('user_id'), $filenames);

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 500);
        }

        return response()->json($result);
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required',
            'filenames' => 'required|array|min:1',
            'filenames.*' => 'string',
        ]);

        $result = $this->galleryService->reorderDocuments(
            $request->input('user_id'),
            $request->input('filenames')
        );

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json($result);
    }

    public function merge(Request $request): Response
    {
        $request->validate([
            'user_id' => 'required',
            'filenames' => 'required|array|min:2',
            'filenames.*' => 'string',
            'download' => 'sometimes|boolean',
        ]);

        try {
            $binary = $this->galleryService->mergeDocuments(
                $request->input('user_id'),
                $request->input('filenames')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $disposition = $request->boolean('download')
            ? 'attachment; filename="documentos-unidos.pdf"'
            : 'inline; filename="documentos-unidos.pdf"';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function mergeAndStore(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required',
            'filenames' => 'required|array|min:2',
            'filenames.*' => 'string',
        ]);

        $result = $this->galleryService->mergeAndStore(
            $request->input('user_id'),
            $request->input('filenames')
        );

        if (isset($result['error'])) {
            $status = str_contains(strtolower($result['error']), 'limite') ? 422 : 500;

            return response()->json(['error' => $result['error']], $status);
        }

        return response()->json($result);
    }

    public function extract(Request $request): Response
    {
        $request->validate([
            'user_id' => 'required',
            'filename' => 'required|string',
            'page_from' => 'nullable|integer|min:1',
            'page_to' => 'nullable|integer|min:1',
            'pages' => 'nullable|array|min:1',
            'pages.*' => 'integer|min:1',
            'download' => 'sometimes|boolean',
        ]);

        $pages = $this->resolveExtractPages($request);

        if ($pages === []) {
            return response()->json(['error' => 'Indique as páginas a extrair (page_from/page_to ou pages).'], 422);
        }

        try {
            $binary = $this->galleryService->extractPages(
                $request->input('user_id'),
                $request->input('filename'),
                $pages
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $label = count($pages) === 1
            ? 'pagina-'.$pages[0]
            : 'paginas-'.$pages[0].'-'.$pages[count($pages) - 1];
        $disposition = $request->boolean('download')
            ? 'attachment; filename="'.$label.'.pdf"'
            : 'inline; filename="'.$label.'.pdf"';

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function extractAndStore(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required',
            'filename' => 'required|string',
            'page_from' => 'nullable|integer|min:1',
            'page_to' => 'nullable|integer|min:1',
            'pages' => 'nullable|array|min:1',
            'pages.*' => 'integer|min:1',
        ]);

        $pages = $this->resolveExtractPages($request);

        if ($pages === []) {
            return response()->json(['error' => 'Indique as páginas a extrair (page_from/page_to ou pages).'], 422);
        }

        $result = $this->galleryService->extractAndStore(
            $request->input('user_id'),
            $request->input('filename'),
            $pages
        );

        if (isset($result['error'])) {
            $status = str_contains(strtolower($result['error']), 'limite') ? 422 : 500;

            return response()->json(['error' => $result['error']], $status);
        }

        return response()->json($result);
    }

    public function getQrCode(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required',
        ]);

        $result = $this->qrCodeService->fetchQrCode($request->input('user_id'));

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], $result['status'] ?? 422);
        }

        if (isset($result['svg'])) {
            return response()->json(['svg' => $result['svg']]);
        }

        return response()->json(['qr_image' => $result['qr_image']]);
    }

    public function callbackFiles(Request $request): JsonResponse
    {
        try {
            $scopeId = CallbackRoute::scopeKeyFromRequest($request);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $result = $this->galleryService->storeCallbackFiles($scopeId, $request->all());

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 500);
        }

        return response()->json($result);
    }

    /**
     * @return list<int>
     */
    private function resolveExtractPages(Request $request): array
    {
        $pages = $request->input('pages');

        if (is_array($pages) && $pages !== []) {
            return array_values(array_map('intval', $pages));
        }

        $from = $request->input('page_from');
        $to = $request->input('page_to');

        if ($from === null && $to === null) {
            return [];
        }

        $from = (int) ($from ?? $to);
        $to = (int) ($to ?? $from);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return range($from, $to);
    }
}
