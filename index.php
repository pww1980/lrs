<?php
/**
 * Lennarts Diktat-Trainer — Router / Entry Point
 *
 * Alle Anfragen laufen durch diese Datei (via .htaccess RewriteRule).
 */

require_once __DIR__ . '/config/app.php';

// Autoloader (einfach, ohne Composer — wird später ggf. durch Composer ersetzt)
spl_autoload_register(function (string $class): void {
    // Namespace App\ → src/
    $path = __DIR__ . '/src/' . str_replace(['App\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

// Session starten (sicher konfiguriert)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,            // Browser-Session
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// CSRF-Token für die Session vorbereiten
use App\Helpers\Auth;

// Aktuellen Pfad bestimmen
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri    = '/' . ltrim($uri, '/');

// Query-Routing: /index.php?_r=/pfad (YunoHost-Kompatibilität ohne try_files)
if (isset($_GET['_r'])) {
    $uri = '/' . ltrim($_GET['_r'], '/');
    unset($_GET['_r']);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Setup-Redirect: wenn noch kein Superadmin existiert ──────────────

$isStaticPath = str_starts_with($uri, '/public/')
             || str_starts_with($uri, '/css/')
             || str_starts_with($uri, '/js/')
             || str_starts_with($uri, '/themes/');

if (!$isStaticPath) {
    try {
        $count = db()->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
        if ((int)$count === 0 && $uri !== '/setup') {
            redirect('/setup');
        }
    } catch (\RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'PERMISSIONS_ERROR:')) {
            $dir = substr($e->getMessage(), strlen('PERMISSIONS_ERROR:'));
            showPermissionsError($dir);
        }
        // Andere RuntimeExceptions: Datenbank-Problem → Setup
        if ($uri !== '/setup') {
            redirect('/setup');
        }
    }
}

function showPermissionsError(string $dir): never
{
    http_response_code(500);
    echo <<<HTML
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
    <title>Einrichtung erforderlich</title>
    <link rel="stylesheet" href="/public/css/app.css">
    <style>
      body { background:#f5f5f5; display:flex; align-items:center; justify-content:center; min-height:100vh; }
      .box { background:#fff; border-radius:12px; padding:2rem; max-width:560px; box-shadow:0 4px 16px rgba(0,0,0,.1); }
      h2 { color:#c62828; margin-top:0 }
      code { background:#f5f5f5; padding:.2rem .5rem; border-radius:4px; font-size:.95rem; word-break:break-all }
      .cmd { background:#263238; color:#aed581; padding:1rem; border-radius:8px; font-family:monospace; margin:.75rem 0; font-size:.9rem; overflow-x:auto }
    </style>
    </head><body><div class="box">
      <h2>⚠ Schreibrechte fehlen</h2>
      <p>Der Webserver kann die Datenbank nicht anlegen, weil das Verzeichnis
         <code>{$dir}</code> nicht beschreibbar ist.</p>
      <p>Bitte einmalig per SSH ausführen:</p>
      <div class="cmd">chown -R www-data:www-data {$dir}</div>
      <p>Danach diese Seite neu laden.</p>
    </div></body></html>
    HTML;
    exit;
}

// ── Routing ───────────────────────────────────────────────────────────

$auth = new \App\Controllers\AuthController();

match (true) {

    // Setup (nur wenn noch kein Superadmin)
    $uri === '/setup'
        => (function () {
            require_once __DIR__ . '/setup.php';
        })(),

    // Login
    $uri === '/login' && $method === 'GET'
        => $auth->showLogin(),

    $uri === '/login' && $method === 'POST'
        => $auth->handleLogin(),

    // Logout
    $uri === '/logout'
        => $auth->handleLogout(),

    // Root → Weiterleitung nach Login-Status
    $uri === '/' || $uri === ''
        => (function () use ($auth) {
            if (\App\Controllers\AuthController::isLoggedIn()) {
                \App\Controllers\AuthController::redirectByRole();
            } else {
                redirect('/login');
            }
        })(),

    // Setup-Wizard (läuft nur wenn noch kein Kind existiert — Guard im Controller)
    str_starts_with($uri, '/setup/wizard')
        => (function () {
            (new \App\Controllers\WizardController())->handle();
        })(),

    // Superadmin System-Seite
    $uri === '/admin/system'
        => (function () {
            \App\Helpers\Auth::requireRole('superadmin');
            require BASE_DIR . '/src/Views/admin/system.php';
        })(),

    // Admin anlegen (POST, Superadmin)
    $uri === '/admin/system/create-admin' && $method === 'POST'
        => (function () {
            \App\Helpers\Auth::requireRole('superadmin');
            \App\Helpers\Auth::verifyCsrf();

            $username    = trim($_POST['username']         ?? '');
            $displayName = trim($_POST['display_name']     ?? '');
            $password    = $_POST['password']              ?? '';
            $passwordConf= $_POST['password_confirm']      ?? '';

            $errors = [];
            if ($username === '' || !preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $username)) {
                $errors[] = 'Benutzername: 3–50 Zeichen, nur Buchstaben, Ziffern, _ und -.';
            } else {
                $taken = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                $taken->execute([$username]);
                if ((int)$taken->fetchColumn() > 0) {
                    $errors[] = 'Benutzername "' . htmlspecialchars($username) . '" ist bereits vergeben.';
                }
            }
            if ($displayName === '') $errors[] = 'Anzeigename ist erforderlich.';
            if (strlen($password) < 6) $errors[] = 'Passwort mind. 6 Zeichen.';
            if ($password !== $passwordConf) $errors[] = 'Passwörter stimmen nicht überein.';

            if (!empty($errors)) {
                $_SESSION['system_errors'] = $errors;
                redirect('/admin/system');
            }

            db()->prepare(
                'INSERT INTO users (username, display_name, password_hash, role, active)
                 VALUES (?, ?, ?, \'admin\', 1)'
            )->execute([$username, $displayName, password_hash($password, PASSWORD_BCRYPT)]);

            $_SESSION['system_success'] = "✅ Admin \"$displayName\" wurde angelegt.";
            redirect('/admin/system');
        })(),

    // Admin sperren/aktivieren (POST, Superadmin)
    $uri === '/admin/system/toggle-admin' && $method === 'POST'
        => (function () {
            \App\Helpers\Auth::requireRole('superadmin');
            \App\Helpers\Auth::verifyCsrf();
            $adminId = (int)($_POST['admin_id'] ?? 0);
            if ($adminId > 0) {
                db()->prepare(
                    'UPDATE users SET active = CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=? AND role=\'admin\''
                )->execute([$adminId]);
            }
            redirect('/admin/system');
        })(),

    // Admin Dashboard
    str_starts_with($uri, '/admin/dashboard')
        => \App\Controllers\DashboardController::show(),

    // Lehrerin-Bericht (PDF-fähige HTML-Seite)
    preg_match('#^/admin/report/(\d+)$#', $uri, $m)
        => \App\Controllers\ReportController::show((int)$m[1]),

    // TTS-Cache vorwärmen (AJAX GET, Admin)
    $uri === '/admin/tts/warm' && $method === 'GET'
        => (function () {
            \App\Helpers\Auth::requireRole('admin', 'superadmin');
            header('Content-Type: application/json');

            $adminId = (int)$_SESSION['user_id'];
            $offset  = max(0, (int)($_GET['offset'] ?? 0));
            $limit   = 5;
            $speeds  = ['normal', 'slow'];

            // Alle aktiven Wörter zählen
            $total = (int)db()->query("SELECT COUNT(*) FROM words WHERE active=1")->fetchColumn();

            // Batch laden
            $stmt = db()->prepare("SELECT id, word FROM words WHERE active=1 ORDER BY id LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);
            $words = $stmt->fetchAll();

            $done    = 0;
            $errors  = 0;
            $skipped = 0;

            try {
                $tts = new \App\Services\TTSService($adminId);
                if ($tts->isBrowserTTS()) {
                    echo json_encode(['done' => $total, 'total' => $total, 'skipped' => $total,
                                      'errors' => 0, 'provider' => 'browser']);
                    exit;
                }

                foreach ($words as $word) {
                    foreach ($speeds as $speed) {
                        try {
                            $result = $tts->synthesizeCached($word['word'], $speed);
                            if ($result) {
                                $done += ($result['cached'] ?? false) ? 0 : 1;
                                $skipped += ($result['cached'] ?? false) ? 1 : 0;
                            }
                        } catch (\Throwable) {
                            $errors++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                echo json_encode(['error' => $e->getMessage()]);
                exit;
            }

            echo json_encode([
                'offset'  => $offset + $limit,
                'total'   => $total,
                'done'    => $done,
                'skipped' => $skipped,
                'errors'  => $errors,
                'finished' => ($offset + $limit) >= $total,
            ]);
            exit;
        })(),

    // Plan bestätigen (AJAX POST)
    $uri === '/admin/plan/approve' && $method === 'POST'
        => \App\Controllers\DashboardController::approvePlan(),

    // Quest ein-/ausschalten (AJAX POST)
    $uri === '/admin/plan/quest-toggle' && $method === 'POST'
        => \App\Controllers\DashboardController::toggleQuest(),

    // KI-Auswertung (Admin-seitig, AJAX POST)
    $uri === '/admin/analysis/run' && $method === 'POST'
        => \App\Controllers\AnalysisController::runForAdmin(),

    // KI-Auswertung (Kind-seitig, AJAX POST)
    $uri === '/learn/test/analyze' && $method === 'POST'
        => \App\Controllers\AnalysisController::runForChild(),

    // Superadmin System
    str_starts_with($uri, '/admin/system')
        => (function () {
            \App\Helpers\Auth::requireRole('superadmin');
            require __DIR__ . '/src/Views/admin/system.php';
        })(),

    // ── Einstufungstest ──────────────────────────────────────────────────

    // TTS-Audio (GET, vor den POST-Routen damit kein Konflikt)
    $uri === '/learn/test/tts' && $method === 'GET'
        => \App\Controllers\TestController::getTts(),

    // Antwort einreichen (AJAX POST)
    $uri === '/learn/test/answer' && $method === 'POST'
        => \App\Controllers\TestController::submitAnswer(),

    // Sektion abschließen (AJAX POST)
    $uri === '/learn/test/section-complete' && $method === 'POST'
        => \App\Controllers\TestController::completeSection(),

    // Test pausieren (AJAX POST)
    $uri === '/learn/test/pause' && $method === 'POST'
        => \App\Controllers\TestController::pauseTest(),

    // Ergebnisseite (GET)
    $uri === '/learn/test/results' && $method === 'GET'
        => \App\Controllers\TestController::showResults(),

    // Test-Hauptseite: GET zeigt, POST startet
    $uri === '/learn/test' && $method === 'GET'
        => \App\Controllers\TestController::show(),

    $uri === '/learn/test' && $method === 'POST'
        => \App\Controllers\TestController::startTest(),

    // ── Questlog (Abenteuermap) ──────────────────────────────────────────
    $uri === '/learn/questlog' && $method === 'GET'
        => \App\Controllers\SessionController::showQuestlog(),

    // ── Übungseinheit ────────────────────────────────────────────────────

    // TTS (GET, vor den anderen Session-Routen)
    $uri === '/learn/session/tts' && $method === 'GET'
        => \App\Controllers\SessionController::getTts(),

    // Session starten (Form POST)
    $uri === '/learn/session/start' && $method === 'POST'
        => \App\Controllers\SessionController::startSession(),

    // Antwort einreichen (AJAX POST)
    $uri === '/learn/session/answer' && $method === 'POST'
        => \App\Controllers\SessionController::submitAnswer(),

    // Session abschließen (AJAX POST)
    $uri === '/learn/session/complete' && $method === 'POST'
        => \App\Controllers\SessionController::completeSession(),

    // KI-Feedback nach Session (AJAX POST, asynchron)
    $uri === '/learn/session/feedback' && $method === 'POST'
        => \App\Controllers\SessionController::getFeedback(),

    // Session-Hauptseite (GET)
    $uri === '/learn/session' && $method === 'GET'
        => \App\Controllers\SessionController::show(),

    // ── Lernbereich (Kind-Startseite / Smart-Redirect) ───────────────────
    str_starts_with($uri, '/learn')
        => (function () {
            \App\Helpers\Auth::requireRole('child');
            require __DIR__ . '/src/Views/learn/index.php';
        })(),

    // 404
    default => (function () use ($uri) {
        http_response_code(404);
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
              <title>Seite nicht gefunden</title>
              <link rel="stylesheet" href="/public/css/app.css">
              </head><body><div class="container">
              <h1>404 — Seite nicht gefunden</h1>
              <p><a href="/">Zurück zur Startseite</a></p>
              </div></body></html>';
    })(),

};
