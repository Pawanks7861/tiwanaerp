<?php

/**
 * Firebase Cloud Messaging. The service-account file is a secret and stays outside git.
 * The web keys are the public Firebase web app config used only to obtain a browser token.
 */
return [

    'project_id' => env('FCM_PROJECT_ID'),

    'credentials' => env('FCM_CREDENTIALS'),

    'web' => [
        'api_key' => env('FCM_WEB_API_KEY'),
        'auth_domain' => env('FCM_WEB_AUTH_DOMAIN'),
        'messaging_sender_id' => env('FCM_MESSAGING_SENDER_ID'),
        'app_id' => env('FCM_WEB_APP_ID'),
        'vapid_key' => env('FCM_WEB_VAPID_KEY'),
    ],

];
