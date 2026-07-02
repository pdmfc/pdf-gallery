<?php

namespace PDMFC\PdfGallery\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PDMFC\PdfGallery\Events\PdfsUploadedFromMobile;
use PDMFC\PdfGallery\Support\DocumentMimeTypes;
use PDMFC\PdfGallery\Support\GalleryDocument;
use PDMFC\PdfGallery\Support\PdfStorage;
use PDMFC\PdfGallery\Support\TempFile;

class PdfGalleryService
{
    public function __construct(
        private readonly PdfStorage $storage,
        private readonly PdfMergeService $mergeService,
        private readonly PdfExtractService $extractService,
        private readonly PdfThumbnailService $thumbnailService,
        private readonly PdfConvertService $convertService,
    ) {}

    /**
     * @return array{documents?: list<array{filename: string, url: string, path: string, timestamp: int|float, page_count: int|null, size_bytes: int}>, error?: string}
     */
    public function listDocuments(string|int $userId): array
    {
        try {
            $this->storage->ensureDirectory($userId);
            $dir = $this->storage->directory($userId);
            $disk = Storage::disk($this->storage->disk());
            $files = $disk->files($dir);
            $documents = [];

            foreach ($files as $file) {
                $filename = basename($file);

                if (! GalleryDocument::isListable($filename)) {
                    continue;
                }

                $fullPath = $this->storage->storagePath($userId, $filename);
                $documents[] = $this->formatDocument($userId, $filename, $file, $fullPath);
            }

            $documents = $this->sortByGalleryOrder($userId, $documents);
            $documents = $this->enrichDocuments($userId, $documents);
            $documents = $this->applyProtectedDocumentFlags($documents);
            $documents = $this->filterDocuments($userId, $documents);

            return ['documents' => $documents];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success?: true, document?: array<string, mixed>, error?: string}
     */
    public function storeUpload(string|int $userId, UploadedFile $file): array
    {
        try {
            if ($room = $this->ensureGalleryHasRoom($userId)) {
                return $room;
            }

            if (! $this->convertService->acceptsUpload($file)) {
                return ['error' => 'Tipo de ficheiro não suportado. Envie PDF, imagem ou documento Word.'];
            }

            $maxMb = (int) config('pdf-gallery.gallery.max_upload_mb', 25);
            $maxBytes = $maxMb * 1024 * 1024;

            if ($file->getSize() > $maxBytes) {
                return ['error' => "O ficheiro excede o limite de {$maxMb} MB."];
            }

            $originalName = (string) $file->getClientOriginalName();
            $mime = (string) $file->getMimeType();
            $binary = file_get_contents($file->getPathname());

            if (! is_string($binary) || $binary === '') {
                return ['error' => 'Ficheiro vazio ou ilegível.'];
            }

            if (DocumentMimeTypes::isPdf($mime, $originalName, $binary)) {
                return $this->persistPdfBinary($userId, $binary, $originalName);
            }

            if (
                GalleryDocument::deferConversion()
                && (DocumentMimeTypes::isWord($mime, $originalName) || DocumentMimeTypes::isImage($mime, $originalName))
            ) {
                return $this->persistOriginalBinary($userId, $binary, $originalName);
            }

            $pdfBinary = $this->convertService->toPdfBinary($binary, $mime, $originalName);

            if (strlen($pdfBinary) > $maxBytes) {
                return ['error' => "O PDF convertido excede o limite de {$maxMb} MB."];
            }

            return $this->persistPdfBinary($userId, $pdfBinary, $originalName);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function galleryRemainingSlots(string|int $userId): ?int
    {
        $max = (int) config('pdf-gallery.gallery.max_files', 100);

        if ($max <= 0) {
            return null;
        }

        $count = count($this->listDocuments($userId)['documents'] ?? []);

        return max(0, $max - $count);
    }

    public function storeCallbackFiles(string|int $userId, array $payload): array
    {
        try {
            $this->storage->ensureDirectory($userId);
            $files = $this->normalizeCallbackPayload($payload);
            $saved = 0;
            $newFilenames = [];
            $maxMb = (int) config('pdf-gallery.gallery.max_upload_mb', 25);
            $maxBytes = $maxMb * 1024 * 1024;

            foreach ($files as $file) {
                if (! is_array($file) || empty($file['name']) || empty($file['content'])) {
                    continue;
                }

                if ($room = $this->ensureGalleryHasRoom($userId)) {
                    if ($saved === 0) {
                        return $room;
                    }

                    break;
                }

                $binary = $this->decodeCallbackBinary((string) $file['content']);

                if ($binary === null) {
                    continue;
                }

                if (strlen($binary) > $maxBytes) {
                    if ($saved === 0) {
                        return ['error' => "O ficheiro excede o limite de {$maxMb} MB."];
                    }

                    break;
                }

                $originalName = (string) $file['name'];
                $mimeType = is_string($file['mime'] ?? null)
                    ? (string) $file['mime']
                    : (string) ($file['type'] ?? 'application/octet-stream');

                try {
                    if ($this->hasPdfHeaderFromBinary($binary)) {
                        $result = $this->persistPdfBinary($userId, $binary, $originalName);

                        if (isset($result['error'])) {
                            if ($saved === 0) {
                                return $result;
                            }

                            break;
                        }

                        if (isset($result['document']['filename'])) {
                            $newFilenames[] = $result['document']['filename'];
                            $saved++;
                        }

                        continue;
                    }

                    if (
                        GalleryDocument::deferConversion()
                        && (
                            DocumentMimeTypes::isWord($mimeType, $originalName)
                            || DocumentMimeTypes::isImage($mimeType, $originalName)
                        )
                    ) {
                        $result = $this->persistOriginalBinary($userId, $binary, $originalName);

                        if (isset($result['error'])) {
                            if ($saved === 0) {
                                return $result;
                            }

                            break;
                        }

                        if (isset($result['document']['filename'])) {
                            $newFilenames[] = $result['document']['filename'];
                            $saved++;
                        }

                        continue;
                    }

                    if (! GalleryDocument::conversionEnabled()) {
                        continue;
                    }

                    $pdfBinary = $this->convertService->toPdfBinary($binary, $mimeType, $originalName);
                    $result = $this->persistPdfBinary($userId, $pdfBinary, $originalName);

                    if (isset($result['error'])) {
                        if ($saved === 0) {
                            return $result;
                        }

                        break;
                    }

                    if (isset($result['document']['filename'])) {
                        $newFilenames[] = $result['document']['filename'];
                        $saved++;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }

            if ($saved > 0) {
                $this->broadcastDocumentsUploaded($userId, $saved, $newFilenames);
            }

            return ['status' => true, 'saved' => $saved];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $filenames
     * @return array{success?: true, deleted_count?: int, error?: string}
     */
    public function deleteDocuments(string|int $userId, array $filenames): array
    {
        try {
            $requested = array_values(array_unique(array_filter(array_map(
                static fn ($name) => basename((string) $name),
                $filenames,
            ))));

            if ($requested === []) {
                return ['error' => 'Indique o ficheiro a eliminar.'];
            }

            $protected = $this->protectedBasenames();
            $blocked = array_values(array_filter(
                $requested,
                static fn (string $name) => in_array($name, $protected, true),
            ));
            $deletable = array_values(array_filter(
                $requested,
                static fn (string $name) => ! in_array($name, $protected, true),
            ));

            if ($deletable === [] && $blocked !== []) {
                return ['error' => 'Não é possível eliminar este documento.'];
            }

            $handler = config('pdf-gallery.documents.delete_handler');

            if (is_callable($handler)) {
                $handled = $handler($userId, $requested);

                if (is_array($handled)) {
                    return $handled;
                }
            }

            $deleted = 0;

            foreach ($deletable as $filename) {
                $safe = $this->storage->safeFilename((string) $filename);
                $path = $this->storage->filePath($userId, $safe);

                if (Storage::disk($this->storage->disk())->exists($path)) {
                    Storage::disk($this->storage->disk())->delete($path);
                    $deleted++;
                }

                $this->thumbnailService->deleteThumbnail($userId, $safe);
                $this->storage->removeFromGalleryOrder($userId, $safe);
            }

            return ['success' => true, 'deleted_count' => $deleted];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $filenames
     * @return array{success?: true, error?: string}
     */
    public function reorderDocuments(string|int $userId, array $filenames): array
    {
        try {
            $existing = array_column($this->listDocuments($userId)['documents'] ?? [], 'filename');
            $requested = array_values(array_unique(array_map(
                fn ($name) => $this->storage->safeFilename((string) $name),
                $filenames
            )));

            if (count($requested) !== count($existing)) {
                return ['error' => 'A lista de ordenação deve incluir todos os PDFs da galeria.'];
            }

            foreach ($requested as $filename) {
                if (! in_array($filename, $existing, true)) {
                    return ['error' => "PDF não encontrado: {$filename}"];
                }
            }

            $this->storage->writeGalleryOrder($userId, $requested);

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $filenames
     */
    public function mergeDocuments(string|int $userId, array $filenames): string
    {
        $filenames = array_values(array_unique(array_map(
            fn ($name) => $this->storage->safeFilename((string) $name),
            $filenames
        )));

        if ($filenames === []) {
            throw new \InvalidArgumentException('Seleccione pelo menos um documento.');
        }

        return $this->assembleDocuments($userId, $filenames);
    }

    public function resolveDownloadFilename(string|int $userId, string $filename, bool $asPdf = false): string
    {
        $filename = $this->storage->safeFilename($filename);
        $enriched = $this->enrichDocuments($userId, [['filename' => $filename]]);
        $name = (string) (($enriched[0]['label'] ?? '') ?: ($enriched[0]['filename'] ?? $filename));

        if ($asPdf) {
            if (preg_match('/\.(docx?|odt|rtf)$/i', $name)) {
                $name = (string) preg_replace('/\.(docx?|odt|rtf)$/i', '.pdf', $name);
            } elseif (! str_ends_with(strtolower($name), '.pdf')) {
                $name = pathinfo($name, PATHINFO_FILENAME).'.pdf';
            }
        }

        $name = GalleryDocument::sanitizeStoredFilename($name);

        return $name !== '' ? $name : $filename;
    }

    public function previewAsPdfBinary(string|int $userId, string $filename): string
    {
        $filename = $this->storage->safeFilename($filename);

        if (! $this->storage->fileExists($userId, $filename)) {
            throw new \InvalidArgumentException('Documento não encontrado.');
        }

        $absolute = $this->storage->storagePath($userId, $filename);

        if (GalleryDocument::isPdf($filename)) {
            $content = file_get_contents($absolute);

            if (! is_string($content) || ! str_starts_with($content, '%PDF-')) {
                throw new \RuntimeException('O ficheiro não é um PDF válido.');
            }

            return $content;
        }

        if (! GalleryDocument::conversionEnabled() || ! GalleryDocument::isConvertible($filename)) {
            throw new \InvalidArgumentException('Pré-visualização não disponível para este documento.');
        }

        $binary = file_get_contents($absolute);

        if (! is_string($binary) || $binary === '') {
            throw new \RuntimeException('Não foi possível ler o documento.');
        }

        $mimeType = GalleryDocument::guessMimeType($filename);

        return $this->convertService->toPdfBinary($binary, $mimeType, $filename);
    }

    /**
     * @param  list<string>  $filenames
     */
    public function assembleDocuments(string|int $userId, array $filenames): string
    {
        $filenames = $this->sortFilenamesByGalleryOrder($userId, $filenames);

        $maxFiles = (int) config('pdf-gallery.merge.max_files', 50);

        if (count($filenames) > $maxFiles) {
            throw new \InvalidArgumentException("Pode juntar no máximo {$maxFiles} documentos de cada vez.");
        }

        $resolved = $this->resolveAssemblyPdfPaths($userId, $filenames);
        $paths = $resolved['paths'];
        $temps = $resolved['temps'];
        $totalBytes = $resolved['total_bytes'];
        $maxTotalMb = (int) config('pdf-gallery.merge.max_total_mb', 200);
        $maxTotalBytes = $maxTotalMb * 1024 * 1024;

        if ($totalBytes > $maxTotalBytes) {
            $this->cleanupAssemblyTempPaths($temps);

            throw new \InvalidArgumentException("O total dos documentos excede {$maxTotalMb} MB.");
        }

        try {
            if (count($paths) === 1) {
                $content = file_get_contents($paths[0]);

                if (! is_string($content) || ! str_starts_with($content, '%PDF-')) {
                    throw new \RuntimeException('O documento preparado não é um PDF válido.');
                }

                return $content;
            }

            return $this->mergeService->merge($paths);
        } finally {
            $this->cleanupAssemblyTempPaths($temps);
        }
    }

    /**
     * @param  list<string>  $filenames
     * @return array{paths: list<string>, temps: list<string>, total_bytes: int}
     */
    private function resolveAssemblyPdfPaths(string|int $userId, array $filenames): array
    {
        $paths = [];
        $temps = [];
        $totalBytes = 0;

        foreach ($filenames as $filename) {
            if (! $this->storage->fileExists($userId, $filename)) {
                throw new \InvalidArgumentException("Documento não encontrado: {$filename}");
            }

            $absolute = $this->storage->storagePath($userId, $filename);
            $size = is_file($absolute) ? (int) filesize($absolute) : 0;
            $totalBytes += $size;

            if (GalleryDocument::isPdf($filename)) {
                $paths[] = $absolute;

                continue;
            }

            if (! GalleryDocument::conversionEnabled()) {
                throw new \InvalidArgumentException("Documento não suportado no merge: {$filename}");
            }

            $mimeType = GalleryDocument::guessMimeType($filename);
            $binary = file_get_contents($absolute);

            if (! is_string($binary) || $binary === '') {
                throw new \RuntimeException("Não foi possível ler o documento: {$filename}");
            }

            $pdfBinary = $this->convertService->toPdfBinary($binary, $mimeType, $filename);
            $tempPath = TempFile::write($pdfBinary, 'pdf');
            $temps[] = $tempPath;
            $paths[] = $tempPath;
            $totalBytes += strlen($pdfBinary);
        }

        return [
            'paths' => $paths,
            'temps' => $temps,
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * @param  list<string>  $tempPaths
     */
    private function cleanupAssemblyTempPaths(array $tempPaths): void
    {
        TempFile::cleanup(...$tempPaths);
    }

    /**
     * @param  list<int>  $pages
     */
    public function extractPages(string|int $userId, string $filename, array $pages): string
    {
        $filename = $this->storage->safeFilename($filename);
        $pages = $this->extractService->normalizePages($pages);

        if ($pages === []) {
            throw new \InvalidArgumentException('Indique pelo menos uma página a extrair.');
        }

        if (! $this->storage->fileExists($userId, $filename)) {
            throw new \InvalidArgumentException("Documento não encontrado: {$filename}");
        }

        if (! GalleryDocument::isPdf($filename)) {
            throw new \InvalidArgumentException('A extração de páginas só está disponível para PDF.');
        }

        $absolute = $this->storage->storagePath($userId, $filename);
        $pageCount = $this->estimatePageCount($absolute);

        if ($pageCount !== null) {
            foreach ($pages as $page) {
                if ($page > $pageCount) {
                    throw new \InvalidArgumentException("A página {$page} não existe (o PDF tem {$pageCount} páginas).");
                }
            }
        }

        return $this->extractService->extract($absolute, $pages);
    }

    /**
     * @param  list<int>  $pages
     * @return array{success?: true, document?: array<string, mixed>, extracted_from?: string, pages?: list<int>, error?: string}
     */
    public function extractAndStore(string|int $userId, string $filename, array $pages): array
    {
        try {
            if ($room = $this->ensureGalleryHasRoom($userId)) {
                return $room;
            }

            $safeFilename = $this->storage->safeFilename($filename);
            $normalizedPages = $this->extractService->normalizePages($pages);
            $binary = $this->extractPages($userId, $safeFilename, $normalizedPages);

            return $this->storeExtractedBinary($userId, $binary, $safeFilename, $normalizedPages);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $filenames
     * @return array{success?: true, document?: array<string, mixed>, merged_from?: list<string>, error?: string}
     */
    public function mergeAndStore(string|int $userId, array $filenames): array
    {
        try {
            if ($room = $this->ensureGalleryHasRoom($userId)) {
                return $room;
            }

            $orderedFilenames = $this->sortFilenamesByGalleryOrder(
                $userId,
                array_values(array_unique(array_map(
                    fn ($name) => $this->storage->safeFilename((string) $name),
                    $filenames
                )))
            );

            $binary = $this->mergeDocuments($userId, $orderedFilenames);

            return $this->storeMergedBinary($userId, $binary, $orderedFilenames);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param  list<string>  $sourceFilenames
     * @return array{success: true, document: array<string, mixed>, merged_from: list<string>}|array{error: string}
     */
    private function storeMergedBinary(string|int $userId, string $binary, array $sourceFilenames): array
    {
        if (! $this->hasPdfHeaderFromBinary($binary)) {
            return ['error' => 'O PDF unido não é válido.'];
        }

        $maxMb = (int) config('pdf-gallery.gallery.max_upload_mb', 25);
        $maxBytes = $maxMb * 1024 * 1024;
        $size = strlen($binary);

        if ($size > $maxBytes) {
            return ['error' => "O PDF unido excede o limite de {$maxMb} MB."];
        }

        $this->storage->ensureDirectory($userId);
        $filename = $this->storage->ensurePdfExtension(
            'pdf_merged_'.time().'_'.bin2hex(random_bytes(4)).'.pdf'
        );
        $path = $this->storage->filePath($userId, $filename);

        if (! Storage::disk($this->storage->disk())->put($path, $binary)) {
            return ['error' => 'Falha ao gravar o PDF unido.'];
        }

        $this->storage->appendToGalleryOrder($userId, $filename);
        $fullPath = $this->storage->storagePath($userId, $filename);
        $this->thumbnailService->ensureThumbnail($userId, $filename);

        $document = $this->formatDocument($userId, $filename, $path, $fullPath);
        $this->notifyDocumentPersisted($userId, $document, [
            'type' => 'merge',
            'merged_from' => $sourceFilenames,
        ]);

        return [
            'success' => true,
            'document' => $document,
            'merged_from' => $sourceFilenames,
        ];
    }

    /**
     * @param  list<int>  $pages
     * @return array{success: true, document: array<string, mixed>, extracted_from: string, pages: list<int>}|array{error: string}
     */
    private function storeExtractedBinary(string|int $userId, string $binary, string $sourceFilename, array $pages): array
    {
        if (! $this->hasPdfHeaderFromBinary($binary)) {
            return ['error' => 'O PDF extraído não é válido.'];
        }

        $maxMb = (int) config('pdf-gallery.gallery.max_upload_mb', 25);
        $maxBytes = $maxMb * 1024 * 1024;
        $size = strlen($binary);

        if ($size > $maxBytes) {
            return ['error' => "O PDF extraído excede o limite de {$maxMb} MB."];
        }

        $this->storage->ensureDirectory($userId);
        $filename = $this->storage->ensurePdfExtension(
            'pdf_extract_'.$this->formatPageRangeLabel($pages).'_'.time().'_'.bin2hex(random_bytes(4)).'.pdf'
        );
        $path = $this->storage->filePath($userId, $filename);

        if (! Storage::disk($this->storage->disk())->put($path, $binary)) {
            return ['error' => 'Falha ao gravar o PDF extraído.'];
        }

        $this->storage->appendToGalleryOrder($userId, $filename);
        $fullPath = $this->storage->storagePath($userId, $filename);
        $this->thumbnailService->ensureThumbnail($userId, $filename);

        $document = $this->formatDocument($userId, $filename, $path, $fullPath);
        $this->notifyDocumentPersisted($userId, $document, [
            'type' => 'extract',
            'extracted_from' => $sourceFilename,
            'pages' => $pages,
        ]);

        return [
            'success' => true,
            'document' => $document,
            'extracted_from' => $sourceFilename,
            'pages' => $pages,
        ];
    }

    /**
     * @param  list<int>  $pages
     */
    private function formatPageRangeLabel(array $pages): string
    {
        if ($pages === []) {
            return 'p0';
        }

        if (count($pages) === 1) {
            return 'p'.$pages[0];
        }

        return 'p'.$pages[0].'-'.$pages[count($pages) - 1];
    }

    private function hasPdfHeaderFromBinary(string $binary): bool
    {
        return str_starts_with($binary, '%PDF-');
    }

    /**
     * @return array{error: string}|null
     */
    private function ensureGalleryHasRoom(string|int $userId): ?array
    {
        $max = (int) config('pdf-gallery.gallery.max_files', 100);

        if ($max <= 0) {
            return null;
        }

        $count = count($this->listDocuments($userId)['documents'] ?? []);

        if ($count >= $max) {
            return ['error' => "Limite da galeria atingido ({$max} documentos)."];
        }

        return null;
    }

    private function formatDocument(string|int $userId, string $filename, string $path, string $fullPath): array
    {
        $kind = GalleryDocument::kind($filename);
        $previewable = GalleryDocument::isPreviewable($filename);
        $thumbUrl = null;
        $pageCount = null;
        $url = null;

        if ($kind === 'pdf') {
            $thumbUrl = $this->thumbnailService->ensureThumbnail($userId, $filename)
                ? $this->storage->thumbnailUrl($userId, $filename)
                : null;
            $pageCount = $this->estimatePageCount($fullPath);
            $url = $this->storage->pdfUrl($userId, $filename);
        } elseif ($kind === 'image') {
            $thumbUrl = $this->storage->pdfUrl($userId, $filename);
        }

        if ($previewable && $url === null) {
            $url = $this->storage->previewUrl($userId, $filename);
        }

        return [
            'filename' => $filename,
            'kind' => $kind,
            'previewable' => $previewable,
            'url' => $url,
            'file_url' => $this->storage->pdfUrl($userId, $filename),
            'thumb_url' => $thumbUrl,
            'path' => $path,
            'timestamp' => is_file($fullPath) ? filemtime($fullPath) : time(),
            'page_count' => $pageCount,
            'size_bytes' => is_file($fullPath) ? (int) filesize($fullPath) : 0,
        ];
    }

    /**
     * @return array{success?: true, document?: array<string, mixed>, error?: string}
     */
    private function persistOriginalBinary(string|int $userId, string $binary, ?string $originalName = null): array
    {
        $this->storage->ensureDirectory($userId);
        $filename = $this->allocateGalleryFilename($userId, $originalName);
        $path = $this->storage->filePath($userId, $filename);

        if (! Storage::disk($this->storage->disk())->put($path, $binary)) {
            return ['error' => 'Falha ao guardar o documento.'];
        }

        $this->storage->appendToGalleryOrder($userId, $filename);
        $fullPath = $this->storage->storagePath($userId, $filename);

        $document = $this->formatDocument($userId, $filename, $path, $fullPath);
        $this->notifyDocumentPersisted($userId, $document, [
            'type' => 'upload',
            'original_name' => $originalName,
            'mime' => GalleryDocument::guessMimeType($filename),
        ]);

        return [
            'success' => true,
            'document' => $document,
        ];
    }

    private function allocateGalleryFilename(string|int $userId, ?string $originalName): string
    {
        $base = GalleryDocument::sanitizeStoredFilename((string) $originalName);
        $candidate = $base;
        $suffix = 1;

        while ($this->storage->fileExists($userId, $candidate)) {
            $stem = pathinfo($base, PATHINFO_FILENAME);
            $extension = GalleryDocument::extension($base);
            $candidate = $stem.'_'.$suffix.($extension !== '' ? '.'.$extension : '');
            $suffix++;
        }

        return $candidate;
    }

    private function persistPdfBinary(string|int $userId, string $pdfBinary, ?string $originalName = null): array
    {
        if (! $this->hasPdfHeaderFromBinary($pdfBinary)) {
            return ['error' => 'O conteúdo convertido não é um PDF válido.'];
        }

        $this->storage->ensureDirectory($userId);
        $filename = $this->allocateGalleryFilename(
            $userId,
            $this->buildStoredFilename($originalName)
        );
        $path = $this->storage->filePath($userId, $filename);

        if (! Storage::disk($this->storage->disk())->put($path, $pdfBinary)) {
            return ['error' => 'Falha ao guardar o PDF.'];
        }

        $this->storage->appendToGalleryOrder($userId, $filename);
        $fullPath = $this->storage->storagePath($userId, $filename);
        $this->thumbnailService->ensureThumbnail($userId, $filename);

        $document = $this->formatDocument($userId, $filename, $path, $fullPath);
        $this->notifyDocumentPersisted($userId, $document, [
            'type' => 'upload',
            'original_name' => $originalName,
        ]);

        return [
            'success' => true,
            'document' => $document,
        ];
    }

    private function buildStoredFilename(?string $originalName): string
    {
        $base = 'pdf_'.time().'_'.bin2hex(random_bytes(4));

        if (! is_string($originalName) || trim($originalName) === '') {
            return $base.'.pdf';
        }

        $stem = pathinfo($originalName, PATHINFO_FILENAME);
        $stem = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $stem);
        $stem = trim((string) $stem, '_');

        return ($stem !== '' ? $stem.'_' : '').$base.'.pdf';
    }

    private function decodeCallbackBinary(string $content): ?string
    {
        $content = (string) preg_replace('#^data:application/pdf;base64,#i', '', $content);
        $content = (string) preg_replace('#^data:.*?;base64,#i', '', $content);
        $binary = base64_decode($content, true);

        if ($binary === false || $binary === '') {
            return null;
        }

        return $binary;
    }

    /**
     * @return list<array{name: string, content: string}>
     */
    private function normalizeCallbackPayload(array $payload): array
    {
        if ($payload === []) {
            return [];
        }

        if (isset($payload['name'], $payload['content'])) {
            return [$payload];
        }

        $first = reset($payload);

        if (is_array($first) && isset($first['name'], $first['content'])) {
            return array_values($payload);
        }

        return [];
    }

    /**
     * @param  list<string>  $newFilenames
     */
    private function broadcastDocumentsUploaded(string|int $userId, int $saved, array $newFilenames): void
    {
        if (! config('pdf-gallery.broadcasting.enabled', true)) {
            return;
        }

        try {
            $sanitizedId = $this->storage->sanitizeUserId($userId);
            $documents = $this->listDocuments($userId)['documents'] ?? [];

            PdfsUploadedFromMobile::dispatch($sanitizedId, $saved, $documents, $newFilenames);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function estimatePageCount(string $absolutePath): ?int
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return null;
        }

        $content = file_get_contents($absolutePath, false, null, 0, 5_000_000);

        if (! is_string($content) || $content === '') {
            return null;
        }

        if (! preg_match_all('/\/Type\s*\/Page[^s]/', $content, $matches)) {
            return null;
        }

        $count = count($matches[0]);

        return $count > 0 ? $count : null;
    }

    /**
     * @param  list<array{filename: string, url: string, path: string, timestamp: int|float, page_count: int|null, size_bytes: int}>  $documents
     * @return list<array{filename: string, url: string, path: string, timestamp: int|float, page_count: int|null, size_bytes: int}>
     */
    private function sortByGalleryOrder(string|int $userId, array $documents): array
    {
        $order = $this->storage->readGalleryOrder($userId);

        if ($order === []) {
            usort($documents, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));

            return $documents;
        }

        $byName = [];

        foreach ($documents as $document) {
            $byName[$document['filename']] = $document;
        }

        $sorted = [];

        foreach ($order as $filename) {
            if (isset($byName[$filename])) {
                $sorted[] = $byName[$filename];
                unset($byName[$filename]);
            }
        }

        foreach ($byName as $document) {
            $sorted[] = $document;
        }

        return $sorted;
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function enrichDocuments(string|int $userId, array $documents): array
    {
        $enricher = config('pdf-gallery.documents.enricher');

        if (! is_callable($enricher)) {
            return $documents;
        }

        return array_values(array_map(
            static fn (array $document): array => $enricher($userId, $document),
            $documents,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function applyProtectedDocumentFlags(array $documents): array
    {
        $protected = $this->protectedBasenames();

        if ($protected === []) {
            return $documents;
        }

        return array_values(array_map(
            static function (array $document) use ($protected): array {
                $basename = basename((string) ($document['filename'] ?? ''));

                if ($basename === '' || ! in_array($basename, $protected, true)) {
                    return $document;
                }

                $document['protected'] = true;
                $document['deletable'] = false;

                return $document;
            },
            $documents,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function filterDocuments(string|int $userId, array $documents): array
    {
        $filter = config('pdf-gallery.documents.filter');

        if (! is_callable($filter)) {
            return $documents;
        }

        return array_values(array_filter(
            $documents,
            static fn (array $document): bool => $filter($userId, $document),
        ));
    }

    /**
     * @param  list<string>  $filenames
     * @return list<string>
     */
    private function sortFilenamesByGalleryOrder(string|int $userId, array $filenames): array
    {
        $order = $this->storage->readGalleryOrder($userId);

        if ($order === []) {
            return $filenames;
        }

        $positions = array_flip($order);

        usort(
            $filenames,
            fn (string $a, string $b) => ($positions[$a] ?? PHP_INT_MAX) <=> ($positions[$b] ?? PHP_INT_MAX)
        );

        return array_values($filenames);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $context
     */
    private function notifyDocumentPersisted(string|int $userId, array $document, array $context = []): void
    {
        $handler = config('pdf-gallery.documents.persist_handler');

        if (! is_callable($handler)) {
            return;
        }

        try {
            $handler($userId, $document, $context);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<string>
     */
    private function protectedBasenames(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($name) => basename((string) $name),
            (array) config('pdf-gallery.gallery.protected_filenames', []),
        ))));
    }
}
