<?php
/**
 * TTS-Cache vorwärmen — für alle aktiven Wörter Audio-Dateien generieren.
 *
 * Aufruf:
 *   php database/warm_tts.php [admin_user_id]
 *
 * Cron-Beispiel (täglich 3 Uhr):
 *   0 3 * * * php /var/www/lrs/database/warm_tts.php >> /var/log/lrs_tts.log 2>&1
 *
 * Der Admin-User-ID bestimmt welcher API-Key genutzt wird (Standard: erster Admin).
 */

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');
define('DB_FILE',  DATA_DIR . '/lerntrainer.sqlite');

require_once BASE_DIR . '/config/app.php';

// Nur CLI
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Nur per CLI ausführen.\n");
}

// Admin-User bestimmen
$adminId = isset($argv[1]) ? (int)$argv[1] : 0;

if ($adminId === 0) {
    $row = db()->query("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1")->fetch();
    if (!$row) {
        $row = db()->query("SELECT id FROM users WHERE role='superadmin' AND active=1 ORDER BY id LIMIT 1")->fetch();
    }
    if (!$row) {
        echo "Kein Admin-User gefunden.\n";
        exit(1);
    }
    $adminId = (int)$row['id'];
}

echo "Nutze Admin-ID: $adminId\n";

// TTS-Service initialisieren
try {
    $tts = new \App\Services\TTSService($adminId);
} catch (\Throwable $e) {
    echo "TTS-Service Fehler: " . $e->getMessage() . "\n";
    exit(1);
}

if ($tts->isBrowserTTS()) {
    echo "Browser-TTS konfiguriert — kein Server-seitiger Cache nötig.\n";
    exit(0);
}

// Alle aktiven Wörter laden
$words = db()->query("SELECT id, word FROM words WHERE active=1 ORDER BY id")->fetchAll();
$total = count($words);
echo "Wörter gesamt: $total\n";

$cached = 0;
$fresh  = 0;
$errors = 0;

foreach ($words as $i => $w) {
    foreach (['normal', 'slow'] as $speed) {
        try {
            $result = $tts->synthesizeCached($w['word'], $speed);
            if ($result) {
                if ($result['cached'] ?? false) {
                    $cached++;
                } else {
                    $fresh++;
                    echo sprintf("[%d/%d] ✓ %s (%s)\n", $i + 1, $total, $w['word'], $speed);
                }
            }
        } catch (\Throwable $e) {
            $errors++;
            echo sprintf("[%d/%d] ✗ %s (%s): %s\n", $i + 1, $total, $w['word'], $speed, $e->getMessage());
        }
    }
    // Kurze Pause um API-Rate-Limits zu vermeiden
    if ($fresh > 0 && $fresh % 10 === 0) {
        usleep(500000); // 0.5s
    }
}

echo "\nFertig: $fresh neu generiert, $cached bereits gecacht, $errors Fehler.\n";
exit($errors > 0 ? 1 : 0);
