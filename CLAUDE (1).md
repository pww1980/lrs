# CLAUDE.md — Lennarts Diktat-Trainer

Dieses Dokument beschreibt das gesamte Projekt für Claude Code.
Lies es vollständig bevor du mit der Implementierung beginnst.

---

## Projektübersicht

**Name:** Lennarts Diktat-Trainer
**Zweck:** Adaptive Rechtschreib-Förder-App für Kinder mit LRS-Schwäche
**Primärer Nutzer:** Lennart, 10 Jahre, 4. Klasse, LRS-Schwäche, liest gut
**Admin:** Patrick (Papa) — überwacht Fortschritt, passt Plan an
**Lizenz:** Open Source (MIT)
**Sprachen:** Deutsch (implementiert) — Englisch (Architektur vorbereitet, später)

---

## Tech Stack

- **Backend:** PHP 8.x
- **Datenbank:** SQLite (eine Datei, kein separater DB-Server)
- **Frontend:** HTML / CSS / Vanilla JS (kein Framework)
- **Hosting:** YunoHost (self-hosted) — auch lokal nutzbar
- **Geräte:** Tablet (Touch) + PC/Desktop (beide unterstützt)
- **Nur online** — kein Offline-Modus
- **Konfiguration:** `.env` Datei (`.env.example` im Repo, nie echte Keys committen)
- **README.md:** YunoHost-Installationsanleitung mitliefern

---

## Externe APIs

### KI-Backend (Auswertung / Übungsplan / Feedback)
Konfigurierbar pro User in den Einstellungen. Unterstützte Anbieter:
- `claude` — Anthropic Claude API
- `openai` — OpenAI ChatGPT API
- `gemini` — Google Gemini API

### Text-to-Speech (TTS)
**Unabhängig** vom KI-Backend konfigurierbar. Unterstützte Anbieter:
- `openai_tts` — OpenAI TTS API (Standard, Stimme: nova)
- `google_tts` — Google Cloud TTS
- `browser` — Web Speech API (kostenlos, kein Key nötig, Fallback)

TTS-Features:
- Wort/Satz wiederholbar (beliebig oft)
- Geschwindigkeit: Normal (rate 1.0) / Langsam (rate 0.6)
- Beim Mini-Diktat: einzelne Sätze wiederholbar

---

## Architektur

```
/
├── index.php              # Router / Entry Point
├── config/
│   └── app.php            # Globale Konfiguration
├── database/
│   ├── schema.sql         # Vollständiges DB-Schema
│   └── seed/              # Grundwortschatz-Daten
├── src/
│   ├── Controllers/       # PHP Controller
│   ├── Models/            # PHP Models (SQLite)
│   ├── Services/
│   │   ├── AIService.php      # KI-Backend Abstraktion
│   │   ├── TTSService.php     # TTS Abstraktion
│   │   └── EncryptionService.php  # API-Key Verschlüsselung
│   └── Helpers/
├── themes/
│   ├── minecraft/         # Erstes Theme
│   │   ├── theme.json     # Farben, Labels, Icons
│   │   └── assets/
│   ├── space/             # Später
│   └── ocean/             # Später
├── public/
│   ├── css/
│   ├── js/
│   └── assets/
└── data/
    └── lerntrainer.sqlite # SQLite Datenbankdatei
```

---

## Rollen & Authentifizierung

Drei Rollen:

```
superadmin  → sieht alles (alle Familien, alle Kinder)
              Systemverwaltung, globale Wortlisten
              Lehrplan-JSONs pflegen
              KI-Nutzungsstatistik global
              Admins anlegen / sperren

admin       → sieht nur eigene Kinder
              Kinder anlegen / verwalten
              Papa-Dashboard pro Kind
              Eigene API-Keys

child       → nur Lernbereich
              kein Zugang zu Dashboard
```

**Mehrere Admins pro Kind:**
Statt `linked_admin_id` gibt es eine Zwischentabelle `child_admins`:
- `primary` — voller Schreibzugriff (Kinder anlegen, Plan anpassen)
- `secondary` — nur Lesezugriff (Fortschritt sehen, kein Eingriff)

```sql
CREATE TABLE child_admins (
  child_id    INTEGER NOT NULL REFERENCES users(id),
  admin_id    INTEGER NOT NULL REFERENCES users(id),
  role        TEXT CHECK(role IN ('primary','secondary'))
              DEFAULT 'primary',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (child_id, admin_id)
);
```

Superadmin hat keinen Eintrag in `child_admins`.
Einfaches Session-basiertes Login (PHP Sessions).

**Kinder-Verwaltung (Admin primary):**
```
[+ Kind hinzufügen]

Name     Klasse  Schulform    Bundesland  Theme      Aktiv  Aktionen
────────────────────────────────────────────────────────────────────
Lennart  4       Grundschule  Bayern      Minecraft  ✅     [📊] [✏️] [🔒]
Merle    7       Gymnasium    Bayern      Space      ✅     [📊] [✏️] [🔒]

[📊] Fortschritt   [✏️] Profil bearbeiten   [🔒] Sperren
```

