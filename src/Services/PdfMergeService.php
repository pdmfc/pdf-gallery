<?php

namespace PDMFC\PdfGallery\Services;

use Illuminate\Support\Facades\Log;
use PDMFC\PdfGallery\Support\CliBinaryResolver;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;

class PdfMergeService
{
    public function __construct(
        private readonly CliBinaryResolver $binaries,
    ) {}

    /**
     * @param  list<string>  $absolutePaths  Caminhos absolutos dos PDFs, por ordem
     */
    public function merge(array $absolutePaths): string
    {
        $absolutePaths = array_values(array_filter($absolutePaths, fn ($path) => is_string($path) && is_file($path)));

        if (count($absolutePaths) < 2) {
            throw new \InvalidArgumentException('São necessários pelo menos dois PDFs para juntar.');
        }

        $engines = config('pdf-gallery.merge.engines', ['qpdf', 'ghostscript', 'fpdi']);
        $errors = [];

        foreach ($engines as $engine) {
            try {
                return match ($engine) {
                    'fpdi' => $this->mergeWithFpdi($absolutePaths),
                    'qpdf' => $this->mergeWithQpdf($absolutePaths),
                    'ghostscript' => $this->mergeWithGhostscript($absolutePaths),
                    default => throw new \RuntimeException("Motor de merge desconhecido: {$engine}"),
                };
            } catch (\Throwable $e) {
                $errors[$engine] = $e->getMessage();
                Log::debug('pdf-gallery merge engine failed', [
                    'engine' => $engine,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        throw new \RuntimeException(
            'Não foi possível juntar os PDFs. '.implode(' | ', array_map(
                fn ($engine, $message) => "{$engine}: {$message}",
                array_keys($errors),
                $errors
            ))
        );
    }

    /**
     * @param  list<string>  $absolutePaths
     */
    private function mergeWithFpdi(array $absolutePaths): string
    {
        $pdf = new Fpdi();

        foreach ($absolutePaths as $path) {
            $pageCount = $pdf->setSourceFile($path);

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $orientation = ($size['width'] ?? 0) > ($size['height'] ?? 0) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }

        return $pdf->Output('S');
    }

    /**
     * @param  list<string>  $absolutePaths
     */
    private function mergeWithQpdf(array $absolutePaths): string
    {
        $binary = $this->binaries->resolveQpdf();

        if ($binary === null) {
            throw new \RuntimeException(
                'qpdf não está disponível. Em Kubernetes/OpenShift instale o pacote na imagem e defina PDF_GALLERY_QPDF_BINARY=/usr/bin/qpdf.'
            );
        }

        $output = $this->temporaryOutputPath();
        $args = array_merge(
            [$binary, '--empty', '--pages'],
            $absolutePaths,
            ['--', $output]
        );

        $this->runProcess($args);

        $content = file_get_contents($output);
        @unlink($output);

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('qpdf não produziu um PDF válido.');
        }

        return $content;
    }

    /**
     * @param  list<string>  $absolutePaths
     */
    private function mergeWithGhostscript(array $absolutePaths): string
    {
        $binary = $this->binaries->resolveGhostscript();

        if ($binary === null) {
            throw new \RuntimeException(
                'Ghostscript não está disponível. Em Kubernetes/OpenShift instale o pacote na imagem e defina PDF_GALLERY_GHOSTSCRIPT_BINARY=/usr/bin/gs.'
            );
        }

        $output = $this->temporaryOutputPath();
        $args = array_merge(
            [
                $binary,
                '-dBATCH',
                '-dNOPAUSE',
                '-q',
                '-sDEVICE=pdfwrite',
                '-dPDFSETTINGS=/prepress',
                '-sOutputFile='.$output,
            ],
            $absolutePaths
        );

        $this->runProcess($args);

        $content = file_get_contents($output);
        @unlink($output);

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('Ghostscript não produziu um PDF válido.');
        }

        return $content;
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
            throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput() ?: 'Falha no processo de merge.'));
        }
    }

    private function temporaryOutputPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-gallery-merge-');

        if ($path === false) {
            throw new \RuntimeException('Não foi possível criar ficheiro temporário.');
        }

        $pdfPath = $path.'.pdf';
        @unlink($path);

        return $pdfPath;
    }
}
