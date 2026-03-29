<?php
/**
 * /learn — Smart-Redirect für Kinder
 *
 * Logik:
 *  1. Aktiver Test läuft?               → /learn/test
 *  2. Noch kein abgeschlossener Test?   → /learn/test (Einstufungstest)
 *  3. Fortschrittstest fällig + Plan aktiv? → Hinweis-Seite mit Button
 *  4. Plan aktiv?                       → /learn/questlog
 *  5. Kein Plan?                        → Warte-Seite
 */

\App\Helpers\Auth::requireRole('child');
$userId = (int)$_SESSION['user_id'];

// 1. Aktiven Test suchen
$activeTestStmt = db()->prepare(
    "SELECT id FROM tests WHERE user_id=? AND status IN ('pending','in_progress') LIMIT 1"
);
$activeTestStmt->execute([$userId]);
if ($activeTestStmt->fetch()) {
    header('Location: /learn/test');
    exit;
}

// 2. Abgeschlossener Test vorhanden?
$doneTestStmt = db()->prepare(
    "SELECT id FROM tests WHERE user_id=? AND status='completed' ORDER BY completed_at DESC LIMIT 1"
);
$doneTestStmt->execute([$userId]);
$doneTest = $doneTestStmt->fetch();

if (!$doneTest) {
    header('Location: /learn/test');
    exit;
}

// 3. Aktiver Plan vorhanden?
$activePlanStmt = db()->prepare(
    "SELECT id FROM learning_plans WHERE user_id=? AND status='active' LIMIT 1"
);
$activePlanStmt->execute([$userId]);
$activePlan = $activePlanStmt->fetch();

// Fortschrittstest fällig?
$progressDue = \App\Services\ProgressTestService::isDue($userId);

if ($activePlan && $progressDue['due']) {
    // Hinweis anzeigen — kein automatischer Redirect, Kind entscheidet
    // (wird unten in der Seite behandelt)
} elseif ($activePlan) {
    header('Location: /learn/questlog');
    exit;
}

$pageTitle = 'Lernen — ' . APP_NAME;
$themeName = $_SESSION['theme'] ?? 'minecraft';
$csrfToken = \App\Helpers\Auth::csrfToken();

// KI-Auswertung schon gelaufen?
$hasAnalysis = false;
if ($doneTest) {
    $analysisStmt = db()->prepare("SELECT COUNT(*) FROM test_results WHERE test_id=?");
    $analysisStmt->execute([$doneTest['id']]);
    $hasAnalysis = (int)$analysisStmt->fetchColumn() > 0;
}

// Modus bestimmen
$mode = 'waiting'; // waiting | progress_due
if ($activePlan && $progressDue['due']) {
    $mode = 'progress_due';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/css/app.css">
  <style>
    .wait-page {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 50%, #388e3c 100%);
    }
    .wait-box {
      background: #fff;
      border-radius: 16px;
      padding: 2.5rem 2rem;
      max-width: 480px;
      width: 100%;
      text-align: center;
      box-shadow: 0 8px 32px rgba(0,0,0,.2);
    }
    .wait-icon { font-size: 4rem; margin-bottom: 1rem; }
    .wait-box h2 { font-size: 1.4rem; color: #212121; margin-bottom: 0.5rem; }
    .wait-box p  { color: #666; line-height: 1.6; margin-bottom: 0.75rem; }
    .status-chip {
      display: inline-block;
      margin-top: 0.75rem;
      padding: 0.4rem 1rem;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
    }
    .status-chip.pending  { background: #fff3e0; color: #e65100; }
    .status-chip.analysis { background: #e3f2fd; color: #1565c0; }
    .btn-start {
      display: inline-block;
      margin-top: 1.25rem;
      background: #4caf50;
      color: #fff;
      border: none;
      padding: 0.85rem 2rem;
      border-radius: 25px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s;
    }
    .btn-start:hover { background: #388e3c; }
    .btn-later {
      display: block;
      margin-top: 0.75rem;
      color: #888;
      font-size: 0.85rem;
      text-decoration: none;
    }
    .btn-later:hover { color: #555; }
    .logout-link {
      display: block;
      margin-top: 1.5rem;
      color: #bbb;
      font-size: 0.8rem;
      text-decoration: none;
    }
    .logout-link:hover { color: #888; }
    .days-badge {
      display: inline-block;
      background: #ff9800;
      color: #fff;
      border-radius: 20px;
      padding: 0.2rem 0.75rem;
      font-size: 0.8rem;
      font-weight: 700;
      margin-bottom: 0.75rem;
    }
  </style>
</head>
<body>
<div class="wait-page">
  <div class="wait-box">

    <?php if ($mode === 'progress_due'): ?>
      <div class="wait-icon">🏆</div>
      <h2>Fortschrittstest fällig!</h2>
      <?php if ($progressDue['days_overdue'] > 0): ?>
        <span class="days-badge">
          +<?= $progressDue['days_overdue'] ?> Tage überfällig
        </span>
      <?php endif; ?>
      <p>
        Du übst schon <?= $progressDue['interval_days'] ?> Tage —
        Zeit zu sehen wie weit du gekommen bist!<br>
        Der Test dauert ca. 10 Minuten.
      </p>
      <form method="POST" action="/learn/test">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="type"       value="progress">
        <button type="submit" class="btn-start">Fortschrittstest starten 🚀</button>
      </form>
      <a href="/learn/questlog" class="btn-later">Später — weiter üben</a>

    <?php elseif ($hasAnalysis): ?>
      <div class="wait-icon">⏳</div>
      <h2>Dein Abenteuer startet bald!</h2>
      <p>Der Test ist ausgewertet. Papa muss deinen Lernplan nur noch bestätigen.</p>
      <span class="status-chip analysis">✅ Auswertung fertig — wartet auf Papa</span>

    <?php else: ?>
      <div class="wait-icon">⏳</div>
      <h2>Dein Abenteuer startet bald!</h2>
      <p>Dein Test wird gerade ausgewertet. Das dauert nur einen Moment!</p>
      <span class="status-chip pending">⏳ Auswertung läuft...</span>
      <script>setTimeout(function(){ window.location.reload(); }, 30000);</script>
    <?php endif; ?>

    <a href="/logout" class="logout-link">Abmelden</a>
  </div>
</div>
</body>
</html>