**Superadmin-Dashboard (zusätzlich):**
```
Systemübersicht:
  Familien/Admins: 3    Kinder gesamt: 5
  Sessions heute:  12   KI-Kosten MTD: €0.43

Admins verwalten:
  Patrick   2 Kinder   aktiv   [Details] [Sperren]

Globale Wortlisten:
  Wörter prüfen + deaktivieren über alle Familien
  Lehrplan-JSONs hochladen / aktualisieren

KI-Statistik global:
  Aufrufe + Kosten pro Anbieter + pro Familie
```

---

## Fehlerkategorien (LRS)

Vier Blöcke, hierarchisch aufgebaut:

```
Block A — Laut-Buchstaben-Zuordnung (phonetische Basis)
  A1 · Auslautverhärtung       z.B. "Hund→Hunt", "Weg→Wek"
  A2 · Vokallänge (kurz/lang)  z.B. "Miete→Mite", "Mitte→Mite"
  A3 · Konsonantenhäufungen    z.B. "Strumpf→Sturmpf"

Block B — Regelwissen (orthografische Regeln)
  B1 · Doppelkonsonanten       z.B. "Mutter→Muter"
  B2 · ck / tz                 z.B. "Brücke→Brüke", "Katze→Katse"
  B3 · ie / ih / i             z.B. "Tier→Tir", "ihm→im"
  B4 · Dehnungs-h              z.B. "fahren→faren"
  B5 · sp / st                 z.B. "Straße→Sdrasse"

Block C — Ableitungswissen (morphologisches Denken)
  C1 · ä vs. e                 z.B. "Hände→Hende"
  C2 · äu vs. eu               z.B. "Häuser→Heuser"
  C3 · dass / das              grammatische Unterscheidung

Block D — Groß-/Kleinschreibung (eigenständiger Block)
  D1 · Konkrete Nomen          z.B. "Fahrrad", "Hund"
  D2 · Abstrakte Nomen         z.B. "Freundschaft", "Angst"
  D3 · Nominalisierungen       z.B. "das Laufen", "beim Essen"
  D4 · Satzanfang              unter Schreibdruck vergessen
```

**Wichtig:** Jedes Wort hat eine `primary_category` und kann
optionale Nebenkategorien haben (`word_categories`).

---

## Lernzyklus

```
1. Einstufungstest
      ↓
2. KI-Auswertung → Fehlerprofil (A/B/C/D mit Schweregrad)
      ↓
3. KI erstellt initialen Übungsplan (Questlog)
      ↓
4. Papa prüft + bestätigt Plan (kann anpassen)
      ↓
5. Übungseinheit (~10 Min / ~20 Wörter)
      ↓
6. KI wertet aus + erstellt Amendment (Plan-Anpassung)
      ↓
7. Loop bis Fortschrittstest fällig (alle X Monate)
      ↓
8. Fortschrittstest → Vergleich → neuer Plan
```

---

## Teststruktur

**Zwei Testtypen** (identische Struktur, unterschiedlicher Typ):
- `initial` — Einstufungstest (einmalig)
- `progress` — Fortschrittstest (alle X Monate, andere Wörter)

**KI-Ermüdungserkennung:**
Nach jedem Block analysiert die KI:
- Response-Zeit steigt an?
- TTS-Replay-Count nimmt zu?
- Fehlerrate steigt trotz bekannter Kategorie?
→ KI empfiehlt Pause / Test aufteilen (mehrere Sessions möglich)

**Format pro Block (KI entscheidet):**
```
Block A → primär Einzelwörter
Block B → Einzelwörter + vereinzelt Lückentext
Block C → Lückentext + kurze Sätze
Block D → Sätze (Groß-/Klein nur im Kontext testbar)
```

---

## Übungsformate

Vier Formate, KI wählt automatisch nach Fortschritt:

```
Neu in Kategorie    → Einzelwort   (isoliert)
Erste Sicherheit    → Lückentext   (Wort im Satzkontext)
Gut beherrscht      → Satzdiktat   (ganzer Satz)
Gemeistert          → Mini-Diktat  (3-5 Sätze am Stück)
```

KI kann innerhalb einer Einheit mischen
(z.B. 10 Einzelwörter zur Aufwärmung + 5 Lückentexte).

**Zweiter Versuch (KI entscheidet):**
```
Tippfehler (1 Zeichen, nahe am Richtigen)?  → 2. Versuch + Hinweis
Großschreibung vergessen?                    → 2. Versuch + Hinweis
Falsches Wortbild (komplett anders)?         → kein 2. Versuch, Erklärung
Auslautverhärtung?                           → kein 2. Versuch, Regel erklären
```

---

## KI-generierte Inhalte

Die KI generiert Diktattexte dynamisch. Prinzip:

```
Lernwörter    → fest vorgegeben (Grundwortschatz / Fehlerprofil)
Story/Kontext → KI baut passend zum Theme drum herum
```

