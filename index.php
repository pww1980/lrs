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
             || str_starts_with($uri, '/js/');

if ($uri !== '/setup' && !$isStaticPath) {
    try {
        $count = db()->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
        if ((int)$count === 0) {
            redirect('/setup');
        }
    } catch (\RuntimeException) {
        // Datenbank noch nicht angelegt
        redirect('/setup');
    }
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

    // Admin Dashboard
    str_starts_with($uri, '/admin/dashboard')
        => \App\Controllers\DashboardController::show(),

    // Lehrerin-Bericht (PDF-fähige HTML-Seite)
    preg_match('#^/admin/report/(\d+)$#', $uri, $m)
        => \App\Controllers\ReportController::show((int)$m[1]),

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
              <link rel="stylesheet" href="/css/app.css">
              </head><body><div class="container">
              <h1>404 — Seite nicht gefunden</h1>
              <p><a href="/">Zurück zur Startseite</a></p>
              </div></body></html>';
    })(),

};
