<?php

namespace PDMFC\PdfGallery\Services;

use Illuminate\Support\Facades\Log;
use PDMFC\PdfGallery\Support\CliBinaryResolver;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;

class PdfExtractService
{
    public function __construct(
        private readonly CliBinaryResolver $binaries,
    ) {}

    /**
     * @param  list<int>  $pages  Números de página (1-based), por ordem
     */
    public function extract(string $absolutePath, array $pages): string
    {
        if (! is_file($absolutePath)) {
            throw new \InvalidArgumentException('O PDF de origem não existe.');
        }

        $pages = $this->normalizePages($pages);

        if ($pages === []) {
            throw new \InvalidArgumentException('Indique pelo menos uma página a extrair.');
        }

        $engines = config('pdf-gallery.merge.engines', ['qpdf', 'ghostscript', 'fpdi']);
        $errors = [];

        foreach ($engines as $engine) {
            try {
                return match ($engine) {
                    'fpdi' => $this->extractWithFpdi($absolutePath, $pages),
                    'qpdf' => $this->extractWithQpdf($absolutePath, $pages),
                    'ghostscript' => $this->extractWithGhostscript($absolutePath, $pages),
                    default => throw new \RuntimeException("Motor de extração desconhecido: {$engine}"),
                };
            } catch (\Throwable $e) {
                $errors[$engine] = $e->getMessage();
                Log::debug('pdf-gallery extract engine failed', [
                    'engine' => $engine,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            'Não foi possível extrair as páginas. '.implode(' | ', array_map(
                fn ($engine, $message) => "{$engine}: {$message}",
                array_keys($errors),
                $errors
            ))
        );
    }

    /**
     * @param  list<int>  $pages
     * @return list<int>
     */
    public function normalizePages(array $pages): array
    {
        $normalized = [];

        foreach ($pages as $page) {
            if (! is_numeric($page)) {
                continue;
            }

            $value = (int) $page;

            if ($value >= 1) {
                $normalized[] = $value;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  list<int>  $pages
     */
    private function extractWithFpdi(string $absolutePath, array $pages): string
    {
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($absolutePath);

        foreach ($pages as $page) {
            if ($page < 1 || $page > $pageCount) {
                throw new \InvalidArgumentException("A página {$page} não existe (o PDF tem {$pageCount} páginas).");
            }

            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $orientation = ($size['width'] ?? 0) > ($size['height'] ?? 0) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
        }

        return $pdf->Output('S');
    }

    /**
     * @param  list<int>  $pages
     */
    private function extractWithQpdf(string $absolutePath, array $pages): string
    {
        $binary = $this->binaries->resolveQpdf();

        if ($binary === null) {
            throw new \RuntimeException(
                'qpdf não está disponível. Em Kubernetes/OpenShift instale o pacote na imagem e defina PDF_GALLERY_QPDF_BINARY=/usr/bin/qpdf.'
            );
        }

        $output = $this->temporaryOutputPath();
        $pageSpec = $this->formatPagesForQpdf($pages);
        $args = [
            $binary,
            $absolutePath,
            '--pages',
            '.',
            $pageSpec,
            '--',
            $output,
        ];

        $this->runProcess($args);

        $content = file_get_contents($output);
        @unlink($output);

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('qpdf não produziu um PDF válido.');
        }

        return $content;
    }

    /**
     * @param  list<int>  $pages
     */
    private function extractWithGhostscript(string $absolutePath, array $pages): string
    {
        if (! $this->isContiguousRange($pages)) {
            throw new \RuntimeException('Ghostscript só suporta intervalos contínuos de páginas.');
        }

        $binary = $this->binaries->resolveGhostscript();

        if ($binary === null) {
            throw new \RuntimeException(
                'Ghostscript não está disponível. Em Kubernetes/OpenShift instale o pacote na imagem e defina PDF_GALLERY_GHOSTSCRIPT_BINARY=/usr/bin/gs.'
            );
        }

        $output = $this->temporaryOutputPath();
        $firstPage = $pages[0];
        $lastPage = $pages[count($pages) - 1];
        $args = [
            $binary,
            '-dBATCH',
            '-dNOPAUSE',
            '-q',
            '-sDEVICE=pdfwrite',
            '-dPDFSETTINGS=/prepress',
            '-dFirstPage='.$firstPage,
            '-dLastPage='.$lastPage,
            '-sOutputFile='.$output,
            $absolutePath,
        ];

        $this->runProcess($args);

        $content = file_get_contents($output);
        @unlink($output);

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('Ghostscript não produziu um PDF válido.');
        }

        return $content;
    }

    /**
     * @param  list<int>  $pages
     */
    private function formatPagesForQpdf(array $pages): string
    {
        $ranges = [];
        $start = $pages[0];
        $previous = $pages[0];

        for ($index = 1, $count = count($pages); $index < $count; $index++) {
            $page = $pages[$index];

            if ($page === $previous + 1) {
                $previous = $page;

                continue;
            }

            $ranges[] = $start === $previous ? (string) $start : "{$start}-{$previous}";
            $start = $page;
            $previous = $page;
        }

        $ranges[] = $start === $previous ? (string) $start : "{$start}-{$previous}";

        return implode(',', $ranges);
    }

    /**
     * @param  list<int>  $pages
     */
    private function isContiguousRange(array $pages): bool
    {
        if ($pages === []) {
            return false;
        }

        for ($index = 1, $count = count($pages); $index < $count; $index++) {
            if ($pages[$index] !== $pages[$index - 1] + 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Falha no processo de extração.'));
        }
    }

    private function temporaryOutputPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-gallery-extract-');

        if ($path === false) {
            throw new \RuntimeException('Não foi possível criar ficheiro temporário.');
        }

        $pdfPath = $path.'.pdf';
        @unlink($path);

        return $pdfPath;
    }
}