Beispiel-Prompt-Logik:
```
"Schreibe einen kurzen Minecraft-Text für einen 10-Jährigen.
 Diese Wörter MÜSSEN vorkommen, exakt so geschrieben:
 [Brücke, Rucksack, plötzlich]
 Kontext: Minecraft, altersgerecht, max. 4 Sätze."
```

Generierte Inhalte werden gespeichert und wiederverwendet
(Pool-System, nach Klassenstufe / Kategorie / Theme).

Pool-Logik beim Abrufen:
1. Passender Satz vorhanden + noch nicht von diesem Kind genutzt? → nehmen
2. Alle genutzt? → KI generiert neuen, speichert in Pool
3. Kein Theme-Satz? → neutralen Satz als Fallback

---

## Gamification — Minecraft-Theme

**Questlog (Abenteuermap):**
```
Biom 1 🌲 Der Wald      → Block A (Basis)
Biom 2 🏜️ Die Wüste     → Block B (Regelwissen)
Biom 3 🌋 Der Nether    → Block C (Ableitungswissen)
Biom 4 🌟 Das End       → Block D (Groß-/Kleinschreibung)
```

- Biome werden freigeschaltet wenn vorheriges gemeistert
- Quests innerhalb eines Bioms von KI freigeschaltet
- Lennart sieht gesamten Fortschrittsweg als Map
- **Biome kommen aus theme.json** — nicht hardcodiert auf A/B/C/D
  → funktioniert für Englisch mit anderen Blockstrukturen

**Achievements (Minecraft Advancements-Style):**
- Lern-Achievements (Fortschritt)
- Streak-Achievements (Kontinuität)
- Achievements schalten Themes und Features frei
- Secret Achievements vorhanden
- **Freischaltungen sind pro Kind** (`user_achievements` / `unlocked_content`
  je per `user_id`) — Lennarts Freischaltungen gehören nur ihm

**Theme-Architektur:**
```
/themes/minecraft/theme.json   ← aktuell implementiert
/themes/space/theme.json       ← später (freischaltbar)
/themes/ocean/theme.json       ← später (freischaltbar)
/themes/nether/theme.json      ← später (Secret, 30-Tage-Streak)
```

Jedes theme.json enthält:
```json
{
  "id": "space",
  "locked_by_default": true,
  "biomes": [
    { "id": "moon",   "label": "Der Mond",    "icon": "🌙", "block_index": 0 },
    { "id": "mars",   "label": "Der Mars",    "icon": "🔴", "block_index": 1 },
    { "id": "saturn", "label": "Der Saturn",  "icon": "🪐", "block_index": 2 },
    { "id": "galaxy", "label": "Die Galaxis", "icon": "🌌", "block_index": 3 }
  ],
  "colors": {},
  "labels": { "points": "Sterne", "quest": "Mission" },
  "flavor_texts": {}
}
```

`block_index` zeigt auf den n-ten Block der aktuellen Sprache —
unabhängig von Deutsch A/B/C/D oder Englisch PH/SP/GR.
App-Logik ist komplett theme-unabhängig.

---

## Papa-Dashboard

Admin sieht:
- Übersicht: letzter Login Lennart, aktuelle Quest, Streak
- Fortschrittsgrafik pro Block A/B/C/D über Zeit (Chart.js)
- Vergleich Einstufungstest → aktueller Stand
- Detailansicht pro Übungseinheit (Wörter, Fehlertypen)
- Plan-Amendments mit KI-Begründung
- Warnungen: Stagnation, lange Pause (5+ Tage), Fortschrittstest fällig
- Manuelle Eingriffe: Quest anpassen, Wörter hinzufügen,
  Schwierigkeit ändern
- Wortpool verwalten: Sätze bewerten (1-5 Sterne), deaktivieren
- PDF-Export für Lehrerin
- KI-Nutzungsstatistik (Aufrufe + geschätzte Kosten pro Monat)
- Keine Push-Benachrichtigungen — nur Dashboard

---

## Datenbankschema

### Block 1 — Nutzer & Einstellungen

```sql
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username VARCHAR(50) UNIQUE NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role TEXT CHECK(role IN ('superadmin','admin','child')) NOT NULL,
  grade_level INTEGER NULL,
  school_type VARCHAR(50) NULL,        -- 'Grundschule', 'Mittelschule'...
  theme VARCHAR(50) DEFAULT 'minecraft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL,
  active INTEGER DEFAULT 1
  -- linked_admin_id entfernt → ersetzt durch child_admins Tabelle
);

CREATE TABLE child_admins (
  child_id    INTEGER NOT NULL REFERENCES users(id),
  admin_id    INTEGER NOT NULL REFERENCES users(id),
  role        TEXT CHECK(role IN ('primary','secondary')) DEFAULT 'primary',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (child_id, admin_id)
);

CREATE TABLE settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  key VARCHAR(100) NOT NULL,
  value_encrypted TEXT NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id, key)
);
-- Settings Keys pro User (alle verschlüsselt gespeichert):
--
-- Admin-Settings:
--   ai_provider              → 'claude' / 'openai' / 'gemini'
--   ai_api_key               → verschlüsselt
--   tts_provider             → 'openai_tts' / 'google_tts' / 'browser'
--   tts_api_key              → verschlüsselt
--
-- Kind-Settings (pro Kind konfigurierbar):
--   tts_voice                → 'nova' / 'alloy' / 'echo' / 'fable'...
--   tts_speed                → 'normal' / 'slow'
--   session_word_count       → default 20, range 10-40
--   progress_test_interval   → default 42 (Tage)
```

