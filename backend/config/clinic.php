<?php

return [
    'auto_approve_signups' => env('CLINIC_AUTO_APPROVE_SIGNUPS', true),
    'documents_disk' => env('CLINIC_DOCUMENTS_DISK', 'local'),
    'antivirus_enabled' => env('CLINIC_ANTIVIRUS_ENABLED', false),
    'ai_provider' => env('CLINIC_AI_PROVIDER', 'fake'), // fake|anthropic
    'impersonation_minutes' => 60,
    'notification_drivers' => [
        'whatsapp' => env('NOTIFY_WHATSAPP_DRIVER', 'log'), // log|whatsapp_cloud
        'sms' => env('NOTIFY_SMS_DRIVER', 'log'),           // log|msg91
        'email' => env('NOTIFY_EMAIL_DRIVER', 'log'),       // log|mail
    ],
];
