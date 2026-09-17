<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services
    | such as Mailgun, Postmark, AWS and more.
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

    'bakong' => [
        'api_url' => env(
            'BAKONG_API_URL',
            'https://api-bakong.nbc.gov.kh'
        ),
        'api_token' => env('BAKONG_API_TOKEN'),
        'merchant_id' => env('BAKONG_MERCHANT_ID', 'merchant@devb'),
        'merchant_name' => env('BAKONG_MERCHANT_NAME', 'E-Commerce Store'),
        'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
        'currency' => env('BAKONG_CURRENCY', 'USD'),
    ],

    'payway' => [
        'merchant_id' => env('PAYWAY_MERCHANT_ID', ''),
        'api_key' => env('PAYWAY_API_KEY', ''),

        'purchase_url' => env(
            'PAYWAY_PURCHASE_URL',
            'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/purchase'
        ),

        'check_url' => env(
            'PAYWAY_CHECK_URL',
            'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/check-transaction-2'
        ),

        'return_url' => env('PAYWAY_RETURN_URL', ''),
        'callback_url' => env('PAYWAY_CALLBACK_URL', ''),
    ],

];