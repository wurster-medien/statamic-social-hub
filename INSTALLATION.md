# Anleitung: Addon auf einer Kundenseite einrichten

Schritt für Schritt vom leeren Statamic-Projekt bis zum Feed auf der Seite. Einzelheiten zu Tags, Config und Speicherorten stehen im [README](README.md).

**Voraussetzungen**

- Statamic 5 (PHP 8.2+) oder Statamic 6 (PHP 8.3+)
- Zugang zum Hub-Admin (`/admin`) mit der Rolle Agentur-Admin oder -Redakteur
- Der Kunde ist im Hub angelegt und seine Konten (Instagram, Facebook) sind ihm zugeordnet (Hub → **Konten** → „Kunde zuordnen“).

---

## 1. Im Hub: Kundenseite anlegen

1. Hub → **Kundenseiten** → **Neu**.
2. Ausfüllen:
   - **Kunde**: lässt sich später nicht mehr ändern.
   - **Name**: z. B. „Muster Bau Website“.
   - **Domain**: ohne `https://`, z. B. `muster-bau.de`.
   - **Webhook-URL**: leer lassen. Der Hub nutzt dann `https://<domain>/!/social-hub/webhook`. Nur eintragen, wenn die Seite unter einer anderen Adresse läuft (nur `https`).
   - **Aktiv**: an.
3. Speichern.

## 2. Im Hub: Konten verknüpfen

Auf der Kundenseite unten bei **Konten** → **Konto verknüpfen**, je Konto einmal:

- **Konto**: Auswahl zeigt nur Konten dieses Kunden.
- **Handle**: So heißt das Konto im Template (`account="…"`). Vorgeschlagen wird der Instagram-Name bzw. der Seitenname. **Bei einem Umzug vom alten Instagram-Addon dasselbe Handle wie bisher im Template nehmen**, dann muss am Template nur der Tag-Name geändert werden.
- **Darf posten**: nur an, wenn der Kunde aus Statamic heraus posten soll (siehe Schritt 7).

## 3. Auf der Seite: Addon installieren

