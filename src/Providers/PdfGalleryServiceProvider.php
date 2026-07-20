<?php

namespace PDMFC\PdfGallery\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Nova\Nova;
use PDMFC\PdfGallery\Console\Commands\CheckToolsCommand;
use PDMFC\PdfGallery\Http\Controllers\PdfGalleryController;
use PDMFC\PdfGallery\Http\Controllers\PdfJsWorkerController;
use PDMFC\PdfGallery\Services\Convert\FpdfImageConverter;
use PDMFC\PdfGallery\Services\Convert\GhostscriptImageConverter;
use PDMFC\PdfGallery\Services\Convert\GotenbergConverter;
use PDMFC\PdfGallery\Services\Convert\LibreOfficeConverter;
use PDMFC\PdfGallery\Services\PdfConvertService;
use PDMFC\PdfGallery\Services\PdfExtractService;
use PDMFC\PdfGallery\Services\PdfGalleryService;
use PDMFC\PdfGallery\Services\PdfMergeService;
use PDMFC\PdfGallery\Services\PdfThumbnailService;
use PDMFC\PdfGallery\Services\QrCodeService;
use PDMFC\PdfGallery\Support\CallbackRoute;
use PDMFC\PdfGallery\Support\CliBinaryResolver;
use PDMFC\PdfGallery\Support\PdfStorage;

class PdfGalleryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/pdf-gallery.php', 'pdf-gallery');

        $this->app->singleton(CliBinaryResolver::class);
        $this->app->singleton(PdfStorage::class);
        $this->app->singleton(PdfMergeService::class);
        $this->app->singleton(PdfExtractService::class);
        $this->app->singleton(PdfThumbnailService::class);
        $this->app->singleton(PdfConvertService::class, function ($app) {
            return new PdfConvertService([
                $app->make(FpdfImageConverter::class),
                $app->make(GhostscriptImageConverter::class),
                $app->make(LibreOfficeConverter::class),
                $app->make(GotenbergConverter::class),
            ]);
        });
        $this->app->singleton(PdfGalleryService::class);
        $this->app->singleton(QrCodeService::class);
    }

    public function boot(): void
    {
        $this->registerBroadcastChannels();
        $this->shareInertiaConfig();
        $this->shareNovaConfig();

        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'pdf-gallery');

        $this->publishes([
            __DIR__.'/../Config/pdf-gallery.php' => config_path('pdf-gallery.php'),
        ], 'pdf-gallery-config');

        $this->publishes([
            __DIR__.'/../stubs/routes-demo.php' => base_path('routes/pdf-gallery-demo.php'),
        ], 'pdf-gallery-demo-routes');

        $this->publishes([
            __DIR__.'/../stubs/deployment.env.example' => base_path('pdf-gallery.deployment.env.example'),
        ], 'pdf-gallery-deployment');

        $this->publishes([
            __DIR__.'/../stubs/nova-service-provider.php' => base_path('stubs/pdf-gallery-nova-service-provider.php'),
        ], 'pdf-gallery-nova');

        $this->publishes([
            __DIR__.'/../Resources/assets/vendor/pdfjs' => public_path('vendor/pdfjs'),
        ], 'pdf-gallery-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckToolsCommand::class,
            ]);
        }

        // Serve the PDF.js worker from the package so hosts don't depend on a
        // published static file under public/vendor/pdfjs (Vite ?url assets fail
        // easily behind reverse proxies / incomplete builds).
        // Path is under /pdf-gallery/... (not /vendor/...) so nginx static
        // handling cannot short-circuit a missing public file with a raw 404.
        Route::get('/pdf-gallery/assets/pdf.worker.min.js', PdfJsWorkerController::class)
            ->name('pdf-gallery.pdfjs.worker');

        if (config('pdf-gallery.demo_routes', false)) {
            Route::middleware('web')->group(function () {
                $this->loadRoutesFrom(__DIR__.'/../Routes/demo.php');
            });
        }

        Route::prefix((string) config('pdf-gallery.routes.prefix', 'api'))->group(function () {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        });

        $callbackRoute = Route::middleware(config('pdf-gallery.routes.callback_middleware', ['api']))
            ->post(CallbackRoute::routePath(), [PdfGalleryController::class, 'callbackFiles'])
            ->name('pdf-gallery.callback.files');

        if (config('pdf-gallery.qr_code.callback_scope_constraint') === 'uuid') {
            $callbackRoute->whereUuid(CallbackRoute::routeParameterName());
        }
    }

    protected function registerBroadcastChannels(): void
    {
        if (! $this->app->runningInConsole() && $this->app->bound('Illuminate\Broadcasting\BroadcastManager')) {
            require __DIR__.'/../Routes/channels.php';
        }
    }

    protected function pdfGalleryFrontendConfig(): array
    {
        return [
            'maxFiles' => (int) config('pdf-gallery.gallery.max_files', 100),
            'maxUploadMb' => (int) config('pdf-gallery.gallery.max_upload_mb', 25),
            'mergeMaxFiles' => (int) config('pdf-gallery.merge.max_files', 50),
            'qrCodeEnabled' => (bool) config('pdf-gallery.qr_code.enabled', true),
            'convertEnabled' => (bool) config('pdf-gallery.convert.enabled', false),
            'protectedFilenames' => array_values(array_filter(array_map(
                static fn ($name) => basename((string) $name),
                (array) config('pdf-gallery.gallery.protected_filenames', []),
            ))),
            'title' => (string) config('pdf-gallery.ui.title', 'Galeria de PDF'),
            'documentSingular' => (string) config('pdf-gallery.ui.document_singular', 'documento'),
            'documentPlural' => (string) config('pdf-gallery.ui.document_plural', 'documentos'),
        ];
    }

    protected function shareInertiaConfig(): void
    {
        if (! class_exists(Inertia::class)) {
            return;
        }

        Inertia::share([
            'pdfGallery' => fn (): array => $this->pdfGalleryFrontendConfig(),
        ]);
    }

    protected function shareNovaConfig(): void
    {
        if ($this->app->runningInConsole() || ! class_exists(Nova::class)) {
            return;
        }

        Nova::provideToScript([
            'pdfGallery' => fn (): array => $this->pdfGalleryFrontendConfig(),
        ]);
    }
}
