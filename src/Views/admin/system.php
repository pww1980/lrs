<?php
// Superadmin System-Übersicht
$pageTitle = 'Systemübersicht — ' . APP_NAME;

// Admins laden
$admins = db()->query(
    "SELECT id, username, display_name, active, last_login,
            (SELECT COUNT(*) FROM child_admins WHERE admin_id = users.id) AS child_count
     FROM users WHERE role = 'admin' ORDER BY display_name"
)->fetchAll();

// Kinder laden
$children = db()->query(
    "SELECT u.id, u.username, u.display_name, u.grade_level, u.active, u.last_login,
            (SELECT display_name FROM users a
               JOIN child_admins ca ON a.id = ca.admin_id
              WHERE ca.child_id = u.id AND ca.role = 'primary' LIMIT 1) AS primary_admin
     FROM users u WHERE u.role = 'child' ORDER BY u.display_name"
)->fetchAll();

$errors  = $_SESSION['system_errors']  ?? [];
$success = $_SESSION['system_success'] ?? null;
unset($_SESSION['system_errors'], $_SESSION['system_success']);
$csrfToken = \App\Helpers\Auth::csrfToken();
$wordCount = (int)db()->query("SELECT COUNT(*) FROM words WHERE active=1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
</head>
<body>
<nav class="navbar">
  <span class="navbar-brand"><?= htmlspecialchars(APP_NAME) ?></span>
  <span class="navbar-user">⭐ <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?> (Superadmin)</span>
  <a href="<?= url('/logout') ?>" class="btn btn-sm">Abmelden</a>
</nav>
<main class="container" style="max-width:900px">

  <h2 style="margin-bottom:.5rem">Systemübersicht</h2>
  <p style="margin-bottom:1.5rem">
    <a href="<?= url('/setup/wizard') ?>" class="btn btn-primary">➕ Kind hinzufügen (Wizard)</a>
  </p>

  <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <!-- ══ WORTMATERIAL ══════════════════════════════════════════════════ -->
  <section class="dash-section" style="margin-bottom:1.5rem">
    <div class="dash-section-title">📚 Wortmaterial</div>
    <p style="color:var(--color-muted);margin-bottom:.75rem;font-size:.9rem">
      Aktive Wörter in der Datenbank: <strong id="word-count"><?= $wordCount ?></strong>
      <?php if ($wordCount === 0): ?>
        <span style="color:#e57373"> — Keine Wörter vorhanden! Test kann nicht gestartet werden.</span>
      <?php endif; ?>
    </p>
    <button type="button" class="btn btn-primary" id="btn-seed-words" onclick="seedWords()">
      📥 Beispielwörter laden (225 Wörter für alle Kategorien A1–D4)
    </button>
    <span id="seed-status" style="margin-left:.75rem;font-size:.85rem;color:var(--color-muted)"></span>
    <script>
    function seedWords() {
      var btn = document.getElementById('btn-seed-words');
      var status = document.getElementById('seed-status');
      btn.disabled = true;
      status.textContent = 'Wird geladen…';
      fetch('<?= url('/admin/seed-words') ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= htmlspecialchars($csrfToken) ?>'
      }).then(r => r.json()).then(d => {
        document.getElementById('word-count').textContent = d.total;
        status.style.color = '#2e7d32';
        status.textContent = '✅ Fertig — ' + d.total + ' Wörter aktiv';
        btn.disabled = false;
      }).catch(() => {
        status.style.color = '#e57373';
        status.textContent = '❌ Fehler beim Laden';
        btn.disabled = false;
      });
    }
    </script>
  </section>

  <!-- ══ ADMIN ANLEGEN ══════════════════════════════════════════════════ -->
  <section class="dash-section">
    <div class="dash-section-title">➕ Admin-Account anlegen</div>
    <p style="color:var(--color-muted);margin-bottom:1rem;font-size:.9rem">
      Admins können sich einloggen, Kinder anlegen und den Lernfortschritt überwachen.
    </p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error" style="margin-bottom:1rem">
        <strong>Fehler:</strong>
        <ul style="margin:.25rem 0 0 1rem">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= url('/admin/system/create-admin') ?>" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;max-width:600px">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

      <div class="form-group" style="margin:0">
        <label>Benutzername</label>
        <input type="text" name="username" required
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               placeholder="z.B. patrick">
      </div>
      <div class="form-group" style="margin:0">
        <label>Anzeigename</label>
        <input type="text" name="display_name" required
               value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>"
               placeholder="z.B. Patrick">
      </div>
      <div class="form-group" style="margin:0">
        <label>Passwort <small>(min. 6 Zeichen)</small></label>
        <input type="password" name="password" required autocomplete="new-password">
      </div>
      <div class="form-group" style="margin:0">
        <label>Passwort bestätigen</label>
        <input type="password" name="password_confirm" required autocomplete="new-password">
      </div>
      <div style="grid-column:1/-1">
        <button type="submit" class="btn btn-primary">Admin anlegen</button>
      </div>
    </form>
  </section>

  <!-- ══ ADMINS ÜBERSICHT ═══════════════════════════════════════════════ -->
  <?php if (!empty($admins)): ?>
  <section class="dash-section">
    <div class="dash-section-title">👤 Admins (<?= count($admins) ?>)</div>
    <table class="children-table">
      <thead><tr>
        <th>Anzeigename</th><th>Benutzername</th><th>Kinder</th><th>Letzter Login</th><th>Status</th><th>Aktionen</th>
      </tr></thead>
      <tbody>
        <?php foreach ($admins as $admin): ?>
        <tr>
          <td><strong><?= htmlspecialchars($admin['display_name']) ?></strong></td>
          <td><?= htmlspecialchars($admin['username']) ?></td>
          <td><?= (int)$admin['child_count'] ?></td>
          <td><?= $admin['last_login'] ? date('d.m.Y', strtotime($admin['last_login'])) : '—' ?></td>
          <td><?= $admin['active'] ? '<span class="badge badge-active">Aktiv</span>' : '<span class="badge badge-pending">Gesperrt</span>' ?></td>
          <td>
            <form method="POST" action="<?= url('/admin/system/toggle-admin') ?>" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="admin_id"   value="<?= (int)$admin['id'] ?>">
              <button type="submit" class="btn btn-sm"
                      onclick="return confirm('Wirklich?')">
                <?= $admin['active'] ? 'Sperren' : 'Aktivieren' ?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <!-- ══ KINDER ÜBERSICHT ══════════════════════════════════════════════ -->
  <?php if (!empty($children)): ?>
  <section class="dash-section">
    <div class="dash-section-title">🧒 Alle Kinder (<?= count($children) ?>)</div>
    <table class="children-table">
      <thead><tr>
        <th>Name</th><th>Benutzername</th><th>Klasse</th><th>Admin</th><th>Letzter Login</th><th>Status</th>
      </tr></thead>
      <tbody>
        <?php foreach ($children as $child): ?>
        <tr>
          <td><strong><?= htmlspecialchars($child['display_name']) ?></strong></td>
          <td><?= htmlspecialchars($child['username']) ?></td>
          <td><?= htmlspecialchars($child['grade_level'] ?? '—') ?></td>
          <td><?= htmlspecialchars($child['primary_admin'] ?? '—') ?></td>
          <td><?= $child['last_login'] ? date('d.m.Y', strtotime($child['last_login'])) : '—' ?></td>
          <td><?= $child['active'] ? '<span class="badge badge-active">Aktiv</span>' : '<span class="badge badge-pending">Gesperrt</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

</main>
</body>
</html>
