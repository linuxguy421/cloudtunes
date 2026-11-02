<?php

return [
    // Public registration is enabled by default; administrator privileges are
    // granted separately through the cloudtunes:make-admin command.
    'public_registration_enabled' => (bool) env('CLOUDTUNES_PUBLIC_REGISTRATION_ENABLED', true),

    // Icecast is queried from the application network; listeners use the public URL.
    'icecast_status_url' => env('ICECAST_STATUS_URL', 'http://icecast:8000/status-json.xsl'),
    'icecast_public_url' => env('ICECAST_PUBLIC_URL', 'http://localhost:8000'),
    'icecast_mount' => env('ICECAST_MOUNT', '/default0.ogg'),

    // Optional Last.fm API key for track artwork. The player works without it.
    'lastfm_api_key' => env('LASTFM_API_KEY'),
];
