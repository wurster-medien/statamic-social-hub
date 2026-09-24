# Social Hub für Statamic

Statamic-Addon (Statamic 5 und 6, PHP 8.2+) für den Social Hub von Wurster Medien.

- liefert Instagram- und Facebook-Feeds aus dem Hub an Antlers-Templates,
- spiegelt die Bilder und Videos lokal nach `public/social-hub/…` (Glide-tauglich, kein Meta-CDN im Browser),
- liefert bei einem Hub-Ausfall bis zu 7 Tage den letzten guten Feed,
- sendet Einträge auf Knopfdruck als Post an den Hub und nimmt Status-Webhooks entgegen.

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
SOCIAL_HUB_WEBHOOK_SECRET=…         # für Webhooks (Feed-Änderungen, Post-Status)
SOCIAL_HUB_DEFAULT_ACCOUNT=         # optional: Handle für Tags ohne account="…"
SOCIAL_HUB_SCHEDULE=true            # Sync alle 30 Minuten über den Scheduler
SOCIAL_HUB_POST_TIMEOUT=120         # optional: Sekunden für „An Social Hub senden“
```

Der Scheduler braucht den üblichen Cron (`* * * * * php artisan schedule:run`). Ohne Cron lädt die Seite den Feed beim ersten Aufruf nach Ablauf des Caches (mit Zeitbudget für Medien).

**Aktualität:** Der Hub prüft die Konten jede Minute auf neue, gelöschte oder bearbeitete Beiträge. Ändert sich ein Feed, schickt er den Webhook `feed.updated` an `POST /!/social-hub/webhook`. Das Addon antwortet sofort und lädt den Feed danach neu, samt Spiegeln der Medien (auch Videos). Neue Beiträge sind so nach ein bis zwei Minuten auf der Seite. Dafür muss das Webhook-Secret gesetzt sein (kommt mit dem Verbindungscode). Die Cache-Zeit (`cache_minutes`, 30 Minuten) bleibt der Rückfall, falls der Webhook die Seite nicht erreicht, und gilt für Like-Zahlen. Nutzt die Seite Statamics Static Caching, bleiben ganze Seiten bis zur Invalidierung im Cache; den Feed dort per `nocache` einbinden.

## Tags

```antlers
{{ social:feed account="muster_bau" limit="12" }}
    <a href="{{ permalink }}">
        <img src="{{ glide :src="thumbnail_url ?? media_url" width="600" }}" alt="{{ alt }}">
    </a>
    <p>{{ caption_html }}</p>
    {{ if children }}{{ children }}{{ media_url }}{{ /children }}{{ /if }}
{{ /social:feed }}
```

Caption gekürzt (erst den Rohtext kürzen, dann escapen, damit keine Entity zerschnitten wird):

```antlers
<a href="{{ media_url }}" data-description="{{ caption_raw | truncate(300, ' …') | entities }}">…</a>
```

| Parameter | Bedeutung |
|---|---|
| `account` (oder `handle`) | Handle des Kontos, wie im Hub für diese Seite vergeben. Ohne Angabe: `SOCIAL_HUB_DEFAULT_ACCOUNT` bzw. erstes Konto |
| `limit` | Anzahl Medien (Standard 12, höchstens `fetch_limit` = 50) |
| `offset` | Medien überspringen |
| `as` | Liste als Variable statt Schleife: `{{ social:feed as="posts" }}{{ posts }}…{{ /posts }}{{ /social:feed }}` |

Felder je Medium (wie bei der Meta-API): `id`, `media_type` (`IMAGE`, `VIDEO`, `CAROUSEL_ALBUM`), `media_product_type`, `media_url`, `thumbnail_url` (nur bei Videos), `permalink`, `caption`, `timestamp`, `like_count`, `comments_count`, `width`, `height`, `children`.
`caption` ist bereits HTML-escaped. Zusätzlich: `caption_raw` (Rohtext), `alt` (Caption ohne Hashtags, max. 125 Zeichen, HTML-escaped), `caption_html` (Caption HTML-escaped, Zeilenumbrüche als `<br>`), `is_video`, `date` (Carbon, z. B. `{{ date format="d.m.Y" }}`), `account`, `extension`.

**Sicherheit:** Antlers escaped Variablen nicht automatisch, und die Texte stammen aus fremden Social-Media-Konten. Deshalb:

- `caption`, `alt` und `caption_html` sind bereits escaped und können direkt ausgegeben werden, im Elementinhalt wie in Attributen (`alt="{{ alt }}"`, `title="{{ caption }}"`, `{{ caption_html }}`). Ein zusätzliches `| entities` schadet nicht (keine doppelte Kodierung).
- `caption_raw` ist der Rohtext für eigene Weiterverarbeitung (z. B. Kürzen) und muss vor der Ausgabe mit `| entities` escaped werden. Ohne Escaping können Anführungszeichen das HTML zerstören und Beiträge Skripte in die Seite schleusen.

Leerer Feed oder Hub-Fehler: `{{ if no_results }}…{{ /if }}`. Fehler erscheinen nie im Template, sondern im Log und im Control Panel.

Weitere Tags:

```antlers
{{ social:media account="muster_bau" id="17912345678901234" }} {{ media_url }} {{ /social:media }}
{{ social:accounts }} {{ handle }} – {{ platform }} – {{ username }} {{ /social:accounts }}
```

Hinweis: Viele Templates haben eine Variable `social` (z. B. Profil-Links). `{{ social }}` ohne Methode und unbekannte Methoden wie `{{ social:label }}` geben deshalb absichtlich nichts aus, falls die Variable einmal fehlt.

## Befehle

```bash
php please social-hub:sync                    # Ping, Konten, alle Feeds, Medien spiegeln, aufräumen
php please social-hub:sync --account=muster_bau # nur ein Konto (ohne Aufräumen)
```

## Control Panel

**Tools → Social Hub**: Verbindung (Ping, Verbindungscode einfügen, Verbindung trennen), Konten mit Status und letztem Abruf (Hub und lokal), letzte Fehler, Knopf „Jetzt synchronisieren“.
Berechtigungen: „Social Hub ansehen“ (`view social hub`), „Social Hub verwalten“ (`manage social hub`, nötig für Sync und Senden) und „Mit dem Social Hub verbinden“ (`connect social hub`).

## Posten aus Statamic

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
- Geladen wird nur vom Host des Hubs. Medien-URLs auf andere Hosts bleiben unverändert stehen und erscheinen als Fehler im Control Panel. Liefert der Hub Medien über einen anderen Host aus (z. B. ein CDN), diesen in `media_hosts` eintragen.
- `media_path` muss ein eigener Unterordner unter `public/` sein. Ist er leer, `/` oder enthält `..` (oder zeigt eine eigene Disk `social-hub` auf `public/`, `storage/app` o. ä.), werden Medien weder gespiegelt noch aufgeräumt; im Log steht eine Warnung.
- Aufräumen löscht nur Dateien nach dem eigenen Namensschema `{handle}/{id}.{ext}` bzw. `{handle}/{id}_thumb.{ext}` (Bild- und Videoendungen) direkt in einem Konto-Ordner. Andere Dateien auf der Disk bleiben unangetastet.

## Sicherheitslücken melden

Bitte nicht als öffentliches Issue, sondern wie in [SECURITY.md](SECURITY.md) beschrieben.

## Tests

```bash
composer install
vendor/bin/phpunit
```
