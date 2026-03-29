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

            // /data-Verzeichnis anlegen falls nötig
            if (!is_dir(DATA_DIR)) {
                mkdir(DATA_DIR, 0755, true);
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

        // FETCH_ASSOC auch für die neue Verbindung setzen
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
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
