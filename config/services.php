<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'keycloak' => [
        'client_id' => env('KEYCLOAK_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_CLIENT_SECRET'),
        'redirect' => env('KEYCLOAK_REDIRECT_URI'),
        'base_url' => env('KEYCLOAK_BASE_URL'),
        'realms' => env('KEYCLOAK_REALM'),
    ],

    'userdb' => [
        'areas_url' => env('USERDB_AREAS_URL', 'https://userdb.hkfree.org/userdb/api/areas'),
        'username' => env('USERDB_API_USERNAME'),
        'password' => env('USERDB_API_PASSWORD'),
    ],

    'confluence' => [
        // The only host the Confluence import talks to; pasted URLs are parsed for a page id only.
        'base_url' => rtrim((string) env('CONFLUENCE_BASE_URL', 'https://doc.hkfree.org'), '/'),
        'token' => env('CONFLUENCE_TOKEN'),
    ],

    'gallery' => [
        // Wall-clock timezone of timeline dates (EXIF dates carry no zone; others are converted).
        'timezone' => env('GALLERY_TIMEZONE', 'Europe/Prague'),
        // Optional self-hosted mirror of the scene recognition model files (Hugging Face layout).
        'scene_model_url' => env('GALLERY_SCENE_MODEL_URL'),
        // Map tiles for the small per-photo maps; OpenStreetMap by default (use a caching proxy
        // for heavy use, per the OSM tile usage policy).
        'map_tiles' => env('GALLERY_MAP_TILES', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'admin_roles' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('GALLERY_ADMIN_ROLES', 'SO,ZSO,PREDSTAVENSTVO,VV')))
        )),
    ],

];
