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

### Verbinden (ohne .env)

Im Hub bei der Kundenseite „API-Key erzeugen“ klicken. Der Hub zeigt einmal einen **Verbindungscode** (`shc1.…`). Ihn im Control Panel unter **Tools → Social Hub** einfügen und auf „Verbinden“ klicken. Das Addon prüft den Code per Ping, speichert Hub-Adresse, Key und Webhook-Secret verschlüsselt (`APP_KEY`) in `storage/app/social-hub/connection.json` und lädt gleich die Beiträge. Nötig ist das Recht „Mit dem Social Hub verbinden“ (`connect social hub`).

Stehen `SOCIAL_HUB_URL`/`SOCIAL_HUB_KEY` in der `.env`, haben sie Vorrang, und das Feld wird nicht angezeigt. Ein Code ohne Key (nach „Webhook-Secret erzeugen“ im Hub) behält den gespeicherten Key und ersetzt nur das Secret.

### .env (Alternative)

```dotenv
SOCIAL_HUB_URL=https://hub.wurster-medien.de
SOCIAL_HUB_KEY=…                    # API-Key der Seite (php artisan hub:site-key im Hub)
SOCIAL_HUB_WEBHOOK_SECRET=…         # nur für Phase 2 (Status-Rückmeldung)
SOCIAL_HUB_DEFAULT_ACCOUNT=         # optional: Handle für Tags ohne account="…"
SOCIAL_HUB_SCHEDULE=true            # Sync alle 30 Minuten über den Scheduler
SOCIAL_HUB_POST_TIMEOUT=120         # optional: Sekunden für „An Social Hub senden“
```

Der Scheduler braucht den üblichen Cron (`* * * * * php artisan schedule:run`). Ohne Cron lädt die Seite den Feed beim ersten Aufruf nach Ablauf des Caches (mit Zeitbudget für Medien).

## Tags

```antlers
{{ social:feed account="rath_bau" limit="12" }}
    <a href="{{ permalink }}">
        <img src="{{ glide :src="thumbnail_url ?? media_url" width="600" }}" alt="{{ alt }}">
    </a>
    <p>{{ caption_html }}</p>
    {{ if children }}{{ children }}{{ media_url }}{{ /children }}{{ /if }}
{{ /social:feed }}
```

Caption gekürzt oder in einem Attribut (z. B. Lightbox):

```antlers
<a href="{{ media_url }}" data-description="{{ caption | truncate(300, ' …') | entities }}">…</a>
```

| Parameter | Bedeutung |
|---|---|
| `account` (oder `handle`) | Handle des Kontos, wie im Hub für diese Seite vergeben. Ohne Angabe: `SOCIAL_HUB_DEFAULT_ACCOUNT` bzw. erstes Konto |
| `limit` | Anzahl Medien (Standard 12, höchstens `fetch_limit` = 50) |
| `offset` | Medien überspringen |
| `as` | Liste als Variable statt Schleife: `{{ social:feed as="posts" }}{{ posts }}…{{ /posts }}{{ /social:feed }}` |

Felder je Medium (wie bei der Meta-API): `id`, `media_type` (`IMAGE`, `VIDEO`, `CAROUSEL_ALBUM`), `media_product_type`, `media_url`, `thumbnail_url` (nur bei Videos), `permalink`, `caption`, `timestamp`, `like_count`, `comments_count`, `width`, `height`, `children`.
Zusätzlich: `alt` (Caption ohne Hashtags, max. 125 Zeichen, bereits HTML-escaped), `caption_html` (Caption HTML-escaped, Zeilenumbrüche als `<br>`), `is_video`, `date` (Carbon, z. B. `{{ date format="d.m.Y" }}`), `account`, `extension`.

**Sicherheit:** Antlers escaped Variablen nicht automatisch, und die Texte stammen aus fremden Social-Media-Konten. Deshalb:

- `alt` und `caption_html` sind bereits escaped und können direkt ausgegeben werden (`alt="{{ alt }}"`, `{{ caption_html }}`). Ein zusätzliches `| entities` schadet nicht (keine doppelte Kodierung).
- `caption` ist der Rohtext (für eigene Weiterverarbeitung) und muss im Template immer mit `{{ caption | entities }}` ausgegeben werden – im Elementinhalt wie in Attributen. Ohne Escaping können Anführungszeichen das HTML zerstören und Beiträge Skripte in die Seite schleusen. Achtung bei Lightboxen, die ein Attribut wie `data-description` als HTML einfügen: dort nur `caption | entities` oder `caption_html` verwenden.

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

**Tools → Social Hub**: Verbindung (Ping, Verbindungscode einfügen, Verbindung trennen), Konten mit Status und letztem Abruf (Hub und lokal), letzte Fehler, Knopf „Jetzt synchronisieren“.
Berechtigungen: „Social Hub ansehen“ (`view social hub`), „Social Hub verwalten“ (`manage social hub`, nötig für Sync und Senden) und „Mit dem Social Hub verbinden“ (`connect social hub`).

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

