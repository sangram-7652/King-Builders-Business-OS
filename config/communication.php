<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Communication engine (M16)
| ---------------------------------------------------------------------------
| Provider-agnostic WhatsApp / SMS / Email. The default provider for every
| channel is `log` — it records the message and never makes an external call,
| so nothing is sent for real until a provider is deliberately configured.
| Credentials are read from env and MUST NOT be committed.
*/

return [
    'channels' => [
        'email' => [
            'provider' => env('COMM_EMAIL_PROVIDER', 'log'), // log | mail
            'from' => [
                'address' => env('COMM_EMAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
                'name' => env('COMM_EMAIL_FROM_NAME', env('MAIL_FROM_NAME')),
            ],
        ],
        'sms' => [
            'provider' => env('COMM_SMS_PROVIDER', 'log'),
            'from' => env('COMM_SMS_FROM'),
        ],
        'whatsapp' => [
            'provider' => env('COMM_WHATSAPP_PROVIDER', 'log'),
            'from' => env('COMM_WHATSAPP_FROM'),
        ],
    ],

    // The queue the delivery job runs on. Uses the app's existing Redis queue.
    'queue' => env('COMM_QUEUE', 'default'),

    // Retry policy for SendCommunicationJob (transient failures only).
    'retry' => [
        'tries' => 5,
        'backoff' => [10, 30, 120, 300, 900],
    ],

    // A dedicated log channel for provider traffic, or null for the default.
    'log_channel' => env('COMM_LOG_CHANNEL'),

    // Provider webhook shared secrets (M16.4) — env only, never committed.
    'webhooks' => [
        'email' => ['secret' => env('COMM_EMAIL_WEBHOOK_SECRET')],
        'sms' => ['secret' => env('COMM_SMS_WEBHOOK_SECRET')],
        'whatsapp' => ['secret' => env('COMM_WHATSAPP_WEBHOOK_SECRET')],
    ],
];
