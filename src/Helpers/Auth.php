<?php

namespace App\Helpers;

/**
 * Auth-Middleware Hilfsfunktionen
 *
 * Schützt Seiten und prüft Rollen.
 * Wird am Anfang jeder geschützten Route aufgerufen.
 */
class Auth
{
    /**
     * Stellt sicher dass ein User eingeloggt ist.
     * Leitet sonst auf /login um.
     */
    public static function require(): void
    {
        if (!isset($_SESSION['user_id'])) {
            // Ursprüngliche URL merken für Post-Login-Redirect
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            redirect('/login');
        }
    }

    /**
     * Stellt sicher dass der User eine der angegebenen Rollen hat.
     * Zeigt sonst 403-Seite.
     */
    public static function requireRole(string ...$roles): void
    {
        self::require();
        if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
            http_response_code(403);
            self::show403();
            exit;
        }
    }

    /**
     * Generiert ein CSRF-Token für die Session (einmalig pro Session).
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Prüft das CSRF-Token aus dem POST-Request.
     * Bricht bei Ungültigkeit mit 403 ab.
     */
    public static function verifyCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die('Ungültige Anfrage (CSRF). Bitte Seite neu laden.');
        }
    }

    private static function show403(): void
    {
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
              <title>Kein Zugriff</title></head><body>
              <h1>403 — Kein Zugriff</h1>
              <p>Du hast keine Berechtigung für diese Seite.</p>
              <a href="/">Zurück zur Startseite</a></body></html>';
    }
}
