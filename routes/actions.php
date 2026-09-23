<?php

use Illuminate\Support\Facades\Route;
use WursterMedien\SocialHub\Http\Controllers\WebhookController;

/*
| Aktionsrouten unter /!/social-hub/… (Präfix = Addon-Slug).
| Der Webhook ist durch die HMAC-Signatur geschützt, nicht durch CSRF.
*/

Route::post('webhook', WebhookController::class)
    ->withoutMiddleware([
        'App\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    ])
    ->name('social-hub.webhook');
