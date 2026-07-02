<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('pdf-gallery.documents.{userId}', function ($user, string $userId): bool {
    $authorize = config('pdf-gallery.broadcasting.authorize');

    if (is_callable($authorize)) {
        return (bool) $authorize($user, $userId);
    }

    $sessionKey = (string) config(
        'pdf-gallery.broadcasting.session_user_id_key',
        'pdf_gallery_broadcast_user_id'
    );

    if (session()->has($sessionKey)) {
        return (string) session($sessionKey) === (string) $userId;
    }

    return $user !== null;
});
