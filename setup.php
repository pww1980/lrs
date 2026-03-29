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

// ── Guard ─────────────────────────────────────────────────────────────

function superadminExists(): bool
{
    try {
        $count = db()->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
        return (int)$count > 0;
    } catch (\RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'PERMISSIONS_ERROR:')) {
            $dir = substr($e->getMessage(), strlen('PERMISSIONS_ERROR:'));
            http_response_code(500);
            die(renderError(
                'Schreibrechte fehlen',
                'Der Webserver kann die Datenbank nicht anlegen.<br><br>'
                . 'Bitte einmalig per SSH ausführen:<br>'
                . '<code class="cmd">chown -R www-data:www-data ' . htmlspecialchars($dir) . '</code>'
                . '<br>Danach diese Seite neu laden.'
            ));
        }
        return false;
    } catch (\Exception) {
        return false;
    }
}

if (superadminExists()) {
    redirect('/login');
}

// ── CSRF ──────────────────────────────────────────────────────────────

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

$errors  = [];
$success = false;
$hasEnvKey = strlen(APP_ENCRYPTION_KEY) >= 16;

// ── POST ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['setup_csrf'], $_POST['csrf_token'] ?? '')) {
        die(renderError('Ungültige Anfrage', 'Bitte Seite neu laden.'));
    }

    $username     = trim($_POST['username']     ?? '');
    $displayName  = trim($_POST['display_name'] ?? '');
    $password     = $_POST['password']          ?? '';
    $passwordConf = $_POST['password_confirm']  ?? '';

    if ($username === '') {
        $errors[] = 'Benutzername ist erforderlich.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $username)) {
        $errors[] = 'Benutzername: 3–50 Zeichen, nur Buchstaben, Ziffern, _ und -.';
    }
    if ($displayName === '') $errors[] = 'Name (Anzeigename) ist erforderlich.';
    if (strlen($password) < 8) $errors[] = 'Passwort muss mindestens 8 Zeichen lang sein.';
    if ($password !== $passwordConf) $errors[] = 'Passwörter stimmen nicht überein.';
    if (!$hasEnvKey) $errors[] = 'APP_ENCRYPTION_KEY fehlt in der .env Datei.';

    if (empty($errors)) {
        try {
            db()->prepare(
                'INSERT INTO users (username, display_name, password_hash, role, active)
                 VALUES (?, ?, ?, \'superadmin\', 1)'
            )->execute([$username, $displayName, password_hash($password, PASSWORD_BCRYPT)]);
            unset($_SESSION['setup_csrf']);
            $success = true;
        } catch (\PDOException $e) {
            $errors[] = 'Datenbankfehler: ' . $e->getMessage();
        }
    }
}

// ── Hilfsfunktion ─────────────────────────────────────────────────────

