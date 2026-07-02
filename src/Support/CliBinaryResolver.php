<?php

namespace PDMFC\PdfGallery\Support;

use Symfony\Component\Process\ExecutableFinder;

class CliBinaryResolver
{
    public function __construct(
        private readonly ?ExecutableFinder $finder = null,
    ) {}

    /**
     * @param  list<string>  $commandNames
     */
    public function resolve(?string $configured, array $commandNames): ?string
    {
        $configured = is_string($configured) ? trim($configured) : '';

        if ($configured !== '') {
            if ($this->looksLikePath($configured)) {
                return $this->isExecutablePath($configured) ? $configured : null;
            }

            $fromAlias = $this->findByName($configured);

            return $fromAlias;
        }

        foreach (array_values(array_unique(array_filter($commandNames))) as $name) {
            $found = $this->findByName($name);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function resolveQpdf(): ?string
    {
        return $this->resolve(
            config('pdf-gallery.merge.qpdf_binary'),
            ['qpdf']
        );
    }

    public function resolveGhostscript(): ?string
    {
        return $this->resolve(
            config('pdf-gallery.merge.ghostscript_binary'),
            ['gs', 'gswin64c', 'gswin32c']
        );
    }

    public function resolveLibreOffice(): ?string
    {
        return $this->resolve(
            config('pdf-gallery.convert.libreoffice_binary'),
            ['soffice', 'libreoffice']
        );
    }

    /**
     * @return array{
     *     merge_engines: list<string>,
     *     search_paths: list<string>,
     *     qpdf: array{configured: string|null, resolved: string|null, required: bool, ok: bool},
     *     ghostscript: array{configured: string|null, resolved: string|null, required: bool, ok: bool},
     *     fpdi: array{required: bool, ok: bool}
     * }
     */
    public function diagnostics(): array
    {
        $engines = array_values(array_filter(array_map(
            'trim',
            (array) config('pdf-gallery.merge.engines', ['qpdf', 'ghostscript', 'fpdi'])
        )));

        $needsQpdf = in_array('qpdf', $engines, true);
        $needsGhostscript = in_array('ghostscript', $engines, true);
        $qpdf = $this->resolveQpdf();
        $ghostscript = $this->resolveGhostscript();

        return [
            'merge_engines' => $engines,
            'search_paths' => $this->searchPaths(),
            'qpdf' => [
                'configured' => $this->configuredValue('pdf-gallery.merge.qpdf_binary'),
                'resolved' => $qpdf,
                'required' => $needsQpdf,
                'ok' => ! $needsQpdf || $qpdf !== null,
            ],
            'ghostscript' => [
                'configured' => $this->configuredValue('pdf-gallery.merge.ghostscript_binary'),
                'resolved' => $ghostscript,
                'required' => $needsGhostscript,
                'ok' => ! $needsGhostscript || $ghostscript !== null,
            ],
            'fpdi' => [
                'required' => in_array('fpdi', $engines, true),
                'ok' => class_exists(\setasign\Fpdi\Fpdi::class),
            ],
            'thumbnails' => [
                'required' => true,
                'ok' => $ghostscript !== null,
                'resolved' => $ghostscript,
            ],
        ];
    }

    public function isHealthy(): bool
    {
        $report = $this->diagnostics();

        if ($report['qpdf']['required'] && ! $report['qpdf']['ok']) {
            return false;
        }

        if ($report['ghostscript']['required'] && ! $report['ghostscript']['ok']) {
            return false;
        }

        if ($report['fpdi']['required'] && ! $report['fpdi']['ok']) {
            return false;
        }

        if (! $report['thumbnails']['ok']) {
            return false;
        }

        return true;
    }

    private function findByName(string $name): ?string
    {
        $found = $this->finder()->find($name, null, $this->searchPaths());

        if ($found === null || ! $this->isExecutablePath($found)) {
            return null;
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function searchPaths(): array
    {
        $configured = config('pdf-gallery.merge.binary_search_paths', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($path) => is_string($path) ? rtrim(trim($path), DIRECTORY_SEPARATOR) : null,
            $configured
        ))));
    }

    private function finder(): ExecutableFinder
    {
        return $this->finder ?? new ExecutableFinder;
    }

    private function looksLikePath(string $value): bool
    {
        return str_contains($value, DIRECTORY_SEPARATOR)
            || str_starts_with($value, '~');
    }

    private function isExecutablePath(string $path): bool
    {
        $path = trim($path);

        if ($path === '') {
            return false;
        }

        return is_file($path) && is_executable($path);
    }

    private function configuredValue(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