### Block 2 — Wortmaterial

```sql
CREATE TABLE words (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  word VARCHAR(100) NOT NULL,
  primary_category VARCHAR(10) NOT NULL,
  grade_level INTEGER NOT NULL,
  difficulty INTEGER DEFAULT 1,
  source TEXT CHECK(source IN ('kmk','ai','manual')) DEFAULT 'kmk',
  active INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE word_categories (
  word_id INTEGER NOT NULL REFERENCES words(id),
  category VARCHAR(10) NOT NULL,
  PRIMARY KEY (word_id, category)
);

CREATE TABLE sentences (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sentence TEXT NOT NULL,
  primary_category VARCHAR(10) NOT NULL,
  grade_level INTEGER NOT NULL,
  theme VARCHAR(50) NULL,
  format TEXT CHECK(format IN ('gap','sentence','mini_diktat')) NOT NULL,
  difficulty INTEGER DEFAULT 1,
  source TEXT CHECK(source IN ('ai','manual')) DEFAULT 'ai',
  times_used INTEGER DEFAULT 0,
  quality_score INTEGER NULL,
  active INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_by INTEGER NULL REFERENCES users(id)
);

CREATE TABLE sentence_words (
  sentence_id INTEGER NOT NULL REFERENCES sentences(id),
  word_id INTEGER NOT NULL REFERENCES words(id),
  position INTEGER NOT NULL,
  is_test_word INTEGER DEFAULT 0,
  PRIMARY KEY (sentence_id, word_id)
);

CREATE TABLE child_sentence_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  sentence_id INTEGER NOT NULL REFERENCES sentences(id),
  used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  was_correct INTEGER NULL
);
```

### Block 3 — Test

```sql
CREATE TABLE tests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  type TEXT CHECK(type IN ('initial','progress')) NOT NULL,
  status TEXT CHECK(status IN ('pending','in_progress',
         'completed','aborted')) DEFAULT 'pending',
  started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  session_count INTEGER DEFAULT 1,
  ai_fatigue_score INTEGER NULL,
  ai_notes TEXT NULL,
  compared_to_test INTEGER NULL REFERENCES tests(id)
);

CREATE TABLE test_sections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  test_id INTEGER NOT NULL REFERENCES tests(id),
  block TEXT CHECK(block IN ('A','B','C','D')) NOT NULL,
  status TEXT CHECK(status IN ('pending','in_progress',
         'completed','skipped')) DEFAULT 'pending',
  order_index INTEGER NOT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  ai_recommendation TEXT NULL
);

CREATE TABLE test_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  section_id INTEGER NOT NULL REFERENCES test_sections(id),
  word_id INTEGER NULL REFERENCES words(id),
  sentence_id INTEGER NULL REFERENCES sentences(id),
  format TEXT CHECK(format IN ('word','gap','sentence')) NOT NULL,
  order_index INTEGER NOT NULL,
  played_at DATETIME NULL,
  replay_count INTEGER DEFAULT 0,
  answered_at DATETIME NULL,
  user_input VARCHAR(255) NULL,
  is_correct INTEGER NULL,
  error_categories TEXT NULL,
  response_time_ms INTEGER NULL,
  ai_feedback TEXT NULL
);

CREATE TABLE test_results (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  test_id INTEGER NOT NULL REFERENCES tests(id),
  block TEXT CHECK(block IN ('A','B','C','D')) NOT NULL,
  category VARCHAR(10) NOT NULL,
  total_items INTEGER NOT NULL,
  correct_items INTEGER NOT NULL,
  error_rate REAL NOT NULL,
  severity TEXT CHECK(severity IN ('none','mild',
            'moderate','severe')) NOT NULL,
  strategy_level INTEGER NOT NULL,
  compared_delta REAL NULL
);
```

### Block 4 — Lernplan / Questlog

