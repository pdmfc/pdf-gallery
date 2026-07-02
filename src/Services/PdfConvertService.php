<?php

namespace PDMFC\PdfGallery\Services;

use Illuminate\Http\UploadedFile;
use PDMFC\PdfGallery\Contracts\DocumentToPdfConverter;
use PDMFC\PdfGallery\Support\DocumentMimeTypes;
use PDMFC\PdfGallery\Support\TempFile;

class PdfConvertService
{
    /** @var list<DocumentToPdfConverter> */
    private array $converters;

    /**
     * @param  iterable<DocumentToPdfConverter>  $converters
     */
    public function __construct(iterable $converters)
    {
        $this->converters = $this->orderConverters($converters);
    }

    public function isEnabled(): bool
    {
        return (bool) config('pdf-gallery.convert.enabled', false);
    }

    public function acceptsUpload(UploadedFile $file): bool
    {
        $mime = (string) $file->getMimeType();
        $name = (string) $file->getClientOriginalName();

        if (DocumentMimeTypes::isPdf($mime, $name, $this->readUploadPrefix($file))) {
            return true;
        }

        return $this->isEnabled() && DocumentMimeTypes::isConvertible($mime, $name);
    }

    public function toPdfFromUpload(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $name = (string) $file->getClientOriginalName();
        $binary = file_get_contents($file->getPathname());

        if (! is_string($binary) || $binary === '') {
            throw new \RuntimeException('Ficheiro vazio ou ilegível.');
        }

        return $this->toPdfBinary($binary, $mime, $name);
    }

    public function toPdfBinary(string $binary, string $mimeType, string $filename): string
    {
        if (DocumentMimeTypes::isPdf($mimeType, $filename, $binary)) {
            return $binary;
        }

        if (! $this->isEnabled()) {
            throw new \RuntimeException('Conversão desactivada. Envie apenas PDF ou active PDF_GALLERY_CONVERT_ENABLED.');
        }

        if (! DocumentMimeTypes::isConvertible($mimeType, $filename)) {
            throw new \RuntimeException('Tipo de ficheiro não suportado para conversão.');
        }

        $extension = DocumentMimeTypes::extension($filename) ?: 'bin';
        $inputPath = TempFile::write($binary, $extension);

        try {
            return $this->convertFile($inputPath, $mimeType, $filename);
        } finally {
            TempFile::cleanup($inputPath);
        }
    }

    /**
     * @return list<array{name: string, available: bool, supports: list<string>}>
     */
    public function diagnostics(): array
    {
        $rows = [];

        foreach ($this->converters as $converter) {
            $supports = [];

            if ($converter->supports('image/jpeg', 'photo.jpg')) {
                $supports[] = 'image';
            }

            if ($converter->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc.docx')) {
                $supports[] = 'word';
            }

            $rows[] = [
                'name' => $converter->name(),
                'available' => $converter->isAvailable(),
                'supports' => $supports,
            ];
        }

        return $rows;
    }

    private function convertFile(string $absoluteInputPath, string $mimeType, string $filename): string
    {
        $category = DocumentMimeTypes::category($mimeType, $filename);
        $errors = [];

        foreach ($this->convertersForCategory($category) as $converter) {
            if (! $converter->supports($mimeType, $filename)) {
                continue;
            }

            if (! $converter->isAvailable()) {
                $errors[$converter->name()] = 'motor não disponível';

                continue;
            }

            try {
                return $converter->convert($absoluteInputPath, $mimeType, $filename);
            } catch (\Throwable $e) {
                $errors[$converter->name()] = $e->getMessage();
            }
        }

        $detail = $errors === []
            ? 'Nenhum motor de conversão configurado.'
            : implode(' | ', array_map(
                fn ($name, $message) => "{$name}: {$message}",
                array_keys($errors),
                $errors
            ));

        throw new \RuntimeException('Não foi possível converter para PDF. '.$detail);
    }

    /**
     * @return list<DocumentToPdfConverter>
     */
    private function convertersForCategory(?string $category): array
    {
        $engines = match ($category) {
            'image' => (array) config('pdf-gallery.convert.image_engines', ['ghostscript', 'gotenberg']),
            'word' => (array) config('pdf-gallery.convert.word_engines', ['libreoffice', 'gotenberg']),
            default => [],
        };

        $engines = array_values(array_filter(array_map('trim', $engines)));

        if ($engines === []) {
            return $this->converters;
        }

        $byName = [];

        foreach ($this->converters as $converter) {
            $byName[$converter->name()] = $converter;
        }

        $ordered = [];

        foreach ($engines as $engine) {
            if (isset($byName[$engine])) {
                $ordered[] = $byName[$engine];
            }
        }

        return $ordered !== [] ? $ordered : $this->converters;
    }

    /**
     * @param  iterable<DocumentToPdfConverter>  $converters
     * @return list<DocumentToPdfConverter>
     */
    private function orderConverters(iterable $converters): array
    {
        $all = [];

        foreach ($converters as $converter) {
            $all[$converter->name()] = $converter;
        }

        $engines = array_values(array_filter(array_map(
            'trim',
            (array) config('pdf-gallery.convert.engines', ['ghostscript', 'libreoffice', 'gotenberg'])
        )));

        $ordered = [];

        foreach ($engines as $engine) {
            if (isset($all[$engine])) {
                $ordered[] = $all[$engine];
            }
        }

        foreach ($all as $converter) {
            if (! in_array($converter, $ordered, true)) {
                $ordered[] = $converter;
            }
        }

        return $ordered;
    }

    private function readUploadPrefix(UploadedFile $file): ?string
    {
        $path = $file->getPathname();

        if (! is_readable($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $prefix = fread($handle, 5);
        fclose($handle);

        return is_string($prefix) ? $prefix : null;
    }
}
