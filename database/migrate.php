<?php
/**
 * Lennarts Diktat-Trainer — Datenbankmigrierung
 *
 * Legt die SQLite-Datenbank unter /data/lerntrainer.sqlite an
 * und führt das Schema aus.
 *
 * Aufruf: php database/migrate.php
 *         (oder über den Browser, wird von index.php geschützt)
 */

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');
define('DB_FILE',  DATA_DIR . '/lerntrainer.sqlite');
define('SCHEMA',   __DIR__ . '/schema.sql');

// Ausgabe-Hilfsfunktionen (CLI + Browser)
function isCli(): bool
{
    return PHP_SAPI === 'cli';
}

function out(string $msg, string $type = 'info'): void
{
    if (isCli()) {
        $prefix = match ($type) {
            'ok'    => "\033[32m✓\033[0m",
            'error' => "\033[31m✗\033[0m",
            'warn'  => "\033[33m!\033[0m",
            default => "\033[34m→\033[0m",
        };
        echo "$prefix $msg\n";
    } else {
        $color = match ($type) {
            'ok'    => '#2e7d32',
            'error' => '#c62828',
            'warn'  => '#f57f17',
            default => '#1565c0',
        };
        echo "<p style=\"color:$color;font-family:monospace\">$msg</p>\n";
    }
}

// Sicherheitsprüfung: Skript darf nicht über Webserver laufen,
// wenn nicht explizit erlaubt (nur CLI oder internes Setup)
if (!isCli() && !defined('ALLOW_MIGRATE_VIA_WEB')) {
    http_response_code(403);
    die('Direktaufruf über Browser nicht erlaubt. Bitte per CLI ausführen.');
}

// data-Verzeichnis erstellen falls nicht vorhanden
if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0750, true)) {
        out('Konnte /data-Verzeichnis nicht erstellen.', 'error');
        exit(1);
    }
    out('Verzeichnis /data angelegt.', 'ok');
}

// .htaccess zum Schutz der SQLite-Datei
$htaccess = DATA_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
    out('.htaccess in /data angelegt (Schutz der SQLite-Datei).', 'ok');
}

// Schema einlesen
if (!file_exists(SCHEMA)) {
    out('schema.sql nicht gefunden: ' . SCHEMA, 'error');
    exit(1);
}

$sql = file_get_contents(SCHEMA);
if ($sql === false) {
    out('Konnte schema.sql nicht lesen.', 'error');
    exit(1);
}

// SQLite-Verbindung öffnen / anlegen
try {
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
} catch (PDOException $e) {
    out('SQLite-Verbindung fehlgeschlagen: ' . $e->getMessage(), 'error');
    exit(1);
}

out('Datenbankdatei: ' . DB_FILE);

// Schema ausführen — jeden Statement einzeln, damit Fehler präzise gemeldet werden
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => $s !== ''
);

$created  = 0;
$inserted = 0;
$errors   = 0;

foreach ($statements as $stmt) {
    // Leerzeilen und reine Kommentare überspringen
    $clean = preg_replace('/--[^\n]*/', '', $stmt);
    if (trim($clean) === '') {
        continue;
    }

    try {
        $pdo->exec($stmt);

        if (preg_match('/^\s*CREATE TABLE/i', $stmt)) {
            // Tabellenname extrahieren für Log
            preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?(\w+)/i', $stmt, $m);
            out('Tabelle angelegt: ' . ($m[1] ?? '?'), 'ok');
            $created++;
        } elseif (preg_match('/^\s*INSERT/i', $stmt)) {
            $inserted++;
        }
    } catch (PDOException $e) {
        out('Fehler bei Statement: ' . substr($stmt, 0, 80) . '...', 'error');
        out('  → ' . $e->getMessage(), 'error');
        $errors++;
    }
}

// Abschlussbericht
out('');
out("Fertig: $created Tabellen angelegt, $inserted Seed-Inserts, $errors Fehler.",
    $errors === 0 ? 'ok' : 'warn');

// Tabellenübersicht ausgeben
if ($errors === 0) {
    out('');
    out('Vorhandene Tabellen in der Datenbank:');
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        out("  • $t");
    }
}

exit($errors === 0 ? 0 : 1);
