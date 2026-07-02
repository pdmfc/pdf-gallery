<?php

namespace PDMFC\PdfGallery\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GalleryAccess
{
    public static function enforcementEnabled(): bool
    {
        return (bool) config('pdf-gallery.authorization.enforce_user_ownership', true);
    }

    public static function canAccessGallery(Request $request, string|int $requestedUserId): bool
    {
        if (! static::enforcementEnabled()) {
            return true;
        }

        $requestedUserId = (string) $requestedUserId;

        $authorize = config('pdf-gallery.authorization.authorize');

        if (is_callable($authorize)) {
            return (bool) $authorize($request->user(), $requestedUserId);
        }

        $sessionKey = (string) config(
            'pdf-gallery.authorization.session_user_id_key',
            'pdf_gallery_user_id'
        );

        if ($request->hasSession() && $request->session()->has($sessionKey)) {
            return (string) $request->session()->get($sessionKey) === $requestedUserId;
        }

        $user = $request->user();

        if ($user !== null) {
            $authId = $user->getAuthIdentifier();

            return $authId !== null && (string) $authId === $requestedUserId;
        }

        return false;
    }

    public static function denyGalleryAccessResponse(): Response
    {
        return response()->json([
            'error' => (string) config(
                'pdf-gallery.authorization.denied_message',
                'Não autorizado a aceder a esta galeria de PDF.'
            ),
        ], (int) config('pdf-gallery.authorization.denied_status', 403));
    }
}
