<?php
/**
 * Lennarts Diktat-Trainer — Globale Konfiguration
 *
 * Lädt .env und stellt Konstanten sowie Hilfsfunktionen
 * für die gesamte Applikation bereit.
 */

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');
define('DB_FILE',  DATA_DIR . '/lerntrainer.sqlite');

// .env einlesen (einfaches Key=Value-Format, keine externe Bibliothek)
$envFile = BASE_DIR . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key   = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '') {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

define('APP_NAME',           $_ENV['APP_NAME']           ?? 'Lennarts Diktat-Trainer');
define('APP_ENV',            $_ENV['APP_ENV']            ?? 'production');
define('APP_ENCRYPTION_KEY', $_ENV['APP_ENCRYPTION_KEY'] ?? '');

// Datenbankverbindung (Singleton via statische Variable)
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Datenbank automatisch anlegen wenn noch nicht vorhanden
        if (!file_exists(DB_FILE)) {
            $schemaFile = BASE_DIR . '/database/schema.sql';
            if (!file_exists($schemaFile)) {
                throw new RuntimeException('schema.sql nicht gefunden: ' . $schemaFile);
            }

            // /data-Verzeichnis und Unterverzeichnisse anlegen falls nötig
            if (!is_dir(DATA_DIR)) {
                mkdir(DATA_DIR, 0755, true);
            }
            if (!is_dir(DATA_DIR . '/tts_cache')) {
                mkdir(DATA_DIR . '/tts_cache', 0755, true);
            }

            // Schreibrechte prüfen bevor wir es versuchen
            if (!is_writable(DATA_DIR)) {
                throw new \RuntimeException(
                    'PERMISSIONS_ERROR:' . DATA_DIR
                );
            }

            // SQLite-Datei erstellen und Schema ausführen
            $newPdo = new PDO('sqlite:' . DB_FILE);
            $newPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $newPdo->exec('PRAGMA journal_mode = WAL;');
            $newPdo->exec('PRAGMA foreign_keys = ON;');

            $sql        = file_get_contents($schemaFile);
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => trim(preg_replace('/--[^\n]*/', '', $s)) !== ''
            );
            foreach ($statements as $stmt) {
                $newPdo->exec($stmt);
            }

            $pdo = $newPdo;
        } else {
            $pdo = new PDO('sqlite:' . DB_FILE);
            $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON;');
            $pdo->exec('PRAGMA journal_mode = WAL;');
        }

        // Unterverzeichnisse sicherstellen (auch bei bestehenden Installationen)
        if (!is_dir(DATA_DIR . '/tts_cache')) {
            @mkdir(DATA_DIR . '/tts_cache', 0755, true);
        }

        // FETCH_ASSOC auch für die neue Verbindung setzen
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Schema-Migrationen für bestehende Installationen
        runSchemaMigrations($pdo);
    }
    return $pdo;
}

/**
 * Führt inkrementelle Schema-Migrationen durch.
 * Wird bei jeder DB-Verbindung aufgerufen — muss idempotent sein.
 */
