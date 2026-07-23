<?php

namespace PDMFC\PdfGallery\Support;

use Illuminate\Support\Facades\Storage;

class PdfStorage
{
    public function disk(): string
    {
        return (string) config('pdf-gallery.storage.disk', 'public');
    }

    public function sanitizeUserId(string|int $userId): string
    {
        return UserId::sanitize($userId);
    }

    public function directory(string|int $userId): string
    {
        $resolver = config('pdf-gallery.storage.directory_resolver');

        if (is_callable($resolver)) {
            $resolved = $resolver($userId);

            if (is_string($resolved) && $resolved !== '') {
                return trim($resolved, '/');
            }
        }

        $base = trim((string) config('pdf-gallery.storage.path', 'pdfs/tmp'), '/');

        return $base.'/'.$this->sanitizeUserId($userId);
    }

    public function ensureDirectory(string|int $userId): void
    {
        $dir = $this->directory($userId);

        if (! Storage::disk($this->disk())->exists($dir)) {
            Storage::disk($this->disk())->makeDirectory($dir);
        }
    }

    public function safeFilename(string $filename): string
    {
        return basename($filename);
    }

    public function ensurePdfExtension(string $filename): string
    {
        $name = $this->safeFilename($filename);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            return $name;
        }

        return pathinfo($name, PATHINFO_FILENAME).'.pdf';
    }

    public function filePath(string|int $userId, string $filename): string
    {
        $filename = $this->safeFilename($filename);
        $resolver = config('pdf-gallery.storage.file_path_resolver');

        if (is_callable($resolver)) {
            $resolved = $resolver($userId, $filename);

            if (is_string($resolved) && $resolved !== '') {
                return trim($resolved, '/');
            }
        }

        return $this->directory($userId).'/'.$filename;
    }

    public function storagePath(string|int $userId, string $filename): string
    {
        $relative = $this->filePath($userId, $filename);
        $root = config("filesystems.disks.{$this->disk()}.root");

        if (is_string($root) && $root !== '') {
            return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        }

        return storage_path('app/public/'.$relative);
    }

    public function fileExists(string|int $userId, string $filename): bool
    {
        return Storage::disk($this->disk())->exists($this->filePath($userId, $filename));
    }

    public function callbackUrl(string|int $userId): string
    {
        return CallbackRoute::resolvedUrl($userId);
    }

    public function pdfUrl(string|int $userId, string $filename): string
    {
        $userId = $this->sanitizeUserId($userId);
        $filename = $this->safeFilename($filename);
        $driver = (string) config("filesystems.disks.{$this->disk()}.driver", 'local');

        if ($driver !== 'local') {
            return Storage::disk($this->disk())->url($this->filePath($userId, $filename));
        }

        return '/api/pdf-gallery/files/'.$userId.'/'.rawurlencode($filename);
    }

    public function thumbnailUrl(string|int $userId, string $pdfFilename): string
    {
        $userId = $this->sanitizeUserId($userId);
        $pdfFilename = $this->safeFilename($pdfFilename);
        $driver = (string) config("filesystems.disks.{$this->disk()}.driver", 'local');

        if ($driver !== 'local') {
            $thumbName = pathinfo($pdfFilename, PATHINFO_FILENAME).'_thumb.jpg';

            return Storage::disk($this->disk())->url($this->filePath($userId, $thumbName));
        }

        return '/api/pdf-gallery/thumbs/'.$userId.'/'.rawurlencode($pdfFilename);
    }

    public function previewUrl(string|int $userId, string $filename): string
    {
        $userId = $this->sanitizeUserId($userId);
        $filename = $this->safeFilename($filename);

        return '/api/pdf-gallery/preview/'.$userId.'/'.rawurlencode($filename);
    }

    public function galleryOrderPath(string|int $userId): string
    {
        return $this->directory($userId).'/.gallery-order.json';
    }

    /**
     * @return list<string>
     */
    public function readGalleryOrder(string|int $userId): array
    {
        $path = $this->galleryOrderPath($userId);

        if (! Storage::disk($this->disk())->exists($path)) {
            return [];
        }

        $json = Storage::disk($this->disk())->get($path);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? $this->safeFilename($item) : null,
            $data
        )));
    }

    /**
     * @param  list<string>  $filenames
     */
    public function writeGalleryOrder(string|int $userId, array $filenames): void
    {
        $path = $this->galleryOrderPath($userId);
        $clean = array_values(array_unique(array_map(
            fn ($name) => $this->safeFilename((string) $name),
            $filenames
        )));

        Storage::disk($this->disk())->put(
            $path,
            json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );
    }

    public function appendToGalleryOrder(string|int $userId, string $filename): void
    {
        $order = $this->readGalleryOrder($userId);
        $filename = $this->safeFilename($filename);
        $order = array_values(array_filter($order, fn ($item) => $item !== $filename));
        $order[] = $filename;
        $this->writeGalleryOrder($userId, $order);
    }

    public function removeFromGalleryOrder(string|int $userId, string $filename): void
    {
        $filename = $this->safeFilename($filename);
        $order = array_values(array_filter(
            $this->readGalleryOrder($userId),
            fn ($item) => $item !== $filename
        ));
        $this->writeGalleryOrder($userId, $order);
    }
}
