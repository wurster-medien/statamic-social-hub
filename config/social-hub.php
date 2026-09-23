<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verbindung zum Social Hub
    |--------------------------------------------------------------------------
    |
    | Adresse des Hubs, API-Key dieser Seite und das Secret, mit dem der Hub
    | Webhooks signiert. Die Werte gehören in die .env der Seite und werden
    | nie ausgegeben oder geloggt.
    |
    */

    'url' => env('SOCIAL_HUB_URL'),

    'key' => env('SOCIAL_HUB_KEY'),

    'webhook_secret' => env('SOCIAL_HUB_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Standardkonto
    |--------------------------------------------------------------------------
    |
    | Handle, das der Tag verwendet, wenn kein account="…" angegeben ist.
    | Leer = erstes Konto, das der Hub für diese Seite liefert.
    |
    */

    'default_account' => env('SOCIAL_HUB_DEFAULT_ACCOUNT'),

    /*
    |--------------------------------------------------------------------------
    | Cache und Ausfallsicherheit
    |--------------------------------------------------------------------------
    |
    | cache_minutes: so lange wird ein Feed aus dem Laravel-Cache geliefert.
    | stale_days:    so lange wird der letzte gute Feed ausgeliefert, wenn der
    |                Hub nicht erreichbar ist. Danach bleibt der Feed leer.
    | retry_minutes: nach einem Fehler wird der Hub frühestens nach dieser
    |                Zeit erneut gefragt (schützt die Seite vor Wartezeiten).
    |
    */

    'cache_minutes' => 30,

    'stale_days' => 7,

    'retry_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'timeout' => 5,

    /*
    |--------------------------------------------------------------------------
    | Feed und Medien
    |--------------------------------------------------------------------------
    |
    | fetch_limit:   so viele Medien werden pro Konto beim Hub abgerufen und
    |                gespiegelt. Der Tag schneidet davon "limit" ab.
    | default_limit: Anzahl Medien, wenn im Tag kein limit angegeben ist.
    | media_path:    Ordner unter public/, in den die Medien gespiegelt werden.
    |                Die Templates erhalten Pfade wie /social-hub/{handle}/…,
    |                die Glide lokal verarbeiten kann.
    | mirror_budget_seconds: Zeitbudget für das Laden von Medien während eines
    |                Seitenaufrufs (nur ohne Cron relevant).
    | max_download_mb: größere Dateien (z. B. lange Videos) werden nicht
    |                gespiegelt, sondern weiter vom Hub ausgeliefert.
    |
    */

    'fetch_limit' => 50,

    'default_limit' => 12,

    'media_path' => 'social-hub',

    'mirror_budget_seconds' => 8,

    'max_download_mb' => 25,

    /*
    |--------------------------------------------------------------------------
    | Lokaler Speicher
    |--------------------------------------------------------------------------
    |
    | Hier liegen der letzte gute Feed je Konto, die Kontenliste und der
    | Sync-Status (JSON-Dateien).
    |
    */

    'storage_path' => storage_path('app/social-hub'),

    /*
    |--------------------------------------------------------------------------
    | Zeitplan
    |--------------------------------------------------------------------------
    |
    | Bei true wird "php please social-hub:sync" alle 30 Minuten über den
    | Laravel-Scheduler ausgeführt (setzt einen Cron für schedule:run voraus).
    |
    */

    'schedule' => env('SOCIAL_HUB_SCHEDULE', true),

    /*
    |--------------------------------------------------------------------------
    | Posten aus Statamic (Phase 2)
    |--------------------------------------------------------------------------
    |
    | publish_accounts: zusätzliche Handles für die Kanalauswahl im Fieldset,
    |                   falls der Hub gerade nicht erreichbar ist.
    | teaser_fields:    Felder, aus denen der Standardtext (Titel + Teaser)
    |                   gebaut wird, wenn kein eigener Text eingetragen ist.
    | webhook_tolerance: maximales Alter eines Webhooks in Sekunden.
    |
    */

    'publish_accounts' => [],

    'teaser_fields' => ['teaser', 'excerpt', 'description', 'beschreibung', 'intro'],

    'webhook_tolerance' => 300,

];