```sql
CREATE TABLE learning_plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  test_id INTEGER NOT NULL REFERENCES tests(id),
  status TEXT CHECK(status IN ('draft','active',
         'completed','superseded')) DEFAULT 'draft',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  admin_notes TEXT NULL,
  ai_notes TEXT NULL
);

CREATE TABLE plan_biomes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_id INTEGER NOT NULL REFERENCES learning_plans(id),
  block TEXT CHECK(block IN ('A','B','C','D')) NOT NULL,
  name VARCHAR(100) NOT NULL,
  theme_biome VARCHAR(50) NOT NULL,
  order_index INTEGER NOT NULL,
  status TEXT CHECK(status IN ('locked','active',
         'completed')) DEFAULT 'locked',
  unlocked_at DATETIME NULL,
  completed_at DATETIME NULL
);

CREATE TABLE quests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  biome_id INTEGER NOT NULL REFERENCES plan_biomes(id),
  category VARCHAR(10) NOT NULL,
  title VARCHAR(100) NOT NULL,
  description TEXT NULL,
  order_index INTEGER NOT NULL,
  status TEXT CHECK(status IN ('locked','active',
         'completed','skipped')) DEFAULT 'locked',
  difficulty INTEGER DEFAULT 1,
  required_score INTEGER DEFAULT 80,
  unlocked_at DATETIME NULL,
  completed_at DATETIME NULL,
  ai_notes TEXT NULL
);

CREATE TABLE plan_units (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  quest_id INTEGER NOT NULL REFERENCES quests(id),
  order_index INTEGER NOT NULL,
  format TEXT CHECK(format IN ('word','gap',
         'sentence','mini_diktat')) NOT NULL,
  word_count INTEGER DEFAULT 20,
  difficulty INTEGER DEFAULT 1,
  status TEXT CHECK(status IN ('pending','active',
         'completed','skipped')) DEFAULT 'pending',
  scheduled_for DATE NULL,
  completed_at DATETIME NULL,
  ai_notes TEXT NULL
);

CREATE TABLE plan_amendments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_id INTEGER NOT NULL REFERENCES learning_plans(id),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  trigger_type TEXT CHECK(trigger_type IN ('session_result',
               'admin_manual','progress_test',
               'stagnation','mastery')) NOT NULL,
  trigger_ref_id INTEGER NULL,
  changes_json TEXT NOT NULL,
  ai_reasoning TEXT NOT NULL,
  admin_approved INTEGER DEFAULT 1
);
```

### Block 5 — Übungseinheit / Sessions

```sql
CREATE TABLE sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  plan_unit_id INTEGER NOT NULL REFERENCES plan_units(id),
  status TEXT CHECK(status IN ('active','completed',
         'aborted')) DEFAULT 'active',
  started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  duration_seconds INTEGER NULL,
  total_items INTEGER DEFAULT 0,
  correct_first_try INTEGER DEFAULT 0,
  correct_second_try INTEGER DEFAULT 0,
  wrong_total INTEGER DEFAULT 0,
  fatigue_score INTEGER NULL,
  motivation_score INTEGER NULL,
  ai_summary TEXT NULL,
  ai_next_action TEXT NULL
);

CREATE TABLE session_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL REFERENCES sessions(id),
  word_id INTEGER NULL REFERENCES words(id),
  sentence_id INTEGER NULL REFERENCES sentences(id),
  generated_content_id INTEGER NULL
    REFERENCES generated_content(id),
  format TEXT CHECK(format IN ('word','gap',
         'sentence','mini_diktat')) NOT NULL,
  order_index INTEGER NOT NULL,
  tts_replays INTEGER DEFAULT 0,
  tts_slow_replays INTEGER DEFAULT 0,
  second_try_allowed INTEGER DEFAULT 0,
  final_correct INTEGER NULL,
  response_time_ms INTEGER NULL,
  ai_feedback TEXT NULL,
  error_categories TEXT NULL
);

CREATE TABLE session_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL REFERENCES session_items(id),
  attempt_number INTEGER NOT NULL,
  user_input VARCHAR(255) NOT NULL,
  is_correct INTEGER NOT NULL,
  answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  error_type VARCHAR(50) NULL
);
```

### Block 6 — Fortschritt / Achievements

```sql
CREATE TABLE progress_snapshots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  trigger_type TEXT CHECK(trigger_type IN ('session','test',
               'quest_completed','biome_completed')) NOT NULL,
  trigger_ref_id INTEGER NULL,
  category_scores TEXT NOT NULL,
  overall_score REAL NOT NULL,
  active_biome VARCHAR(10) NULL,
  active_quest VARCHAR(10) NULL,
  streak_days INTEGER DEFAULT 0,
  total_sessions INTEGER DEFAULT 0,
  total_words INTEGER DEFAULT 0
);

CREATE TABLE achievement_definitions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code VARCHAR(50) UNIQUE NOT NULL,
  title VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  icon VARCHAR(50) NOT NULL,
  category TEXT CHECK(category IN ('learning','streak',
            'collection','special')) NOT NULL,
  trigger_type VARCHAR(50) NOT NULL,
  trigger_value INTEGER NULL,
  unlocks_theme VARCHAR(50) NULL,
  unlocks_feature VARCHAR(50) NULL,
  is_secret INTEGER DEFAULT 0,
  active INTEGER DEFAULT 1
);

CREATE TABLE user_achievements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  achievement_id INTEGER NOT NULL
    REFERENCES achievement_definitions(id),
  unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  seen_by_user INTEGER DEFAULT 0,
  UNIQUE(user_id, achievement_id)
);

CREATE TABLE unlocked_content (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  content_type TEXT CHECK(content_type IN
                ('theme','feature')) NOT NULL,
  content_key VARCHAR(50) NOT NULL,
  unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  unlocked_by INTEGER NOT NULL
    REFERENCES achievement_definitions(id)
);
```

