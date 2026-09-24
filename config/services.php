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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'base_url' => env('TERMII_BASE_URL'),
        'email_configuration_id' => env('TERMII_EMAIL_CONFIGURATION_ID'),
        'registration_template' => env('TERMII_REGISTRATION_TEMPLATE_ID'),
        'forget_template' => env('TERMII_FORGET_PASSWORD_TEMPLATE_ID'),
        'resendotp_template' => env('TERMII_RESENDOTP_TEMPLATE_ID'),
    ],

     'nomba' => [
        'client_id'     => env('NOMBA_CLIENT_ID'),
        'client_secret' => env('NOMBA_CLIENT_SECRET'),
        'account_id'    => env('NOMBA_ACCOUNT_ID'),
        'base_url'      => env('NOMBA_BASE_URL'),
        'webhook_secret' => env('NOMBA_WEBHOOK_SECRET'),
    ],
];
