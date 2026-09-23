# Social Hub für Statamic

Statamic-Addon (Statamic 5 und 6, PHP 8.2+) für den Social Hub von Wurster Medien.

- liefert Instagram- und Facebook-Feeds aus dem Hub an Antlers-Templates,
- spiegelt die Bilder und Videos lokal nach `public/social-hub/…` (Glide-tauglich, kein Meta-CDN im Browser),
- liefert bei einem Hub-Ausfall bis zu 7 Tage den letzten guten Feed,
- sendet Einträge auf Knopfdruck als Post an den Hub (Phase 2) und nimmt Status-Webhooks entgegen.

## Installation

```bash
composer require wurster-medien/statamic-social-hub
```

Für die lokale Entwicklung als Path-Repository in der `composer.json` der Seite:

```json
"repositories": [
    { "type": "path", "url": "../statamic-social-hub", "options": { "symlink": true } }
]
```

Config veröffentlichen (optional):

```bash
php artisan vendor:publish --tag=social-hub-config
```

### .env

```dotenv
SOCIAL_HUB_URL=https://hub.wurster-medien.de
SOCIAL_HUB_KEY=…                    # API-Key der Seite (php artisan hub:site-key im Hub)
SOCIAL_HUB_WEBHOOK_SECRET=…         # nur für Phase 2 (Status-Rückmeldung)
SOCIAL_HUB_DEFAULT_ACCOUNT=         # optional: Handle für Tags ohne account="…"
SOCIAL_HUB_SCHEDULE=true            # Sync alle 30 Minuten über den Scheduler
```

Der Scheduler braucht den üblichen Cron (`* * * * * php artisan schedule:run`). Ohne Cron lädt die Seite den Feed beim ersten Aufruf nach Ablauf des Caches (mit Zeitbudget für Medien).

## Tags

```antlers
{{ social:feed account="rath_bau" limit="12" }}
    <a href="{{ permalink }}">
        <img src="{{ glide :src="thumbnail_url ?? media_url" width="600" }}" alt="{{ alt }}">
    </a>
    {{ if children }}{{ children }}{{ media_url }}{{ /children }}{{ /if }}
{{ /social:feed }}
```

| Parameter | Bedeutung |
|---|---|
| `account` (oder `handle`) | Handle des Kontos, wie im Hub für diese Seite vergeben. Ohne Angabe: `SOCIAL_HUB_DEFAULT_ACCOUNT` bzw. erstes Konto |
| `limit` | Anzahl Medien (Standard 12, höchstens `fetch_limit` = 50) |
| `offset` | Medien überspringen |
| `as` | Liste als Variable statt Schleife: `{{ social:feed as="posts" }}{{ posts }}…{{ /posts }}{{ /social:feed }}` |

Felder je Medium (wie bei der Meta-API): `id`, `media_type` (`IMAGE`, `VIDEO`, `CAROUSEL_ALBUM`), `media_product_type`, `media_url`, `thumbnail_url` (nur bei Videos), `permalink`, `caption`, `timestamp`, `like_count`, `comments_count`, `width`, `height`, `children`.
Zusätzlich: `alt` (Caption ohne Hashtags, max. 125 Zeichen), `is_video`, `date` (Carbon, z. B. `{{ date format="d.m.Y" }}`), `account`, `extension`.

Leerer Feed oder Hub-Fehler: `{{ if no_results }}…{{ /if }}`. Fehler erscheinen nie im Template, sondern im Log und im Control Panel.

Weitere Tags:

```antlers
{{ social:media account="rath_bau" id="17912345678901234" }} {{ media_url }} {{ /social:media }}
{{ social:accounts }} {{ handle }} – {{ platform }} – {{ username }} {{ /social:accounts }}
```

Hinweis: Viele Templates haben eine Variable `social` (z. B. Profil-Links). `{{ social }}` ohne Methode und unbekannte Methoden wie `{{ social:label }}` geben deshalb absichtlich nichts aus, falls die Variable einmal fehlt.

## Umstieg vom alten Instagram-Tag

Nur der Tag-Name ändert sich, die Felder bleiben gleich:

| alt | neu |
|---|---|
| `{{ instagram:feed limit="12" handle="kabelmat" }}` | `{{ social:feed limit="12" handle="kabelmat" }}` |
| `{{ /instagram:feed }}` | `{{ /social:feed }}` |
| `{{ instagram:feed limit="32" as="posts" }}` | `{{ social:feed limit="32" as="posts" }}` |

Das Handle ist das, das im Hub der Seite zugewiesen ist (`site_social_account.handle`). Am einfachsten dort dieselben Handles wie bisher vergeben.

Danach:

1. `php please social-hub:sync` ausführen und im Control Panel unter **Tools → Social Hub** prüfen.
2. Altes Addon per Composer entfernen, dessen Config und `.env`-Einträge löschen.
3. Datenschutzerklärung anpassen: Bilder werden lokal ausgeliefert, nicht mehr vom Meta-CDN.

## Befehle

```bash
php please social-hub:sync                    # Ping, Konten, alle Feeds, Medien spiegeln, aufräumen
php please social-hub:sync --account=rath_bau # nur ein Konto (ohne Aufräumen)
```

## Control Panel

**Tools → Social Hub**: Verbindung (Ping), Konten mit Status und letztem Abruf (Hub und lokal), letzte Fehler, Knopf „Jetzt synchronisieren“.
Berechtigungen: „Social Hub ansehen“ (`view social hub`) und „Social Hub verwalten“ (`manage social hub`, nötig für Sync und Senden).

## Posten aus Statamic (Phase 2)

1. Fieldset in den Blueprint importieren (Tab „Social Media“):

   ```yaml
   -
     import: social-hub::social_hub
   ```

2. Im Eintrag Kanäle, Text, Bilder und Zeitpunkt ausfüllen und **speichern**.
3. Im Drei-Punkte-Menü des Eintrags **„An Social Hub senden“** wählen. Beim Speichern wird nie automatisch gesendet.
4. Der Hub meldet Status und Permalinks per Webhook an `POST /!/social-hub/webhook` zurück (signiert mit `SOCIAL_HUB_WEBHOOK_SECRET`).

Erneutes Senden aktualisiert denselben Post im Hub (Referenz = Entry-ID), solange er dort noch nicht freigegeben bzw. veröffentlicht ist.

## Speicherorte

- Medien: `public/social-hub/{handle}/{id}.{ext}` (Disk `social-hub`)
- letzter guter Feed, Konten, Status: `storage/app/social-hub/*.json`
- `public/social-hub` gehört nicht ins Git der Seite (`.gitignore`).

## Tests

```bash
composer install
vendor/bin/phpunit
```