### Block 7 — Generierte Inhalte

```sql
CREATE TABLE generated_content (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT CHECK(type IN ('diktat','sentence','word_list',
       'feedback','exercise_plan','test_analysis')) NOT NULL,
  ai_provider TEXT CHECK(ai_provider IN
               ('claude','openai','gemini')) NOT NULL,
  model_version VARCHAR(50) NOT NULL,
  prompt_used TEXT NOT NULL,
  content_json TEXT NOT NULL,
  grade_level INTEGER NULL,
  category VARCHAR(10) NULL,
  theme VARCHAR(50) NULL,
  format TEXT CHECK(format IN ('word','gap',
         'sentence','mini_diktat')) NULL,
  difficulty INTEGER NULL,
  quality_score INTEGER NULL,
  times_used INTEGER DEFAULT 0,
  active INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_for INTEGER NULL REFERENCES users(id)
);

CREATE TABLE generated_content_words (
  content_id INTEGER NOT NULL
    REFERENCES generated_content(id),
  word_id INTEGER NOT NULL REFERENCES words(id),
  is_test_word INTEGER DEFAULT 0,
  position INTEGER NOT NULL,
  PRIMARY KEY (content_id, word_id)
);

CREATE TABLE ai_interactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NULL REFERENCES users(id),
  session_id INTEGER NULL REFERENCES sessions(id),
  test_id INTEGER NULL REFERENCES tests(id),
  type TEXT CHECK(type IN ('feedback','plan_generation',
       'plan_amendment','test_analysis',
       'content_generation','fatigue_check')) NOT NULL,
  ai_provider TEXT CHECK(ai_provider IN
               ('claude','openai','gemini')) NOT NULL,
  model_version VARCHAR(50) NOT NULL,
  prompt_tokens INTEGER NULL,
  completion_tokens INTEGER NULL,
  cost_estimate REAL NULL,
  prompt_used TEXT NOT NULL,
  response_json TEXT NOT NULL,
  duration_ms INTEGER NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## Wortmaterial

**Keine hardcodierten Seed-Dateien.**
Wortlisten werden dynamisch per KI generiert — einmalig beim Setup,
dann gecacht in der Datenbank. Bundesland + Jahrgangsstufe + offizieller
Lehrplan als Kontext für die KI.

### Lehrplan-Referenzdatenbank

```
/database/curricula/
  bayern_gs_3_4.json    ← LehrplanPLUS Bayern Grundschule 3/4
  bayern_gs_5_6.json
  nrw_gs_3_4.json       ← später
  ...
```

Jede JSON-Datei enthält pro Fehlerkategorie:
```json
{
  "federal_state": "Bayern",
  "school_type": "Grundschule",
  "grades": "3/4",
  "source": "LehrplanPLUS Bayern 2014",
  "url": "https://lehrplanplus.bayern.de/...",
  "categories": {
    "B2": {
      "label": "ck und tz",
      "curriculum_text": "Schülerinnen und Schüler schreiben Wörter
        mit ck und tz nach kurzem Vokal richtig.",
      "examples_official": ["backen", "Brücke", "Katze", "Mütze"],
      "grade_focus": "ab Klasse 3"
    }
  }
}
```

### Generierungs-Logik beim Setup

```
Setup: Bundesland + Jahrgangsstufe gewählt
        ↓
Für jede Kategorie (A1–D4) prüfen:
  SELECT COUNT(*) FROM words
  WHERE federal_state = 'Bayern'
  AND grade_level = 4
  AND primary_category = 'B2'
  AND active = 1

  >= 15 Wörter vorhanden? → nichts tun (gecacht)
  < 15 Wörter?            → KI generiert fehlende Wörter
        ↓
Generierte Wörter gespeichert mit source='ai_generated'
Admin kann Wörter im Dashboard prüfen + deaktivieren
```

Schwellwert 15 (nicht 20) damit manuell hinzugefügte Wörter
keine Neugenerierung auslösen.

### KI-Prompt beim Generieren

Alle spezifischen Werte kommen dynamisch aus der Lehrplan-JSON.
Der Prompt selbst bleibt generisch — funktioniert für jedes
Bundesland und jede Sprache.

```
Du bist ein Rechtschreib-Experte für {language}-Lernende.
Generiere 20 altersgerechte Übungswörter passend zu
folgendem Lehrplan:

Sprache:        {language}          -- z.B. "Deutsch"
Region:         {federal_state}     -- z.B. "Bayern"
Schulform:      {school_type}       -- z.B. "Grundschule"
Jahrgangsstufe: {grade_level}       -- z.B. "4"
Kategorie:      {category_code}     -- z.B. "B2"
Bezeichnung:    {category_label}    -- z.B. "ck und tz"
Lehrplan:       {curriculum_ref}    -- z.B. "LehrplanPLUS Bayern 2014"

Lehrplan-Vorgabe:
"{curriculum_text}"

