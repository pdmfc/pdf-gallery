<?php

namespace PDMFC\PdfGallery\Console\Commands;

use Illuminate\Console\Command;
use PDMFC\PdfGallery\Services\PdfConvertService;
use PDMFC\PdfGallery\Support\CliBinaryResolver;

class CheckToolsCommand extends Command
{
    protected $signature = 'pdf-gallery:check-tools';

    protected $description = 'Verifica dependências do PDF Gallery (merge, miniaturas, conversão)';

    public function handle(CliBinaryResolver $resolver, PdfConvertService $convertService): int
    {
        $report = $resolver->diagnostics();

        $this->line('PDF Gallery — ferramentas');
        $this->line('Motores de merge: '.implode(', ', $report['merge_engines']));
        $this->line('Pastas de procura: '.implode(', ', $report['search_paths']));
        $this->newLine();

        $this->renderToolRow('qpdf', $report['qpdf']);
        $this->renderToolRow('ghostscript', $report['ghostscript']);
        $this->renderFpdiRow($report['fpdi']);
        $this->renderThumbnailRow($report['thumbnails']);

        $this->newLine();
        $this->line('Conversão para PDF: '.($convertService->isEnabled() ? 'activada' : 'desactivada'));

        foreach ($convertService->diagnostics() as $converter) {
            $status = $converter['available'] ? '<info>OK</info>' : '<comment>indisponível</comment>';
            $supports = $converter['supports'] === [] ? '—' : implode(', ', $converter['supports']);

            $this->line(sprintf(
                '  %s (suporta: %s) %s',
                $converter['name'],
                $supports,
                $status
            ));
        }

        if ($resolver->isHealthy()) {
            $this->info('Tudo OK para merge e miniaturas.');

            return self::SUCCESS;
        }

        $this->error('Faltam dependências. Em Kubernetes/OpenShift instale qpdf e ghostscript na imagem e defina PDF_GALLERY_*_BINARY=/usr/bin/...');

        return self::FAILURE;
    }

    /**
     * @param  array{configured: string|null, resolved: string|null, required: bool, ok: bool}  $tool
     */
    private function renderToolRow(string $label, array $tool): void
    {
        $status = $tool['ok'] ? '<info>OK</info>' : '<error>FALTA</error>';
        $required = $tool['required'] ? 'obrigatório' : 'opcional';
        $configured = $tool['configured'] ?? '(auto)';
        $resolved = $tool['resolved'] ?? '—';

        $this->line(sprintf(
            '%s [%s] config=%s resolved=%s %s',
            $label,
            $required,
            $configured,
            $resolved,
            $status
        ));
    }

    /**
     * @param  array{required: bool, ok: bool}  $fpdi
     */
    private function renderFpdiRow(array $fpdi): void
    {
        $status = $fpdi['ok'] ? '<info>OK</info>' : '<error>FALTA</error>';
        $required = $fpdi['required'] ? 'obrigatório' : 'opcional';

        $this->line(sprintf('fpdi [%s] pacote PHP %s', $required, $status));
    }

    /**
     * @param  array{required: bool, ok: bool, resolved: string|null}  $thumbnails
     */
    private function renderThumbnailRow(array $thumbnails): void
    {
        $status = $thumbnails['ok'] ? '<info>OK</info>' : '<error>FALTA</error>';
        $resolved = $thumbnails['resolved'] ?? '—';

        $this->line(sprintf(
            'miniaturas [obrigatório] ghostscript=%s %s',
            $resolved,
            $status
        ));
    }
}
