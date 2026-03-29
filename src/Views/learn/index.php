<?php
/**
 * /learn — Smart-Redirect für Kinder
 *
 * Logik:
 *  1. Kein abgeschlossener Test?        → /learn/test
 *  2. Test abgeschlossen, Plan aktiv?   → /learn/questlog
 *  3. Test abgeschlossen, kein Plan?    → Warte-Seite (Papa muss bestätigen)
 */

\App\Helpers\Auth::requireRole('child');
$userId = (int)$_SESSION['user_id'];

// 1. Aktiven oder pending Test suchen
$activeTestStmt = db()->prepare(
    "SELECT id FROM tests WHERE user_id=? AND status IN ('pending','in_progress') LIMIT 1"
);
$activeTestStmt->execute([$userId]);
$activeTest = $activeTestStmt->fetch();

if ($activeTest) {
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
    // Noch kein Test → Einstufungstest starten
    header('Location: /learn/test');
    exit;
}

// 3. Aktiver Plan vorhanden?
$activePlanStmt = db()->prepare(
    "SELECT id FROM learning_plans WHERE user_id=? AND status='active' LIMIT 1"
);
$activePlanStmt->execute([$userId]);
$activePlan = $activePlanStmt->fetch();

if ($activePlan) {
    header('Location: /learn/questlog');
    exit;
}

// Kein aktiver Plan: Test abgeschlossen, aber Papa hat noch nicht bestätigt
// Zeige Warte-Seite
$pageTitle = 'Warten — ' . APP_NAME;
$themeName = $_SESSION['theme'] ?? 'minecraft';

// KI-Auswertung schon gelaufen?
$analysisStmt = db()->prepare(
    "SELECT COUNT(*) FROM test_results WHERE test_id=?"
);
$analysisStmt->execute([$doneTest['id']]);
$hasAnalysis = (int)$analysisStmt->fetchColumn() > 0;
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
      max-width: 440px;
      width: 100%;
      text-align: center;
      box-shadow: 0 8px 32px rgba(0,0,0,.2);
    }
    .wait-icon { font-size: 4rem; margin-bottom: 1rem; }
    .wait-box h2 { font-size: 1.4rem; color: #212121; margin-bottom: 0.5rem; }
    .wait-box p  { color: #666; line-height: 1.6; }
    .status-chip {
      display: inline-block;
      margin-top: 1rem;
      padding: 0.4rem 1rem;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 600;
    }
    .status-chip.pending  { background: #fff3e0; color: #e65100; }
    .status-chip.analysis { background: #e3f2fd; color: #1565c0; }
    .logout-link {
      display: block;
      margin-top: 1.5rem;
      color: #999;
      font-size: 0.85rem;
      text-decoration: none;
    }
    .logout-link:hover { color: #555; }
  </style>
</head>
<body>
<div class="wait-page">
  <div class="wait-box">
    <div class="wait-icon">⏳</div>
    <h2>Dein Abenteuer startet bald!</h2>
    <?php if ($hasAnalysis): ?>
      <p>Der Test ist ausgewertet. Papa muss deinen Lernplan jetzt nur noch bestätigen.</p>
      <span class="status-chip analysis">✅ Auswertung fertig — wartet auf Papa</span>
    <?php else: ?>
      <p>Dein Test wird gerade ausgewertet. Das dauert nur einen Moment!</p>
      <span class="status-chip pending">⏳ Auswertung läuft...</span>
      <script>
        // Seite nach 30 Sekunden neu laden (falls Auswertung noch läuft)
        setTimeout(function() { window.location.reload(); }, 30000);
      </script>
    <?php endif; ?>
    <a href="/logout" class="logout-link">Abmelden</a>
  </div>
</div>
</body>
</html>
