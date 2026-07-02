<?php

namespace PDMFC\PdfGallery\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PDMFC\PdfGallery\Support\GalleryAccess;
use Symfony\Component\HttpFoundation\Response;

class EnsurePdfGalleryUserAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! GalleryAccess::enforcementEnabled()) {
            return $next($request);
        }

        $userId = $request->input('user_id') ?? $request->route('userId');

        if ($userId === null || $userId === '') {
            return $next($request);
        }

        if (! GalleryAccess::canAccessGallery($request, $userId)) {
            return GalleryAccess::denyGalleryAccessResponse();
        }

        return $next($request);
    }
}