function renderError(string $title, string $body): string
{
    return <<<HTML
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
    <title>Fehler — Setup</title>
    <link rel="stylesheet" href="/css/app.css">
    <style>.cmd{display:block;background:#263238;color:#aed581;padding:.6rem 1rem;border-radius:6px;margin:.75rem 0;font-family:monospace;font-size:.875rem;word-break:break-all}</style>
    </head><body class="setup-page">
    <div class="setup-card" style="text-align:left">
      <div class="setup-icon">⚠️</div>
      <h2 style="color:#c62828;margin-bottom:.75rem">$title</h2>
      <p style="color:#555;line-height:1.7">$body</p>
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
  <style>
    .setup-page {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 1.5rem;
      background: linear-gradient(145deg, #1b5e20 0%, #2e7d32 45%, #1565c0 100%);
    }
    .setup-card {
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 8px 40px rgba(0,0,0,.25);
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 440px;
      text-align: center;
    }
    .setup-icon {
      font-size: 3.5rem;
      margin-bottom: .5rem;
      line-height: 1;
    }
    .setup-card h1 {
      font-size: 1.5rem;
      color: #1b5e20;
      margin-bottom: .25rem;
    }
    .setup-card .subtitle {
      color: #757575;
      font-size: .875rem;
      margin-bottom: 1.75rem;
    }
    .setup-steps {
      display: flex;
      justify-content: center;
      gap: .5rem;
      margin-bottom: 1.75rem;
    }
    .setup-step {
      display: flex;
      align-items: center;
      gap: .4rem;
      font-size: .8rem;
      color: #9e9e9e;
    }
    .setup-step.active { color: #1b5e20; font-weight: 600; }
    .setup-step .dot {
      width: 22px; height: 22px;
      border-radius: 50%;
      background: #e0e0e0;
      display: flex; align-items: center; justify-content: center;
      font-size: .7rem; font-weight: 700; color: #757575;
    }
    .setup-step.active .dot { background: #2e7d32; color: #fff; }
    .setup-step.done .dot { background: #4caf50; color: #fff; }
    .step-line { width: 24px; height: 2px; background: #e0e0e0; }
    .setup-card .form-group { text-align: left; }
    .env-hint {
      background: <?= $hasEnvKey ? '#e8f5e9' : '#fff8e1' ?>;
      border: 1px solid <?= $hasEnvKey ? '#a5d6a7' : '#ffe082' ?>;
      border-radius: 8px;
      padding: .75rem 1rem;
      font-size: .8rem;
      color: <?= $hasEnvKey ? '#2e7d32' : '#f57f17' ?>;
      text-align: left;
      margin-bottom: 1.25rem;
    }
    .env-hint code {
      font-family: monospace;
      background: rgba(0,0,0,.07);
      padding: .1rem .3rem;
      border-radius: 3px;
    }
    .success-box {
      padding: .5rem 0;
    }
    .success-box .big-icon { font-size: 4rem; margin-bottom: 1rem; }
    .success-box h2 { color: #2e7d32; font-size: 1.4rem; margin-bottom: .5rem; }
    .success-box p { color: #555; margin-bottom: 1.5rem; line-height: 1.6; }
    .success-next {
      font-size: .8rem;
      color: #9e9e9e;
      margin-top: 1.25rem;
    }
  </style>
</head>
<body class="setup-page">
<div class="setup-card">

<?php if ($success): ?>

  <div class="success-box">
    <div class="big-icon">🎉</div>
    <h2>Einrichtung abgeschlossen!</h2>
    <p>Dein Superadmin-Account ist angelegt.<br>
       Jetzt einloggen und den ersten Admin-Account + Kind anlegen.</p>
    <a href="<?= url('/login') ?>" class="btn btn-primary btn-block">Zum Login →</a>
    <p class="success-next">Diese Seite ist ab sofort dauerhaft deaktiviert.</p>
  </div>

<?php else: ?>

  <div class="setup-icon">⛏️</div>
  <h1><?= htmlspecialchars(APP_NAME) ?></h1>
  <p class="subtitle">Ersteinrichtung — Superadmin anlegen</p>

  <!-- Schritt-Indikator -->
  <div class="setup-steps">
    <div class="setup-step active">
      <div class="dot">1</div> Superadmin
    </div>
    <div class="step-line"></div>
    <div class="setup-step">
      <div class="dot">2</div> Admin
    </div>
    <div class="step-line"></div>
    <div class="setup-step">
      <div class="dot">3</div> Kind
    </div>
    <div class="step-line"></div>
    <div class="setup-step">
      <div class="dot">4</div> Fertig
    </div>
  </div>

  <!-- .env-Status -->
  <?php if ($hasEnvKey): ?>
    <div class="env-hint">✅ <code>.env</code> gefunden — API-Keys können sicher gespeichert werden.</div>
  <?php else: ?>
    <div class="env-hint">⚠️ <code>APP_ENCRYPTION_KEY</code> fehlt in <code>.env</code> — API-Keys können nicht gespeichert werden. Bitte zuerst <code>.env</code> anlegen (siehe README).</div>
  <?php endif; ?>

  <!-- Fehler -->
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error" style="text-align:left;margin-bottom:1.25rem">
      <strong>Bitte korrigieren:</strong>
      <ul style="margin:.4rem 0 0 1.1rem">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= url('/setup') ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['setup_csrf'] ?? '') ?>">

    <div class="form-group">
      <label for="username">Benutzername</label>
      <input type="text" id="username" name="username" required autofocus
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
             placeholder="z.B. patrick">
    </div>

    <div class="form-group">
      <label for="display_name">Dein Name</label>
      <input type="text" id="display_name" name="display_name" required
             value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
             placeholder="z.B. Patrick">
    </div>

    <div class="form-group">
      <label for="password">Passwort <small style="color:#9e9e9e">(min. 8 Zeichen)</small></label>
      <input type="password" id="password" name="password" required autocomplete="new-password">
    </div>

    <div class="form-group">
      <label for="password_confirm">Passwort bestätigen</label>
      <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
    </div>

    <button type="submit" class="btn btn-primary btn-block" <?= !$hasEnvKey ? 'disabled title="Bitte zuerst .env anlegen"' : '' ?>>
      Account anlegen →
    </button>
  </form>

<?php endif; ?>
</div>
</body>
</html>
