<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Steadfast Courier API Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration options are used to authenticate with the Steadfast
    | Courier API. You should obtain these credentials from the Steadfast
    | Courier service provider.
    |
    */

    'base_url' => env('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'),

    'api_key' => env('STEADFAST_API_KEY', 'your-api-key'),

    'secret_key' => env('STEADFAST_SECRET_KEY', 'your-secret-key'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Bearer Token
    |--------------------------------------------------------------------------
    |
    | Steadfast sends parcel status updates to your callback URL with this
    | token in the Authorization header. Leave it unset to disable webhook
    | handling: never compare an incoming token against an empty value, or
    | every request sending a bare "Bearer " header would be authenticated.
    |
    */

    'webhook_bearer_token' => env('STEADFAST_BEARER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Default Content Type
    |--------------------------------------------------------------------------
    |
    | This option specifies the default content type to be used in requests
    | made to the Steadfast Courier API. You may modify this value if
    | necessary, but it's recommended to keep it as 'application/json'.
    |
    */

    'content_type' => 'application/json',

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts
    |--------------------------------------------------------------------------
    |
    | How long to wait for Steadfast, in seconds. Without these a hung endpoint
    | would block the web request or queue worker indefinitely.
    |
    */

    'timeout' => env('STEADFAST_TIMEOUT', 30),

    'connect_timeout' => env('STEADFAST_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Total attempts for a read that fails to connect or gets a 5xx, and how
    | long to wait between them in milliseconds. A 4xx is never retried: a 401
    | gets the same answer every time, and ten in five minutes lock the keys
    | out for an hour. Writes are never retried automatically; Steadfast
    | refuses a duplicate invoice, so retry one yourself with the same invoice.
    |
    */

    'retry' => [
        'times' => env('STEADFAST_RETRY_TIMES', 2),
        'sleep' => env('STEADFAST_RETRY_SLEEP', 200),
    ],
];
