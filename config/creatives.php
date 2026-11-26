<?php

return [
    // Where creative library files live: "azure" in production, "local" in development and tests.
    // Browsers upload and download straight from this disk, so file bytes never pass through PHP.
    'disk' => env('CREATIVE_LIBRARY_DISK', 'local'),

    // Storage a workspace may use for creatives, and the largest single file of each kind.
    'workspace_cap_mb' => (int) env('CREATIVE_LIBRARY_WORKSPACE_CAP_MB', 10240),
    'max_image_mb' => (int) env('CREATIVE_LIBRARY_MAX_IMAGE_MB', 50),
    'max_video_mb' => (int) env('CREATIVE_LIBRARY_MAX_VIDEO_MB', 1024),

    // How long a signed upload or view link works.
    'upload_url_minutes' => (int) env('CREATIVE_LIBRARY_UPLOAD_URL_MINUTES', 120),
    'view_url_minutes' => (int) env('CREATIVE_LIBRARY_VIEW_URL_MINUTES', 15),

    'types' => [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'video/mp4' => ['video', 'mp4'],
        'video/quicktime' => ['video', 'mov'],
        'video/webm' => ['video', 'webm'],
    ],
];