Der Hub lädt beim Anlegen die Bilder synchron. Für das Senden gilt deshalb ein eigenes Timeout (`post_timeout`, Standard 120 s, `.env`: `SOCIAL_HUB_POST_TIMEOUT`); Feeds bleiben beim kurzen `timeout` (5 s). Antwortet der Hub nicht rechtzeitig, meldet die Aktion: „Der Hub antwortet nicht rechtzeitig – der Post wurde evtl. trotzdem angelegt …“. Es wird **nicht** automatisch erneut gesendet. Nach ein paar Minuten die Aktion erneut ausführen: Sie aktualisiert denselben Post und zeigt den aktuellen Status. Hinweis: Der Webserver (z. B. `fastcgi_read_timeout` bei nginx, Standard 60 s) muss so lange Anfragen zulassen; `max_execution_time` hebt das Addon beim Senden selbst an.

Webhooks: Ungültige oder zu alte Signatur (älter als `webhook_tolerance`, 300 s) → `401`. Jede angenommene Signatur wird für die Dauer der Toleranz im Cache gemerkt; eine Wiederholung derselben Zustellung (Replay) wird mit `200` und `{"ok": true, "duplicate": true}` beantwortet und **ohne Wirkung** verworfen. Bewusst kein `409`: Der Hub signiert jeden Zustellversuch neu, ein Duplikat ist also immer schon verarbeitet, und ein Fehlerstatus würde nur unnötige Wiederholungen oder Alarme auslösen.

## Speicherorte

- Medien: `public/social-hub/{handle}/{id}.{ext}` (Disk `social-hub`)
- letzter guter Feed, Konten, Status: `storage/app/social-hub/*.json`
- `public/social-hub` gehört nicht ins Git der Seite (`.gitignore`).

Medien spiegeln:

- Downloads laufen per Stream in eine temporäre Datei, nie komplett in den Speicher. Dateien über `max_download_mb` (Standard 25) werden abgebrochen (angekündigte Größe per `Content-Length`, sonst während des Downloads) und weiter vom Hub ausgeliefert.
- Beim Seitenaufruf (Feed-Cache abgelaufen, kein Cron) werden nur Bilder und Vorschaubilder innerhalb des Zeitbudgets (`mirror_budget_seconds`) gespiegelt, **keine Videos**; diese kommen bis zum nächsten Sync vom Hub. Videos lädt `php please social-hub:sync` bzw. der Knopf im Control Panel.
- `media_path` muss ein eigener Unterordner unter `public/` sein. Ist er leer, `/` oder enthält `..` (oder zeigt eine eigene Disk `social-hub` auf `public/`, `storage/app` o. ä.), werden Medien weder gespiegelt noch aufgeräumt; im Log steht eine Warnung.
- Aufräumen löscht nur Dateien nach dem eigenen Namensschema `{handle}/{id}.{ext}` bzw. `{handle}/{id}_thumb.{ext}` (Bild- und Videoendungen) direkt in einem Konto-Ordner. Andere Dateien auf der Disk bleiben unangetastet.

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Änderungen

### Unveröffentlicht

- **Medien:** Ein gespiegeltes Bild bzw. Vorschaubild wird neu geladen, wenn der Hub eine größere Breite meldet (z. B. nach besseren Vorschaubildern für Facebook-Videos). Scheitert das, bleibt die lokale Datei.

### 1.1.0

- **Verbindungscode:** Die Seite lässt sich im Control Panel mit einem Code aus dem Hub verbinden, ohne die `.env` zu bearbeiten. Die Zugangsdaten liegen verschlüsselt unter `storage/app/social-hub/connection.json`; Werte aus der `.env` haben Vorrang. Neues Recht `connect social hub`.

### Sicherheitsprüfung

- **Templates:** `alt` ist jetzt HTML-escaped (sicher in `alt="…"`), neues Feld `caption_html` (escaped, Zeilenumbrüche als `<br>`). `caption` bleibt roh – in Templates `{{ caption | entities }}` verwenden.
- **Medien:** Downloads per Stream in eine temporäre Datei mit Größenprüfung vor und während des Downloads (kein Speicherüberlauf mehr bei großen Videos). Beim Seitenaufruf werden keine Videos mehr gespiegelt.
- **Senden:** eigenes Timeout `post_timeout` (Standard 120 s) für `POST /api/v1/posts`, verständliche Meldung bei Timeout, kein automatisches erneutes Senden.
- **Aufräumen:** nur noch Dateien nach dem eigenen Namensschema; unsicherer `media_path` (leer, `/`, `..`) deaktiviert Spiegeln und Aufräumen mit Log-Warnung.
- **Webhooks:** Replay-Schutz – eine bereits angenommene Signatur wird innerhalb der Toleranz mit `200 {"duplicate": true}` ohne Wirkung beantwortet.
