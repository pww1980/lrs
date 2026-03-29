<?php
/**
 * Lennarts Diktat-Trainer — Ersteinrichtung
 *
 * Legt den ersten Superadmin-Account an.
 * Läuft NUR wenn noch kein Superadmin in der Datenbank existiert.
 * Danach ist diese Seite dauerhaft deaktiviert.
 */

require_once __DIR__ . '/config/app.php';

session_start();

// ── Guard: Nur ausführen wenn noch kein Superadmin existiert ──────────

function superadminExists(): bool
{
    try {
        $count = db()->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
        return (int)$count > 0;
    } catch (\Exception) {
        return false;
    }
}

// Datenbank noch nicht angelegt?
if (!file_exists(DB_FILE)) {
    die(renderError(
        'Datenbank nicht gefunden',
        'Bitte zuerst <code>php database/migrate.php</code> ausführen.'
    ));
}

if (superadminExists()) {
    // Setup bereits abgeschlossen — Seite ist dauerhaft deaktiviert
    redirect('/login');
}

// ── CSRF Token ────────────────────────────────────────────────────────

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

$errors  = [];
$success = false;

// ── POST verarbeiten ──────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF prüfen
    if (!hash_equals($_SESSION['setup_csrf'], $_POST['csrf_token'] ?? '')) {
        die(renderError('Ungültige Anfrage', 'Bitte Seite neu laden.'));
    }

    $username     = trim($_POST['username']     ?? '');
    $displayName  = trim($_POST['display_name'] ?? '');
    $password     = $_POST['password']          ?? '';
    $passwordConf = $_POST['password_confirm']  ?? '';

    // Validierung
    if ($username === '') {
        $errors[] = 'Benutzername ist erforderlich.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $username)) {
        $errors[] = 'Benutzername: 3–50 Zeichen, nur Buchstaben, Ziffern, _ und -.';
    }

    if ($displayName === '') {
        $errors[] = 'Name (Anzeigename) ist erforderlich.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Passwort muss mindestens 8 Zeichen lang sein.';
    }

    if ($password !== $passwordConf) {
        $errors[] = 'Passwörter stimmen nicht überein.';
    }

    // Encryption Key prüfen
    if (strlen(APP_ENCRYPTION_KEY) < 16) {
        $errors[] = 'APP_ENCRYPTION_KEY fehlt oder zu kurz. Bitte .env prüfen.';
    }

    if (empty($errors)) {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = db()->prepare(
                'INSERT INTO users (username, display_name, password_hash, role, active)
                 VALUES (?, ?, ?, \'superadmin\', 1)'
            );
            $stmt->execute([$username, $displayName, $hash]);

            // Setup-Session löschen
            unset($_SESSION['setup_csrf']);

            $success = true;
        } catch (\PDOException $e) {
            $errors[] = 'Datenbankfehler: ' . $e->getMessage();
        }
    }
}

// ── HTML ausgeben ─────────────────────────────────────────────────────

function renderError(string $title, string $body): string
{
    return <<<HTML
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
    <title>Fehler — Setup</title>
    <link rel="stylesheet" href="/css/app.css">
    </head><body class="setup-page">
    <div style="background:#fff;padding:2rem;border-radius:8px;max-width:480px;margin:auto;margin-top:20vh">
      <h2 style="color:#c62828">⚠ $title</h2>
      <p style="margin-top:.75rem">$body</p>
    </div></body></html>
    HTML;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ersteinrichtung — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body class="setup-page">

<main class="setup-box" style="margin:auto; margin-top: min(10vh, 80px)">

  <?php if ($success): ?>

    <div style="text-align:center; padding: 1rem 0">
      <span style="font-size:3rem">🎉</span>
      <h1 style="color:#2e7d32; margin-top:.5rem">Einrichtung abgeschlossen!</h1>
      <p style="color:#555; margin: .75rem 0 1.5rem">
        Superadmin-Account wurde angelegt.
      </p>
      <a href="<?= url('/login') ?>" class="btn btn-primary">Zum Login</a>
      <p style="font-size:.8rem; color:#888; margin-top:1.5rem">
        Diese Seite ist ab sofort dauerhaft deaktiviert.
      </p>
    </div>

  <?php else: ?>

    <h1>🔧 Ersteinrichtung</h1>
    <p class="subtitle">Legt den ersten Superadmin-Account an.</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error">
        <strong>Bitte folgende Fehler korrigieren:</strong>
        <ul style="margin:.5rem 0 0 1.2rem">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= url('/setup') ?>" novalidate>
      <input type="hidden" name="csrf_token"
             value="<?= htmlspecialchars($_SESSION['setup_csrf'] ?? '') ?>">

      <div class="form-group">
        <label for="username">Benutzername</label>
        <input type="text" id="username" name="username" required autofocus
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               placeholder="z.B. patrick">
      </div>

      <div class="form-group">
        <label for="display_name">Name (Anzeigename)</label>
        <input type="text" id="display_name" name="display_name" required
               value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
               placeholder="z.B. Patrick">
      </div>

      <div class="form-group">
        <label for="password">Passwort <small style="color:#888">(min. 8 Zeichen)</small></label>
        <input type="password" id="password" name="password" required
               autocomplete="new-password">
      </div>

      <div class="form-group">
        <label for="password_confirm">Passwort bestätigen</label>
        <input type="password" id="password_confirm" name="password_confirm" required
               autocomplete="new-password">
      </div>

      <button type="submit" class="btn btn-primary btn-block">
        Superadmin anlegen
      </button>
    </form>

  <?php endif; ?>

</main>

</body>
</html>
