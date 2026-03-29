<?php
/**
 * Questlog — Abenteuermap für das Kind
 *
 * Variablen (von SessionController::showQuestlog() bereitgestellt):
 *   $activePlan    array|null   — aktiver Lernplan
 *   $biomes        array        — plan_biomes mit quests
 *   $activeUnit    array|null   — aktuell aktive plan_unit (zum Üben)
 *   $theme         array        — theme.json Inhalt
 *   $childName     string
 */

$pageTitle = 'Abenteuermap — ' . APP_NAME;
$themeName = $_SESSION['theme'] ?? 'minecraft';
$csrfToken = \App\Helpers\Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    /* ── Questlog Layout ── */
    .questlog-header {
      background: linear-gradient(135deg, #2d5016 0%, #3d7a1a 50%, #4a9220 100%);
      color: #fff;
      padding: 1.25rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      border-bottom: 3px solid #1a3a08;
    }
    .questlog-header .logo { font-size: 2rem; }
    .questlog-header h1 { font-size: 1.3rem; font-weight: 700; margin: 0; }
    .questlog-header .subtitle { font-size: 0.85rem; opacity: 0.85; margin-top: 0.1rem; }
    .header-right { margin-left: auto; display: flex; align-items: center; gap: 0.75rem; }
    .header-right a { color: #b9f0a0; font-size: 0.85rem; text-decoration: none; }
    .header-right a:hover { text-decoration: underline; }

    /* ── Map Container ── */
    .map-container {
      max-width: 800px;
      margin: 2rem auto;
      padding: 0 1rem;
    }

    .map-title {
      text-align: center;
      margin-bottom: 2rem;
    }
    .map-title h2 { font-size: 1.5rem; color: #2d5016; }
    .map-title p  { color: #666; margin-top: 0.25rem; }

    /* ── Biome Card ── */
    .biome-card {
      border-radius: 12px;
      margin-bottom: 1.5rem;
      overflow: hidden;
      box-shadow: 0 3px 12px rgba(0,0,0,.15);
      transition: transform 0.2s;
    }
    .biome-card.locked {
      opacity: 0.55;
      filter: grayscale(50%);
    }
    .biome-card:not(.locked):hover {
      transform: translateY(-2px);
    }

    .biome-header {
      padding: 1rem 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      color: #fff;
      position: relative;
    }
    .biome-header.forest  { background: linear-gradient(135deg, #2d6a1f, #4a9228); }
    .biome-header.desert  { background: linear-gradient(135deg, #8a6914, #c4960a); }
    .biome-header.nether  { background: linear-gradient(135deg, #8a1414, #c43a0a); }
    .biome-header.the_end { background: linear-gradient(135deg, #2a1a5e, #5a2d9a); }
    .biome-header.locked-bg { background: linear-gradient(135deg, #555, #777); }

    .biome-icon { font-size: 2rem; }
    .biome-name { font-size: 1.1rem; font-weight: 700; }
    .biome-block { font-size: 0.8rem; opacity: 0.85; }
    .biome-status-badge {
      margin-left: auto;
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 700;
      background: rgba(255,255,255,0.2);
    }
    .biome-status-badge.active   { background: rgba(255,255,255,0.3); }
    .biome-status-badge.completed { background: #4caf50; }
    .biome-status-badge.locked   { background: rgba(0,0,0,0.3); }

    .biome-lock-overlay {
      position: absolute;
      right: 1.25rem;
      font-size: 1.5rem;
      opacity: 0.7;
    }

    /* ── Quest List ── */
    .quest-list {
      background: #fff;
      padding: 0.75rem 1rem;
    }

    .quest-item {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.75rem 0.5rem;
      border-bottom: 1px solid #f0f0f0;
      border-radius: 8px;
      transition: background 0.15s;
    }
    .quest-item:last-child { border-bottom: none; }
    .quest-item:hover:not(.locked) { background: #f8f8f8; }

    .quest-icon {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      flex-shrink: 0;
    }
    .quest-icon.active    { background: #e8f5e9; }
    .quest-icon.completed { background: #e8f5e9; }
    .quest-icon.locked    { background: #f0f0f0; }
    .quest-icon.skipped   { background: #fff3e0; }

    .quest-info { flex: 1; }
    .quest-title { font-weight: 600; font-size: 0.95rem; color: #212121; }
    .quest-title.locked  { color: #999; }
    .quest-desc { font-size: 0.8rem; color: #666; margin-top: 0.1rem; }
    .quest-units { font-size: 0.75rem; color: #888; }

    .quest-action { flex-shrink: 0; }

    .btn-practice {
      background: #4caf50;
      color: #fff;
      border: none;
      padding: 0.5rem 1.25rem;
      border-radius: 20px;
      font-size: 0.85rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      transition: background 0.15s, transform 0.1s;
    }
    .btn-practice:hover { background: #388e3c; transform: scale(1.05); }
    .btn-practice.pulse {
      animation: practice-pulse 1.5s ease-in-out infinite;
    }
    @keyframes practice-pulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(76,175,80,0.5); }
      50%       { box-shadow: 0 0 0 8px rgba(76,175,80,0); }
    }

    .badge-completed { color: #4caf50; font-size: 1.3rem; }
    .badge-locked    { color: #bbb;    font-size: 1.1rem; }
    .badge-skipped   { color: #ff9800; font-size: 0.75rem; font-style: italic; }

    /* ── No Plan State ── */
    .no-plan-box {
      text-align: center;
      padding: 3rem 1rem;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 3px 12px rgba(0,0,0,.1);
    }
    .no-plan-box .icon { font-size: 4rem; margin-bottom: 1rem; }
    .no-plan-box h3 { font-size: 1.3rem; color: #555; margin-bottom: 0.5rem; }
    .no-plan-box p { color: #888; }

    /* ── Streak / Stats Bar ── */
    .stats-bar {
      display: flex;
      gap: 1rem;
      justify-content: center;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }
    .stat-chip {
      background: #fff;
      border-radius: 20px;
      padding: 0.4rem 1rem;
      font-size: 0.85rem;
      box-shadow: 0 2px 6px rgba(0,0,0,.1);
      display: flex;
      align-items: center;
      gap: 0.4rem;
      font-weight: 600;
    }
    .stat-chip .icon { font-size: 1rem; }

    /* ── Progress connector ── */
    .biome-connector {
      text-align: center;
      font-size: 1.5rem;
      margin: -0.5rem 0;
      opacity: 0.4;
    }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($themeName) ?>">

<header class="questlog-header">
  <span class="logo">⛏️</span>
  <div>
    <h1>Abenteuermap</h1>
    <div class="subtitle"><?= htmlspecialchars($childName) ?>s Lernreise</div>
  </div>
  <div class="header-right">
    <a href="<?= url('/logout') ?>">Abmelden</a>
  </div>
</header>

<main class="map-container">

  <?php if (!$activePlan): ?>
    <div class="no-plan-box">
      <div class="icon">🗺️</div>
      <h3>Noch keine Abenteuermap</h3>
      <p>Der Einstufungstest muss zuerst ausgewertet und vom Papa bestätigt werden.</p>
      <?php
        // Zeige Link zum Test wenn noch kein Test abgeschlossen
        $hasTest = (bool)db()->prepare(
          "SELECT COUNT(*) FROM tests WHERE user_id=? AND status='completed'"
        )->execute([$_SESSION['user_id']]) &&
          (int)db()->query("SELECT COUNT(*) FROM tests WHERE user_id={$_SESSION['user_id']} AND status='completed'")->fetchColumn() > 0;
      ?>
      <?php if (!$hasTest): ?>
        <br><a href="<?= url('/learn/test') ?>" class="btn-practice" style="display:inline-block;margin-top:1rem;">
          Einstufungstest starten
        </a>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <!-- Stats Bar -->
    <div class="stats-bar">
      <?php if ($streakDays > 0): ?>
        <div class="stat-chip">
          <span class="icon">🔥</span>
          <?= $streakDays ?> Tage Streak
        </div>
      <?php endif; ?>
      <div class="stat-chip">
        <span class="icon">📚</span>
        <?= $totalSessions ?> Einheiten
      </div>
      <div class="stat-chip">
        <span class="icon">✅</span>
        <?= $completedQuests ?>/<?= $totalQuests ?> Quests
      </div>
    </div>

    <div class="map-title">
      <h2>Deine Abenteuermap</h2>
      <p><?= htmlspecialchars($theme['flavor_texts']['welcome'] ?? 'Bereit für dein Abenteuer?') ?></p>
    </div>

    <?php foreach ($biomes as $biomeIndex => $biome):
      $biomeId = $biome['theme_biome'] ?? 'forest';
      $isLocked    = $biome['status'] === 'locked';
      $isActive    = $biome['status'] === 'active';
      $isCompleted = $biome['status'] === 'completed';

      // Biome header class
      $headerClass = in_array($biomeId, ['forest','desert','nether','the_end'])
                    ? $biomeId : 'locked-bg';
      if ($isLocked) $headerClass = 'locked-bg';

      $statusLabel = match($biome['status']) {
        'active'    => 'Aktiv',
        'completed' => '✅ Abgeschlossen',
        default     => '🔒 Gesperrt',
      };
    ?>

      <?php if ($biomeIndex > 0): ?>
        <div class="biome-connector">↕</div>
      <?php endif; ?>

      <div class="biome-card <?= $isLocked ? 'locked' : '' ?>">
        <div class="biome-header <?= htmlspecialchars($headerClass) ?>">
          <span class="biome-icon"><?= htmlspecialchars($biome['icon'] ?? '🌍') ?></span>
          <div>
            <div class="biome-name"><?= htmlspecialchars($biome['name']) ?></div>
            <div class="biome-block">Block <?= htmlspecialchars($biome['block']) ?></div>
          </div>
          <span class="biome-status-badge <?= htmlspecialchars($biome['status']) ?>">
            <?= htmlspecialchars($statusLabel) ?>
          </span>
          <?php if ($isLocked): ?>
            <span class="biome-lock-overlay">🔒</span>
          <?php endif; ?>
        </div>

        <div class="quest-list">
          <?php if (empty($biome['quests'])): ?>
            <p style="color:#999;font-size:0.85rem;padding:0.5rem;">Keine Quests</p>
          <?php endif; ?>

          <?php foreach ($biome['quests'] as $quest):
            $qIsActive    = $quest['status'] === 'active';
            $qIsCompleted = $quest['status'] === 'completed';
            $qIsLocked    = $quest['status'] === 'locked';
            $qIsSkipped   = $quest['status'] === 'skipped';

            // Is this the quest we should practice now?
            $isPracticeTarget = $activeUnit && (int)$activeUnit['quest_id'] === (int)$quest['id'];

            // Quest icon
            $qIcon = match(true) {
              $qIsCompleted => '✅',
              $qIsActive    => '⚔️',
              $qIsSkipped   => '⏭️',
              default       => '🔒',
            };

            // Unit progress
            $doneUnits    = (int)($quest['done_units']    ?? 0);
            $totalUnits   = (int)($quest['total_units']   ?? 0);
            $pendingUnits = (int)($quest['pending_units'] ?? 0);
          ?>
            <div class="quest-item <?= $qIsLocked || $qIsSkipped ? 'locked' : '' ?>">
              <div class="quest-icon <?= htmlspecialchars($quest['status']) ?>">
                <?= $qIcon ?>
              </div>
              <div class="quest-info">
                <div class="quest-title <?= $qIsLocked ? 'locked' : '' ?>">
                  <?= htmlspecialchars($quest['title']) ?>
                </div>
                <?php if ($quest['description']): ?>
                  <div class="quest-desc"><?= htmlspecialchars($quest['description']) ?></div>
                <?php endif; ?>
                <?php if ($totalUnits > 0 && !$qIsLocked): ?>
                  <div class="quest-units">
                    <?= $doneUnits ?>/<?= $totalUnits ?> Einheiten
                    <?php if ($pendingUnits > 0): ?>
                      · <?= $pendingUnits ?> ausstehend
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="quest-action">
                <?php if ($isPracticeTarget && $activeUnit): ?>
                  <a href="<?= url('/learn/session?unit_id=' . (int)$activeUnit['id']) ?>"
                     class="btn-practice pulse">
                    Üben!
                  </a>
                <?php elseif ($qIsCompleted): ?>
                  <span class="badge-completed">✅</span>
                <?php elseif ($qIsSkipped): ?>
                  <span class="badge-skipped">übersprungen</span>
                <?php elseif ($qIsLocked || $isLocked): ?>
                  <span class="badge-locked">🔒</span>
                <?php elseif ($qIsActive): ?>
                  <?php
                    // Find active unit for this quest
                    $unitStmt = db()->prepare(
                      "SELECT id FROM plan_units WHERE quest_id=? AND status='active' LIMIT 1"
                    );
                    $unitStmt->execute([$quest['id']]);
                    $unit = $unitStmt->fetch();
                    if ($unit):
                  ?>
                    <a href="<?= url('/learn/session?unit_id=' . (int)$unit['id']) ?>"
                       class="btn-practice">
                      Üben
                    </a>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php endforeach; ?>

  <?php endif; ?>

</main>

</body>
</html>
