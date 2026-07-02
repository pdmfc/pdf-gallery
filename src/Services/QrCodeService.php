<?php

namespace PDMFC\PdfGallery\Services;

use Illuminate\Support\Facades\Http;
use PDMFC\PdfGallery\Support\CallbackRoute;
use PDMFC\PdfGallery\Support\PdfStorage;

class QrCodeService
{
    public function __construct(
        protected PdfStorage $storage,
        protected PdfGalleryService $galleryService,
    ) {}

    public function fetchQrCode(string|int|null $userId): array
    {
        if ($userId === null || $userId === '') {
            return [
                'error' => 'ID do utilizador em falta.',
                'status' => 422,
            ];
        }

        $apiUrl = config('pdf-gallery.qr_code.api_url');
        $bearer = config('pdf-gallery.qr_code.api_bearer_token');
        $deliveryMode = (string) config('pdf-gallery.qr_code.delivery_mode', 'callback_base64');

        if (! $apiUrl || ! $bearer) {
            return [
                'error' => 'Serviço de QR Code não configurado (PDF_GALLERY_QRCODE_URL, PDF_GALLERY_QRCODE_API_TOKEN).',
                'status' => 500,
            ];
        }

        $remainingSlots = $this->galleryService->galleryRemainingSlots($userId);

        if ($remainingSlots !== null && $remainingSlots <= 0) {
            $max = (int) config('pdf-gallery.gallery.max_files', 100);

            return [
                'error' => "Limite da galeria atingido ({$max} PDFs). Elimine documentos para adicionar novos.",
                'status' => 422,
            ];
        }

        $payload = [
            'user_token' => $this->storage->sanitizeUserId($userId),
            'endpoint' => $this->storage->callbackUrl($userId),
            'delivery_mode' => $deliveryMode,
        ];

        if ($remainingSlots !== null && $remainingSlots > 0) {
            $payload['max_files'] = $remainingSlots;
        }

        $payload['max_upload_mb'] = (int) config('pdf-gallery.gallery.max_upload_mb', 25);

        $response = Http::withToken($bearer)->post($apiUrl, $payload);

        if (! $response->successful()) {
            return [
                'error' => 'Falha ao obter QR Code do serviço externo.',
                'status' => $response->status(),
            ];
        }

        return $this->normalizeQrResponse($response->body());
    }

    private function normalizeQrResponse(string $body): array
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return [
                'error' => 'Resposta vazia do serviço QR.',
                'status' => 502,
            ];
        }

        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            foreach (['image', 'qr', 'data', 'content', 'base64', 'qr_image'] as $key) {
                if (! empty($json[$key]) && is_string($json[$key])) {
                    $trimmed = trim($json[$key]);
                    break;
                }
            }
        }

        if (str_contains($trimmed, '<svg')) {
            return ['svg' => $trimmed, 'status' => 200];
        }

        if (preg_match('#^data:image/[\w+.-]+;base64,#i', $trimmed)) {
            return ['qr_image' => $trimmed, 'status' => 200];
        }

        $b64 = preg_replace('#\s#', '', $trimmed);

        return [
            'qr_image' => 'data:image/png;base64,'.$b64,
            'status' => 200,
        ];
    }
}