Das Paket liegt nicht auf Packagist, sondern auf GitHub. In der `composer.json` der Seite das Repository eintragen:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/wurster-medien/statamic-social-hub.git"
    }
]
```

Die `https`-Adresse funktioniert auf dem Server ohne Deploy-Key. Steht bereits ein `repositories`-Block in der Datei, den Eintrag dort ergänzen.

Dann installieren:

```bash
composer require wurster-medien/statamic-social-hub
```

In die `.gitignore` der Seite (hier landen die gespiegelten Bilder und Videos):

```gitignore
/public/social-hub
```

Ab Version 1.5.0 legt das Addon zusätzlich selbst eine `.gitignore` in `public/social-hub/` an. Die Zeile nicht mit `>>` in PowerShell anhängen: Das schreibt UTF-16, Git liest die Zeile dann nicht. Prüfen mit `git check-ignore -v public/social-hub/x.jpg`.

`composer.json`, `composer.lock` und `.gitignore` committen und die Seite wie gewohnt deployen.

> **Lokal entwickeln** mit dem Addon aus `D:\Websites\statamic-social-hub`: statt `vcs` ein Path-Repository nutzen, siehe [README → Installation](README.md#installation). Das nicht committen.

## 4. Verbinden

1. Im Hub auf der Kundenseite **API-Key erzeugen** klicken. Den Schalter „Webhook-Secret ebenfalls neu erzeugen“ anlassen, wenn die Seite noch keins hat (Standard).
2. Der Hub zeigt **einmal** den **Verbindungscode** (`shc1.…`). Kopieren.
3. Im Statamic-Control-Panel der Live-Seite: **Werkzeuge → Social Hub** → Code einfügen → **Verbinden**.

Das Addon prüft den Code, speichert Hub-Adresse, Key und Webhook-Secret verschlüsselt und lädt sofort die Beiträge. Die `.env` muss dafür niemand anfassen.

Hinweise:

- Den Code nicht per Mail oder Chat weitergeben. Wer ihn hat, kann die Feeds der Seite abrufen und (bei „Darf posten“) Posts anlegen. Geht er verloren: im Hub „API-Key erneuern“ und neu einfügen.
- Das Feld erscheint nur, wenn in der `.env` der Seite **kein** `SOCIAL_HUB_URL`/`SOCIAL_HUB_KEY` steht. Die `.env` hat immer Vorrang.
- Der Code gehört zu genau einer Umgebung. Lokal und Staging bekommen eigene Kundenseiten im Hub oder bleiben unverbunden (dann liefern die Tags einen leeren Feed).
- Andere Nutzer als Super-Admins brauchen dafür das Recht **„Mit dem Social Hub verbinden“** (Benutzer → Rollen → Gruppe „Social Hub“).

## 5. Cronjob (Ploi)

Das Addon gleicht alle 30 Minuten über den Laravel-Scheduler ab. Hat die Seite schon einen Cronjob mit `schedule:run`, ist nichts zu tun. Sonst in Ploi:

1. Server öffnen → links **Cronjobs**.
2. Befehl: `php /home/ploi/<domain>/artisan schedule:run`. Den genauen Pfad zeigt **Werkzeuge → Social Hub** unter „Automatischer Abruf“ zum Kopieren an.
3. Frequenz **Every minute** (`* * * * *`), Benutzer `ploi`, speichern.

Läuft die Seite mit einer anderen PHP-Version als der Server-Standard, `php8.x` statt `php` eintragen.

Ohne Cron funktioniert der Feed trotzdem, lädt aber beim Seitenaufruf nach und spiegelt dabei keine Videos.

## 6. Feed ins Template einbauen

```antlers
{{ social:feed account="muster_bau" limit="12" }}
    <a href="{{ permalink }}" target="_blank" rel="noopener">
        <img src="{{ glide :src="thumbnail_url ?? media_url" width="600" }}" alt="{{ alt }}">
    </a>
    {{ if no_results }}<p>Gerade keine Beiträge.</p>{{ /if }}
{{ /social:feed }}
```

- `account` ist das Handle aus Schritt 2.
- `caption`, `alt` und `caption_html` sind schon escaped. `caption_raw` immer mit `| entities` ausgeben.
- Nutzt die Seite **Static Caching**, den Feed in `{{ nocache }}…{{ /nocache }}` setzen, sonst erscheinen neue Beiträge erst nach der nächsten Invalidierung.

Alle Felder und Parameter: [README → Tags](README.md#tags).

### Umzug vom alten Instagram-Addon

1. Im Template `instagram:feed` durch `social:feed` ersetzen. Die Feldnamen bleiben gleich.
2. `caption` prüfen: Wird sie irgendwo gekürzt, `caption_raw | truncate(…) | entities` nehmen.
3. Seite ansehen und mit dem alten Stand vergleichen.
4. Datenschutzerklärung anpassen (Bilder kommen jetzt von der eigenen Domain, nicht mehr von Meta).
5. Erst danach das alte Addon mit Composer entfernen.

## 7. Optional: Posten aus Statamic

Nur wenn der Kunde Einträge als Post an den Hub schicken soll.

1. Im Hub beim Konto **Darf posten** einschalten (Schritt 2, „Bearbeiten“).
2. Das Fieldset in den Blueprint der Sammlung importieren, am besten in einem eigenen Tab „Social Media“:

   ```yaml
   -
     import: social-hub::social_hub
   ```

3. Redakteuren das Recht **„Social Hub verwalten“** geben.
4. Unter nginx `fastcgi_read_timeout` auf mindestens 120 Sekunden setzen (Ploi: Seite → **Nginx-Konfiguration**). Der Hub lädt beim Senden die Bilder, das dauert länger als die üblichen 60 Sekunden.

Gesendet wird nur über **„An Social Hub senden“** im Drei-Punkte-Menü des Eintrags, nie beim Speichern. Freigabe und Veröffentlichung laufen im Hub.

## 8. Prüfen

- [ ] **Werkzeuge → Social Hub** zeigt „Verbunden“, alle Konten mit Status ok und einen letzten Sync.
- [ ] `php please social-hub:sync` meldet „Verbunden mit dem Social Hub“ und keine Fehler.
- [ ] Im Hub zeigt die Kundenseite unter „Zuletzt gemeldet“ einen aktuellen Zeitpunkt und die Addon-Version.
- [ ] Die Bilder auf der Seite kommen von der eigenen Domain (`/social-hub/…` bzw. Glide), nicht von `cdninstagram.com` oder `fbcdn.net`.
- [ ] Unter „Webhook“ im Control Panel steht „Secret gesetzt“. Ein neuer Beitrag auf Instagram erscheint nach ein bis zwei Minuten auf der Seite.
- [ ] Cronjob läuft (Ploi → Cronjobs, bzw. „Letzter Sync“ wird alle 30 Minuten aktualisiert).

## Fehlerbehebung

| Anzeige | Ursache und Lösung |
|---|---|
| Kein Feld für den Verbindungscode | In der `.env` stehen `SOCIAL_HUB_URL`/`SOCIAL_HUB_KEY`. Entfernen oder dort pflegen. Oder dem Nutzer fehlt das Recht „Mit dem Social Hub verbinden“. |
| Verbinden schlägt fehl, Key ungültig | Code unvollständig kopiert, Key inzwischen erneuert oder gesperrt, oder Kundenseite im Hub nicht aktiv. Im Hub „API-Key erneuern“ und den neuen Code einfügen. |
| Hub nicht erreichbar | Hub-Adresse im Code prüfen. Der Server der Seite muss ausgehend per HTTPS auf den Hub zugreifen dürfen. Bis zu 7 Tage zeigt die Seite den letzten guten Feed. |
| Konto fehlt, Feed leer | Handle im Template stimmt nicht mit dem Handle im Hub überein, oder das Konto ist dort nicht mit dieser Seite verknüpft. |
| Neue Beiträge kommen erst nach 30 Minuten | Webhook kommt nicht an: kein Webhook-Secret (im Hub „Webhook-Secret erzeugen“ und den Code erneut einfügen), abweichende Domain (Webhook-URL im Hub eintragen) oder Static Caching ohne `nocache`. |
| Medien kommen weiter vom Hub | Datei größer als `max_download_mb`, Videos ohne Cron, oder der Hub liefert über einen anderen Host aus (`media_hosts` in der Config). Details im Control Panel unter „Letzte Fehler“. |
| „Der Hub antwortet nicht rechtzeitig“ beim Senden | Post wurde evtl. trotzdem angelegt. Ein paar Minuten warten und die Aktion erneut ausführen, sie aktualisiert denselben Post. Dauerhaft: `fastcgi_read_timeout` prüfen (Schritt 7). |
