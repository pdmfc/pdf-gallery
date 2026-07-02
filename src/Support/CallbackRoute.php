<?php

namespace PDMFC\PdfGallery\Support;

use Illuminate\Http\Request;

class CallbackRoute
{
    public static function urlTemplate(): ?string
    {
        $url = config('pdf-gallery.qr_code.callback_url');

        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        return $url !== '' ? $url : null;
    }

    public static function resolvedUrl(string|int $scopeId): string
    {
        $scopeId = self::sanitizeScopeId($scopeId);
        $template = self::urlTemplate();

        if ($template !== null) {
            return self::substituteScopeId($template, $scopeId);
        }

        return route(
            'pdf-gallery.callback.files',
            [self::routeParameterName() => $scopeId],
            absolute: true
        );
    }

    public static function routePath(): string
    {
        $configured = config('pdf-gallery.routes.callback_path');

        if (is_string($configured) && trim($configured) !== '') {
            return ltrim(trim($configured), '/');
        }

        $template = self::urlTemplate();

        if ($template !== null) {
            $path = parse_url($template, PHP_URL_PATH);

            if (is_string($path) && $path !== '' && $path !== '/') {
                $path = ltrim($path, '/');

                if (! self::hasPlaceholder($path)) {
                    return rtrim($path, '/').'/'.self::placeholderForScopeParam();
                }

                return $path;
            }
        }

        return 'callback/upload-draft-attachments/'.self::placeholderForScopeParam();
    }

    public static function routeParameterName(): string
    {
        $configured = config('pdf-gallery.qr_code.callback_scope_param');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $placeholders = self::placeholders(self::routePath());

        if ($placeholders !== []) {
            return $placeholders[0];
        }

        return 'draftId';
    }

    public static function scopeKeyFromRequest(Request $request): string
    {
        $parameter = self::routeParameterName();
        $value = $request->route($parameter);

        if (! is_string($value) && ! is_numeric($value)) {
            throw new \InvalidArgumentException('Identificador em falta no callback.');
        }

        return self::sanitizeScopeId($value);
    }

    public static function isConfigured(): bool
    {
        return self::urlTemplate() !== null;
    }

    public static function hasPlaceholder(?string $template = null): bool
    {
        return self::placeholders($template) !== [];
    }

    /**
     * @return list<string>
     */
    public static function placeholders(?string $template = null): array
    {
        if ($template === null) {
            return [];
        }

        if (! preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $template, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    public static function sanitizeScopeId(string|int $scopeId): string
    {
        return UserId::sanitize($scopeId);
    }

    private static function placeholderForScopeParam(): string
    {
        return '{'.self::routeParameterName().'}';
    }

    private static function substituteScopeId(string $template, string $scopeId): string
    {
        if (self::hasPlaceholder($template)) {
            return (string) preg_replace('/\{[a-zA-Z][a-zA-Z0-9_]*\}/', $scopeId, $template);
        }

        return rtrim($template, '/').'/'.$scopeId;
    }
}
