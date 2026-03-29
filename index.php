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
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Setup-Redirect: wenn noch kein Superadmin existiert ──────────────

$isStaticPath = str_starts_with($uri, '/public/')
             || str_starts_with($uri, '/css/')
             || str_starts_with($uri, '/js/');

if ($uri !== '/setup' && !$isStaticPath) {
    try {
        $count = db()->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
        if ((int)$count === 0) {
            header('Location: /setup');
            exit;
        }
    } catch (\RuntimeException) {
        // Datenbank noch nicht angelegt
        header('Location: /setup');
        exit;
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
                header('Location: /login');
                exit;
            }
        })(),

    // Setup-Wizard (läuft nur wenn noch kein Kind existiert — Guard im Controller)
    str_starts_with($uri, '/setup/wizard')
        => (function () {
            (new \App\Controllers\WizardController())->handle();
        })(),

    // Admin Dashboard (leitet zum Wizard wenn noch kein Kind existiert)
    str_starts_with($uri, '/admin/dashboard')
        => (function () {
            \App\Helpers\Auth::requireRole('admin', 'superadmin');
            // Wenn kein Kind existiert → Wizard starten
            $childCount = (int) db()->query(
                "SELECT COUNT(*) FROM users WHERE role = 'child'"
            )->fetchColumn();
            if ($childCount === 0) {
                header('Location: /setup/wizard');
                exit;
            }
            require __DIR__ . '/src/Views/admin/dashboard.php';
        })(),

    // Superadmin System
    str_starts_with($uri, '/admin/system')
        => (function () {
            \App\Helpers\Auth::requireRole('superadmin');
            require __DIR__ . '/src/Views/admin/system.php';
        })(),

    // Lernbereich
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