function runSchemaMigrations(PDO $pdo): void
{
    // ── 1. Neue Tabellen anlegen ──────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_adventures (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        child_id     INTEGER NOT NULL REFERENCES users(id),
        created_by   INTEGER NOT NULL REFERENCES users(id),
        title        VARCHAR(200) NOT NULL DEFAULT 'Schulaufgabe',
        school_date  DATE NULL,
        scheduled_date DATE NOT NULL,
        status       TEXT CHECK(status IN ('pending','active','completed','cancelled')) DEFAULT 'pending',
        diktat_generated INTEGER DEFAULT 0,
        ai_notes     TEXT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_adventure_words (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        adventure_id INTEGER NOT NULL REFERENCES custom_adventures(id) ON DELETE CASCADE,
        word         VARCHAR(200) NOT NULL,
        order_index  INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_adventure_sentences (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        adventure_id INTEGER NOT NULL REFERENCES custom_adventures(id) ON DELETE CASCADE,
        sentence     TEXT NOT NULL,
        order_index  INTEGER NOT NULL DEFAULT 0
    )");

    // ── 2. Neue Spalten zu bestehenden Tabellen hinzufügen ────────────────
    // ALTER TABLE ADD COLUMN ist in SQLite idempotent wenn wir vorher prüfen.
    $existingCols = static function (PDO $pdo, string $table): array {
        return array_column(
            $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
    };

    $sessionCols = $existingCols($pdo, 'sessions');
    if (!in_array('custom_adventure_id', $sessionCols, true)) {
        $pdo->exec("ALTER TABLE sessions ADD COLUMN custom_adventure_id INTEGER NULL REFERENCES custom_adventures(id)");
    }

    $itemCols = $existingCols($pdo, 'session_items');
    if (!in_array('custom_text', $itemCols, true)) {
        $pdo->exec("ALTER TABLE session_items ADD COLUMN custom_text VARCHAR(500) NULL");
    }

    // ── 3. sessions.plan_unit_id nullable machen (SQLite table recreation) ─
    // Prüfen ob plan_unit_id noch NOT NULL ist
    $sesColInfo = $pdo->query("PRAGMA table_info(sessions)")->fetchAll(PDO::FETCH_ASSOC);
    $unitColInfo = null;
    foreach ($sesColInfo as $col) {
        if ($col['name'] === 'plan_unit_id') { $unitColInfo = $col; break; }
    }
    if ($unitColInfo && (int)$unitColInfo['notnull'] === 1) {
        // Tabelle neu erstellen mit nullable plan_unit_id
        $pdo->exec("PRAGMA foreign_keys = OFF");
        $pdo->exec("BEGIN");
        $pdo->exec("CREATE TABLE sessions_new (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id              INTEGER NOT NULL REFERENCES users(id),
            plan_unit_id         INTEGER NULL REFERENCES plan_units(id),
            custom_adventure_id  INTEGER NULL REFERENCES custom_adventures(id),
            status               TEXT CHECK(status IN ('active','completed','aborted')) DEFAULT 'active',
            started_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at         DATETIME NULL,
            duration_seconds     INTEGER NULL,
            total_items          INTEGER DEFAULT 0,
            correct_first_try    INTEGER DEFAULT 0,
            correct_second_try   INTEGER DEFAULT 0,
            wrong_total          INTEGER DEFAULT 0,
            fatigue_score        INTEGER NULL,
            motivation_score     INTEGER NULL,
            ai_summary           TEXT NULL,
            ai_next_action       TEXT NULL
        )");
        $pdo->exec("INSERT INTO sessions_new
            (id, user_id, plan_unit_id, status, started_at, completed_at,
             duration_seconds, total_items, correct_first_try, correct_second_try,
             wrong_total, fatigue_score, motivation_score, ai_summary, ai_next_action)
            SELECT id, user_id, plan_unit_id, status, started_at, completed_at,
                   duration_seconds, total_items, correct_first_try, correct_second_try,
                   wrong_total, fatigue_score, motivation_score, ai_summary, ai_next_action
            FROM sessions");
        $pdo->exec("DROP TABLE sessions");
        $pdo->exec("ALTER TABLE sessions_new RENAME TO sessions");
        $pdo->exec("COMMIT");
        $pdo->exec("PRAGMA foreign_keys = ON");
    }
}

// Fehlerausgabe konfigurieren
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

/**
 * Generiert eine interne URL als /index.php?_r=/pfad damit die App
 * ohne nginx try_files funktioniert (YunoHost Custom Webapp).
 * Statische Assets (CSS/JS) direkt übergeben — diese Funktion NICHT für Assets nutzen.
 */
function url(string $path): string
{
    $parts = explode('?', $path, 2);
    $qs    = '_r=' . rawurlencode($parts[0]);
    if (isset($parts[1])) {
        $qs .= '&' . $parts[1];
    }
    return '/index.php?' . $qs;
}

/**
 * Redirect-Helper für interne Weiterleitungen.
 */
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}
