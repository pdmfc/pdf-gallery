<?php

return [
    'storage' => [
        'disk' => env('PDF_GALLERY_DISK', 'public'),
        'path' => env('PDF_GALLERY_STORAGE_PATH', 'pdfs/tmp'),
        'directory_resolver' => null,
    ],

    'routes' => [
        'prefix' => env('PDF_GALLERY_ROUTES_PREFIX', 'api'),
        'callback_path' => env('PDF_GALLERY_CALLBACK_PATH'),
        'browser_middleware' => ['web'],
        'callback_middleware' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PDF_GALLERY_CALLBACK_MIDDLEWARE', 'api'))
        ))),
    ],

    'name' => 'Galeria de PDF',

    'ui' => [
        'title' => 'Galeria de PDF',
        'document_singular' => 'documento',
        'document_plural' => 'documentos',
    ],

    'demo_routes' => env('PDF_GALLERY_DEMO_ROUTES', false),

    'qr_code' => [
        'enabled' => filter_var(env('PDF_GALLERY_QR_CODE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'api_url' => env('PDF_GALLERY_QRCODE_URL', env('QRCODE_URL')),
        'api_bearer_token' => env('PDF_GALLERY_QRCODE_API_TOKEN', env('QRCODE_API_TOKEN')),
        'delivery_mode' => env('PDF_GALLERY_QRCODE_DELIVERY_MODE', env('QRCODE_DELIVERY_MODE', 'callback_base64')),
        'callback_url' => env('PDF_GALLERY_QRCODE_CALLBACK_URL'),
        'callback_scope_param' => env('PDF_GALLERY_CALLBACK_SCOPE_PARAM', 'draftId'),
        'callback_scope_constraint' => env('PDF_GALLERY_CALLBACK_SCOPE_CONSTRAINT'),
    ],

    'broadcasting' => [
        'enabled' => filter_var(env('PDF_GALLERY_BROADCASTING', true), FILTER_VALIDATE_BOOL),
        'session_user_id_key' => 'pdf_gallery_broadcast_user_id',
        'authorize' => null,
    ],

    'authorization' => [
        'enforce_user_ownership' => env('PDF_GALLERY_ENFORCE_USER_OWNERSHIP', true),
        'authorize' => null,
        'session_user_id_key' => 'pdf_gallery_user_id',
        'denied_message' => 'Não autorizado a aceder a esta galeria de PDF.',
        'denied_status' => 403,
    ],

    'gallery' => [
        'max_files' => max(0, (int) env('PDF_GALLERY_MAX_FILES', 100)),
        'max_upload_mb' => max(1, (int) env('PDF_GALLERY_MAX_UPLOAD_MB', 25)),
    ],

    'documents' => [
        'enricher' => null,
        'filter' => null,
        'delete_handler' => null,
    ],

    'merge' => [
        'max_files' => max(2, (int) env('PDF_GALLERY_MERGE_MAX_FILES', 50)),
        'max_total_mb' => max(1, (int) env('PDF_GALLERY_MERGE_MAX_TOTAL_MB', 200)),
        'engines' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PDF_GALLERY_MERGE_ENGINES', 'qpdf,ghostscript,fpdi'))
        ))),

        // Kubernetes/OpenShift: defina caminhos absolutos (/usr/bin/qpdf, /usr/bin/gs).
        // Local (Mac/Linux): deixe vazio para detecção automática.
        'qpdf_binary' => env('PDF_GALLERY_QPDF_BINARY'),
        'ghostscript_binary' => env('PDF_GALLERY_GHOSTSCRIPT_BINARY'),

        // Pastas extra para detecção automática (dev local). No container basta PATH + /usr/bin.
        'binary_search_paths' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'PDF_GALLERY_BINARY_SEARCH_PATHS',
                '/usr/bin,/usr/local/bin,/opt/homebrew/bin,/bin'
            ))
        ))),
    ],

    'convert' => [
        'enabled' => filter_var(env('PDF_GALLERY_CONVERT_ENABLED', false), FILTER_VALIDATE_BOOL),
        'timeout_seconds' => max(10, (int) env('PDF_GALLERY_CONVERT_TIMEOUT', 120)),
        'engines' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PDF_GALLERY_CONVERT_ENGINES', 'fpdf,ghostscript,libreoffice,gotenberg'))
        ))),
        'image_engines' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PDF_GALLERY_CONVERT_IMAGE_ENGINES', 'fpdf,ghostscript'))
        ))),
        'word_engines' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PDF_GALLERY_CONVERT_WORD_ENGINES', 'libreoffice,gotenberg'))
        ))),
        'libreoffice_binary' => env('PDF_GALLERY_LIBREOFFICE_BINARY'),
        'gotenberg_url' => env('PDF_GALLERY_GOTENBERG_URL'),
        'defer_conversion' => filter_var(env('PDF_GALLERY_CONVERT_DEFER', true), FILTER_VALIDATE_BOOL),
    ],
];