Offizielle Beispielwörter laut Lehrplan:
{examples_official}

Regeln:
- Wörter aus dem aktiven Wortschatz eines {grade_level}-Klässlers
- Schwierigkeit aufsteigend (difficulty 1→3)
- Keine Wiederholung der offiziellen Beispielwörter
- Nur Wörter bei denen {category_code} der primäre Lernfokus ist
- Ausgabe als JSON: [{word, difficulty, secondary_categories}]
```

### Datenbankfelder (Ergänzung zu Block 2)

`words` Tabelle erhält zwei zusätzliche Felder:
```sql
federal_state   VARCHAR(50) NULL,  -- 'Bayern', 'NRW'
curriculum_ref  VARCHAR(100) NULL  -- 'LehrplanPLUS GS 3/4'
```

`source` Werte erweitert:
```
'kmk'          → bundesweiter KMK-Grundwortschatz (manuell)
'ai_generated' → von KI generiert auf Basis Lehrplan
'manual'       → vom Admin manuell eingetragen
```

### Manuelle Wortpflege (Papa-Dashboard)

Admin kann jederzeit eigene Wörter hinzufügen — z.B. direkt aus
Lennarts Schulheft oder Diktat-Korrekturen der Lehrerin.

`source = 'manual'` in der `words` Tabelle kennzeichnet diese Wörter.

**UI im Papa-Dashboard:**
```
📝 Wörter verwalten

  [+ Wort hinzufügen]

  Wort           Kategorie   Klasse  Quelle   Aktiv
  ──────────────────────────────────────────────────
  Fahrrad        B2 / D1     4       KMK      ✅
  Schmetterling  B1          4       KMK      ✅
  Erdbeere       D1          4       manuell  ✅
