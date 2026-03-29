# Lennarts Diktat-Trainer

Adaptive Rechtschreib-Förder-App für Kinder mit LRS-Schwäche.
Gebaut mit PHP 8 + SQLite — kein Framework, kein Composer nötig.

---

## Voraussetzungen

| Komponente | Version |
|---|---|
| PHP | 8.1+ |
| PHP-Erweiterungen | `pdo_sqlite`, `curl`, `mbstring`, `openssl` |
| Webserver | nginx oder Apache |
| SQLite | wird von PHP mitgeliefert |

---

## Installation (YunoHost)

### 1. Custom Webapp installieren

YunoHost → Anwendungen → **Custom Webapp** installieren.
Pfad und Domain nach Wunsch wählen.

### 2. Dateien hochladen

```bash
# Repo klonen oder ZIP entpacken nach z.B. /var/www/lrs
git clone https://github.com/pww1980/lrs /var/www/lrs
```

### 3. Schreibrechte für das data-Verzeichnis setzen

```bash
chown -R www-data:www-data /var/www/lrs/data
```

> **Einmalig nötig.** Die Datenbank wird beim ersten Aufruf automatisch angelegt.

### 4. .env anlegen

```bash
cp /var/www/lrs/.env.example /var/www/lrs/.env
nano /var/www/lrs/.env
```

Mindest-Inhalt der `.env`:

```env
APP_NAME="Lennarts Diktat-Trainer"
APP_ENV=production

# Zufälliger Schlüssel für AES-256-Verschlüsselung der API-Keys
# Generieren: php -r "echo bin2hex(random_bytes(32));"
APP_ENCRYPTION_KEY=dein_zufaelliger_schluessel_hier
```

Schlüssel generieren:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

### 5. nginx-Konfiguration (nur wenn nötig)

YunoHost konfiguriert nginx automatisch. Falls Clean-URLs nicht funktionieren
(404 auf `/login`, `/setup` etc.), in der nginx-Config ergänzen:

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

> **Hinweis:** Die App verwendet automatisch Query-String-Routing
> (`/index.php?_r=/pfad`) als Fallback — funktioniert ohne nginx-Änderungen.

---

## Erste Schritte nach der Installation

### Schritt 1 — Superadmin anlegen

Seite aufrufen → automatischer Redirect auf `/setup`.
Superadmin-Account (für dich als Elternteil) anlegen.

### Schritt 2 — Admin anlegen *(optional)*

Als Superadmin einloggen → **Systemübersicht** → Admin-Account anlegen.
*(Superadmin kann auch direkt Kinder anlegen — Schritt 2 ist optional.)*

### Schritt 3 — Kind anlegen (Wizard)

Systemübersicht → **"➕ Kind hinzufügen (Wizard)"**

Im Wizard:
1. **Kind anlegen** — Name, Klasse, Schulform, Bundesland, Theme
2. **API-Keys** — KI-Backend (Claude/OpenAI/Gemini) + TTS-Anbieter
3. **Fehlerkategorien** — Erklärung der Blöcke A–D
4. **Testintervall** — Standard: 42 Tage (6 Wochen)
5. **Fertig** — Kind kann sich einloggen

### Schritt 4 — TTS vorwärmen *(empfohlen)*

Admin-Dashboard → **"🔊 TTS-Cache vorwärmen"** → Button klicken.
Generiert Audio-Dateien für alle Wörter im Voraus → Test startet ohne Ladepause.

### Schritt 5 — Einstufungstest

Als Kind einloggen → Einstufungstest starten.
KI wertet aus und erstellt den Übungsplan (nach Admin-Bestätigung).

---

## API-Keys konfigurieren

Alle Keys werden AES-256-verschlüsselt in der SQLite-Datenbank gespeichert.

### KI-Backend (Auswertung + Übungsplan)

| Anbieter | Empfehlung | Kosten |
|---|---|---|
| **Claude** (Anthropic) | ✅ Empfohlen | ~0,003 $/1k Token |
| OpenAI (GPT-4o) | ✅ | ~0,0025 $/1k Token |
| Google Gemini | ✅ | ~0,00125 $/1k Token |

### Text-to-Speech

| Anbieter | Qualität | Kosten |
|---|---|---|
| **OpenAI TTS** | ⭐⭐⭐ Sehr gut | ~0,015 $/1k Zeichen |
| Google Cloud TTS | ⭐⭐⭐ Sehr gut | ~0,004 $/1k Zeichen |
| **Browser-TTS** | ⭐⭐ Gut | Kostenlos |

> Browser-TTS (Web Speech API) funktioniert ohne API-Key — gute Option zum Testen.

---

## TTS-Cache (Cron)

Damit Audio sofort abgespielt wird ohne Verzögerung:

```bash
# Alle Wörter einmalig generieren
php /var/www/lrs/database/warm_tts.php

# Cron: täglich 3 Uhr (für neu hinzugefügte Wörter)
0 3 * * * php /var/www/lrs/database/warm_tts.php >> /var/log/lrs_tts.log 2>&1
```

---

## Verzeichnisstruktur

```
/
├── index.php              # Router (Entry Point)
├── setup.php              # Ersteinrichtung (einmalig)
├── config/app.php         # Konfiguration + DB-Verbindung
├── database/
│   ├── schema.sql         # Datenbankschema
│   ├── warm_tts.php       # TTS-Cache CLI-Script
│   └── curricula/         # Lehrplan-JSONs (Bayern etc.)
├── src/
│   ├── Controllers/       # PHP-Controller
│   ├── Models/            # Datenbank-Modelle
│   ├── Services/          # KI, TTS, Verschlüsselung
│   ├── Helpers/           # Auth, CSRF
│   └── Views/             # PHP-Templates
├── themes/
│   └── minecraft/         # Minecraft-Theme (Standard)
├── public/
│   ├── css/app.css        # Stylesheet
│   └── js/                # JavaScript
└── data/
    ├── lerntrainer.sqlite  # Datenbank (auto-generiert)
    └── tts_cache/          # Audio-Cache (auto-generiert)
```

---

## Fehlerbehebung

### "Schreibrechte fehlen"
```bash
chown -R www-data:www-data /var/www/lrs/data
```

### "APP_ENCRYPTION_KEY fehlt"
```bash
# In .env eintragen:
APP_ENCRYPTION_KEY=$(php -r "echo bin2hex(random_bytes(32));")
```

### TTS lädt nicht / Timeout
→ Im Admin-Dashboard auf **"🔊 TTS-Cache vorwärmen"** klicken.
→ Oder Browser-TTS wählen (kein API-Key nötig).

### 404 auf alle Seiten außer Startseite
→ nginx `try_files` Direktive fehlt — siehe [nginx-Konfiguration](#5--nginx-konfiguration-nur-wenn-nötig).
→ App funktioniert auch ohne nginx-Änderungen (Query-String-Routing).

---

## Lizenz

MIT — siehe [LICENSE](LICENSE)
