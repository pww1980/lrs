<?php

namespace App\Controllers;

/**
 * Login / Logout Logik
 */
class AuthController
{
    // Zeigt die Login-Seite oder leitet weiter wenn schon eingeloggt
    public function showLogin(): void
    {
        if ($this->isLoggedIn()) {
            $this->redirectByRole();
        }

        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);

        require BASE_DIR . '/src/Views/auth/login.php';
    }

    // Verarbeitet den Login-Formular-POST
    public function handleLogin(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $_SESSION['login_error'] = 'Bitte Benutzername und Passwort eingeben.';
            redirect('/login');
        }

        $user = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $user->execute([$username]);
        $user = $user->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Bewusst unspezifische Fehlermeldung (kein "User nicht gefunden")
            $_SESSION['login_error'] = 'Benutzername oder Passwort falsch.';
            redirect('/login');
        }

        // Session absichern: neue Session-ID nach Login
        session_regenerate_id(true);

        $_SESSION['user_id']      = $user['id'];
        $_SESSION['user_role']    = $user['role'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['theme']        = $user['theme'] ?? 'minecraft';

        // Letzten Login aktualisieren
        db()->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')
           ->execute([$user['id']]);

        $this->redirectByRole();
    }

    // Logout
    public function handleLogout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        redirect('/login');
    }

    // Prüft ob ein User eingeloggt ist
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    // Prüft ob der aktuelle User eine bestimmte Rolle hat
    public static function hasRole(string ...$roles): bool
    {
        return in_array($_SESSION['user_role'] ?? '', $roles, true);
    }

    // Leitet nach Rolle weiter
    public static function redirectByRole(): never
    {
        $target = match ($_SESSION['user_role'] ?? '') {
            'superadmin' => '/admin/system',
            'admin'      => '/admin/dashboard',
            'child'      => '/learn',
            default      => '/login',
        };
        redirect($target);
    }
}