```

**Beim Hinzufügen eines Wortes:**
- Wort eingeben
- Primärkategorie wählen (Dropdown A1–D4)
- Optionale Nebenkategorien wählen
- Klassenstufe wählen
- Schwierigkeit (1–3)
- Sofort aktiv oder erst prüfen

**Verhalten im Übungsplan:**
- Manuell eingepflegte Wörter werden von der KI
  automatisch in den Übungspool der passenden Kategorie aufgenommen
- KI kann manuell eingetragene Wörter priorisieren
  (z.B. "Diese Wörter kamen in echten Diktaten vor")

---

## Achievements — Minecraft Advancements Style

Drei Kategorien. Seed-Daten für `achievement_definitions` Tabelle.

### Lern-Achievements (category: 'learning')

| code | icon | title | trigger_type | trigger_value | unlocks |
|---|---|---|---|---|---|
| first_session | 🪓 | Holzaxt | sessions_completed | 1 | — |
| words_10 | ⚔️ | Holzschwert | words_correct | 10 | — |
| block_a_done | 🛡️ | Lederrüstung | block_mastered | A | — |
| words_50 | ⚔️ | Steinschwert | words_correct | 50 | — |
| quests_3 | 🔮 | Zaubertrank | quests_completed | 3 | — |
| block_b_done | ⚔️ | Eisenschwert | block_mastered | B | space (Theme) |
| block_c_done | 💎 | Diamantschwert | block_mastered | C | ocean (Theme) |
| all_blocks | 🌟 | Nether-Stern | block_mastered | ALL | dark (Theme) |

### Streak-Achievements (category: 'streak')

| code | icon | title | trigger_type | trigger_value | unlocks |
|---|---|---|---|---|---|
| streak_3 | 🔥 | Funken | streak_days | 3 | — |
| streak_7 | 🔥 | Fackel | streak_days | 7 | — |
| streak_14 | 🔥 | Lagerfeuer | streak_days | 14 | mini_diktat_mode (Feature) |
| streak_30 | 🔥 | Netherportal | streak_days | 30 | nether (Secret-Theme) |

### Secret Achievements (category: 'special', is_secret: 1)

| code | icon | title | trigger_type | trigger_value | unlocks |
|---|---|---|---|---|---|
| creeper_friend | 🐛 | Creeper-Freund | same_word_wrong | 10 | — |
| eagle_eye | 🦅 | Adlerauge | correct_streak_first_try | 20 | — |
| slow_learner | 🐢 | Langsam aber sicher | tts_slow_count | 50 | — |

### Beschreibungstexte (Minecraft Flavor)

```
Holzaxt:         "Du hast dein erstes Abenteuer begonnen!"
Holzschwert:     "10 Wörter gemeistert — du wirst stärker!"
Lederrüstung:    "Block A abgeschlossen — der Wald gehört dir!"
Steinschwert:    "50 Wörter richtig — ein echter Kämpfer!"
Zaubertrank:     "3 Quests erledigt — die Magie wirkt!"
Eisenschwert:    "Block B gemeistert — die Wüste wartet!"
Diamantschwert:  "Block C gemeistert — du bist fast unbesiegbar!"
Nether-Stern:    "Alle Blöcke gemeistert — Legende!"
Funken:          "3 Tage in Folge geübt — das Feuer brennt!"
Fackel:          "7 Tage am Stück — nichts kann dich aufhalten!"
Lagerfeuer:      "14 Tage — du bist ein echter Überlebenskünstler!"
Netherportal:    "30 Tage Streak — du hast das Netherportal geöffnet!"
Creeper-Freund:  "Ein Wort macht dir Probleme — aber du gibst nicht auf!"
Adlerauge:       "20 Wörter in Folge beim ersten Versuch — unglaublich!"
Langsam aber sicher: "Du hörst genau hin — das ist deine Stärke!"
```

---

## Sicherheit

- Passwörter: `password_hash()` mit PASSWORD_BCRYPT
- API-Keys: AES-256 verschlüsselt in SQLite, pro User
- Encryption-Secret: in `.env` Datei außerhalb Webroot
- Sessions: PHP native Sessions mit regenerate on login
- Kein öffentlicher Zugang — App ist passwortgeschützt

---

## Offene TODOs

### Inhalte
- [ ] Lehrplan-JSON erstellen: bayern_gs_3_4.json (alle Kategorien A1–D4)
- [ ] Lehrplan-JSON erstellen: bayern_gs_5_6.json
- [ ] KI-Generierungslogik implementieren (WordGeneratorService.php)
- [ ] Achievement-Definitionen in DB seeden (siehe unten)

### Features
- [ ] Fortschrittstest-Intervall: alle 6 Wochen (42 Tage), konfigurierbar
- [ ] PDF-Bericht für Lehrerin enthält:
      - Fehlerprofil Vorher/Nachher (Einstufungstest vs. aktuell)
      - Fehlerrate pro Kategorie als Grafik
      - Anzahl Übungseinheiten + Gesamtdauer
      - Konkrete Verbesserungen in Zahlen (z.B. "B2: 80%→34% Fehlerrate")
      - Aktuelle Schwachstellen
      - KI-Zusammenfassung in einfacher Sprache (für Lehrerin verständlich)
- [ ] Setup-Wizard (ausführlich, Erststart):
      Schritt 1: Kind anlegen (Name, Klasse, Theme)
      Schritt 2: API-Keys eingeben (KI-Backend + TTS, mit Erklärung)
      Schritt 3: Erklärung der 4 Fehlerblöcke A/B/C/D mit Beispielen
      Schritt 4: Fortschrittstest-Intervall bestätigen (default 6 Wochen)
      Schritt 5: Einstufungstest starten oder später

### Technisch
- [ ] AIService.php: Abstraktion für Claude / OpenAI / Gemini
- [ ] TTSService.php: Abstraktion für OpenAI TTS / Google / Browser
- [ ] EncryptionService.php: AES-256 für API-Keys
- [ ] WordGeneratorService.php: KI generiert Wörter auf Basis Lehrplan-JSON
- [ ] Chart.js Integration für Fortschrittsgrafiken
- [ ] Theme-System: theme.json laden und anwenden
- [ ] User-Datenexport (ZIP: SQLite-Dump gefiltert auf User)
- [ ] README.md: YunoHost-Installationsanleitung
- [ ] .env.example mitliefern (nie echte Keys committen)
- [ ] Datenschutzkonzept für Mehrnutzer-Betrieb (TODO wenn Open Source)

### Language-aware Architektur (Englisch vorbereitet, nicht implementiert)

`words` Tabelle: `language VARCHAR(10) DEFAULT 'de'`

Eigene `categories` Tabelle statt hardcodierter Enums:
```sql
CREATE TABLE categories (
  code          VARCHAR(10) PRIMARY KEY, -- 'A1', 'B2'
  language      VARCHAR(10) DEFAULT 'de',
  block         VARCHAR(5),              -- 'A', 'B', 'C', 'D'
  label         VARCHAR(100),
  description   TEXT,
  sort_order    INTEGER
);
```

Lehrplan-JSONs mit `language` Feld:
```json
{ "language": "de", "federal_state": "Bayern", ... }
```

Wenn Englisch kommt: nur neue Curriculum-JSONs +
Kategoriedefinitionen anlegen — kein Umbau nötig.

---

## Implementierungsreihenfolge (empfohlen)

1.  Datenbankschema anlegen (schema.sql)
2.  Basis-Auth (Login / Session / Rollen)
3.  Setup-Wizard (Bundesland, Klasse, API-Keys, Theme)
4.  TTSService + AIService + EncryptionService Abstraktionen
5.  Lehrplan-JSONs anlegen (bayern_gs_3_4.json etc.)
6.  WordGeneratorService (KI generiert Wörter auf Basis Lehrplan)
7.  Einstufungstest (UI + Logik)
8.  KI-Auswertung + Fehlerprofil
9.  Questlog-UI (Abenteuermap, Biome, Quests)
10. Übungseinheit (alle 4 Formate)
11. KI-Feedback nach Einheit + Plan-Amendment
12. Papa-Dashboard + Fortschrittsgrafiken (Chart.js)
13. Achievements + Theme-Freischaltungen
14. PDF-Export Lehrerin
15. Fortschrittstest-Logik
