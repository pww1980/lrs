<?php
/**
 * Übungseinheit-View
 *
 * Variablen (von SessionController::show() bereitgestellt):
 *   $unit      array   — plan_unit mit format, word_count, difficulty, quest_title, biome_name, theme_biome, category
 *   $session   array|null  — aktive sessions-Zeile
 *   $items     array   — session_items (mit word/sentence)
 *   $progress  array   — {total, answered, correct}
 *   $theme     array   — theme.json
 */

$pageTitle = 'Übungseinheit — ' . APP_NAME;
$themeName = $_SESSION['theme'] ?? 'minecraft';
$csrfToken = \App\Helpers\Auth::csrfToken();

$formatLabel = match($unit['format'] ?? 'word') {
    'gap'        => 'Lückentext',
    'sentence'   => 'Satzdiktat',
    'mini_diktat'=> 'Mini-Diktat',
    default      => 'Einzelwort',
};

$biomeThemeId = $unit['theme_biome'] ?? 'forest';
$biomeHeaderClass = in_array($biomeThemeId, ['forest','desert','nether','the_end'])
                    ? $biomeThemeId : 'forest';

// Biome icon aus theme
$biomeIcon = '🌲';
foreach ($theme['biomes'] ?? [] as $tb) {
    if ($tb['id'] === $biomeThemeId) { $biomeIcon = $tb['icon']; break; }
}

// Items JSON für JS — ohne korrekte Antworten zu exponieren
$itemsForJs = [];
if ($session) {
    foreach ($items as $item) {
        $alreadyDone = $item['final_correct'] !== null;
        $itemsForJs[] = [
            'id'         => (int)$item['id'],
            'format'     => $item['format'],
            'order'      => (int)$item['order_index'],
            'is_done'    => $alreadyDone,
            'is_correct' => $alreadyDone ? (bool)$item['final_correct'] : null,
            // Gap items need to show the sentence context (with blank)
            'gap_context'=> ($item['format'] === 'gap' && $item['sentence'])
                              ? htmlspecialchars($item['sentence'])
                              : null,
        ];
    }
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
    /* ── Session Layout ── */
    .session-header {
      padding: 0.75rem 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      color: #fff;
    }
    .session-header.forest  { background: linear-gradient(135deg, #2d6a1f, #4a9228); }
    .session-header.desert  { background: linear-gradient(135deg, #8a6914, #c4960a); }
    .session-header.nether  { background: linear-gradient(135deg, #8a1414, #c43a0a); }
    .session-header.the_end { background: linear-gradient(135deg, #2a1a5e, #5a2d9a); }

    .session-header .biome-icon { font-size: 1.8rem; }
    .session-header .header-info h2 { font-size: 1rem; font-weight: 700; margin: 0; }
    .session-header .header-info .sub { font-size: 0.8rem; opacity: 0.85; }
    .session-header .back-link {
      margin-left: auto;
      color: rgba(255,255,255,0.85);
      text-decoration: none;
      font-size: 0.85rem;
    }
    .session-header .back-link:hover { color: #fff; }

    /* ── Progress Bar ── */
    .session-progress {
      background: #f5f5f5;
      padding: 0.5rem 1.25rem;
      border-bottom: 1px solid #e0e0e0;
    }
    .progress-bar-outer {
      background: #e0e0e0;
      border-radius: 20px;
      height: 10px;
      overflow: hidden;
    }
    .progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #4caf50, #81c784);
      border-radius: 20px;
      transition: width 0.4s ease;
    }
    .progress-label {
      display: flex;
      justify-content: space-between;
      font-size: 0.75rem;
      color: #666;
      margin-top: 0.25rem;
    }

    /* ── Session Container ── */
    .session-container {
      max-width: 620px;
      margin: 1.5rem auto;
      padding: 0 1rem;
    }

    /* ── Start Screen ── */
    .start-screen {
      text-align: center;
      background: #fff;
      border-radius: 12px;
      padding: 2rem 1.5rem;
      box-shadow: 0 3px 12px rgba(0,0,0,.1);
    }
    .start-screen .icon { font-size: 4rem; margin-bottom: 1rem; }
    .start-screen h2 { font-size: 1.4rem; color: #212121; margin-bottom: 0.5rem; }
    .start-screen p { color: #666; margin-bottom: 1.5rem; }
    .start-screen .meta {
      display: flex;
      gap: 0.75rem;
      justify-content: center;
      flex-wrap: wrap;
      margin-bottom: 1.5rem;
    }
    .meta-chip {
      background: #f0f0f0;
      border-radius: 20px;
      padding: 0.3rem 0.9rem;
      font-size: 0.8rem;
      color: #444;
    }

    /* ── Exercise Area ── */
    #exercise-area {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 3px 12px rgba(0,0,0,.1);
      overflow: hidden;
    }

    .exercise-top {
      padding: 1.5rem;
      text-align: center;
      border-bottom: 1px solid #f0f0f0;
    }

    .tts-area {
      margin-bottom: 1.25rem;
    }
    .tts-btn {
      background: #1976d2;
      color: #fff;
      border: none;
      padding: 0.75rem 1.75rem;
      border-radius: 30px;
      font-size: 1rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      transition: background 0.15s, transform 0.1s;
    }
    .tts-btn:hover { background: #1565c0; transform: scale(1.03); }
    .tts-btn:active { transform: scale(0.98); }
    .tts-btn.loading { background: #90a4ae; cursor: wait; }
    .tts-btn.playing {
      background: #0d47a1;
      animation: tts-pulse 1s ease-in-out infinite;
    }
    @keyframes tts-pulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(25,118,210,0.5); }
      50%       { box-shadow: 0 0 0 8px rgba(25,118,210,0); }
    }

    .tts-slow-btn {
      background: none;
      border: 2px solid #1976d2;
      color: #1976d2;
      padding: 0.4rem 1rem;
      border-radius: 20px;
      font-size: 0.8rem;
      cursor: pointer;
      margin-top: 0.5rem;
      transition: background 0.15s;
    }
    .tts-slow-btn:hover { background: #e3f2fd; }

    /* ── Gap context display ── */
    .gap-context-text {
      font-size: 1.1rem;
      color: #444;
      line-height: 1.7;
      margin: 0.75rem 0;
      background: #f5f5f5;
      padding: 0.75rem 1rem;
      border-radius: 8px;
      text-align: left;
    }
    .gap-blank { font-weight: 700; color: #1976d2; }

    /* ── Answer Input ── */
    .answer-area {
      padding: 1.25rem 1.5rem;
    }
    .answer-label {
      font-size: 0.85rem;
      color: #666;
      margin-bottom: 0.5rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    #answer-input {
      width: 100%;
      padding: 0.85rem 1rem;
      font-size: 1.2rem;
      border: 2px solid #e0e0e0;
      border-radius: 8px;
      outline: none;
      transition: border-color 0.2s;
      font-family: inherit;
    }
    #answer-input:focus { border-color: #1976d2; }
    #answer-input.correct { border-color: #4caf50; background: #f1f8e9; }
    #answer-input.wrong   { border-color: #f44336; background: #ffebee; }
    #answer-input.second-try { border-color: #ff9800; background: #fff8e1; }

    .submit-row {
      display: flex;
      gap: 0.75rem;
      margin-top: 0.75rem;
    }
    #submit-btn {
      flex: 1;
      background: #1976d2;
      color: #fff;
      border: none;
      padding: 0.85rem;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: background 0.15s;
    }
    #submit-btn:hover { background: #1565c0; }
    #submit-btn:disabled { background: #bdbdbd; cursor: default; }

    /* ── Feedback Box ── */
    #feedback-box {
      display: none;
      padding: 1rem 1.5rem;
      border-top: 1px solid #f0f0f0;
    }
    #feedback-box.correct { background: #f1f8e9; border-top-color: #4caf50; }
    #feedback-box.wrong   { background: #ffebee; border-top-color: #f44336; }
    #feedback-box.second-try { background: #fff8e1; border-top-color: #ff9800; }

    .feedback-text { font-weight: 600; font-size: 1rem; margin-bottom: 0.25rem; }
    .feedback-correct { color: #2e7d32; }
    .feedback-wrong   { color: #c62828; }
    .feedback-hint    { font-size: 0.85rem; color: #666; }
    .correct-answer-show { font-size: 0.9rem; color: #555; margin-top: 0.5rem; }
    .correct-answer-show strong { color: #2e7d32; }

    #next-btn {
      margin-top: 0.75rem;
      background: #4caf50;
      color: #fff;
      border: none;
      padding: 0.65rem 1.5rem;
      border-radius: 20px;
      font-size: 0.9rem;
      font-weight: 700;
      cursor: pointer;
      transition: background 0.15s;
    }
    #next-btn:hover { background: #388e3c; }

    /* ── Complete Screen ── */
    #complete-screen {
      display: none;
      text-align: center;
      background: #fff;
      border-radius: 12px;
      padding: 2rem;
      box-shadow: 0 3px 12px rgba(0,0,0,.1);
    }
    #complete-screen .icon { font-size: 4rem; margin-bottom: 0.75rem; }
    #complete-screen h2 { font-size: 1.4rem; color: #2e7d32; margin-bottom: 0.75rem; }
    .complete-stats {
      display: flex;
      gap: 0.75rem;
      justify-content: center;
      margin: 1rem 0;
      flex-wrap: wrap;
    }
    .complete-stat {
      background: #f5f5f5;
      border-radius: 12px;
      padding: 0.75rem 1.25rem;
      text-align: center;
      min-width: 100px;
    }
    .complete-stat .val { font-size: 1.8rem; font-weight: 700; color: #212121; }
    .complete-stat .lbl { font-size: 0.75rem; color: #666; }
    .quest-complete-banner {
      background: #e8f5e9;
      border: 2px solid #4caf50;
      border-radius: 8px;
      padding: 0.75rem;
      font-weight: 700;
      color: #2e7d32;
      margin: 0.75rem 0;
      font-size: 1rem;
    }
    #map-btn {
      background: #4caf50;
      color: #fff;
      border: none;
      padding: 0.85rem 2rem;
      border-radius: 25px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      margin-top: 1rem;
    }
    #map-btn:hover { background: #388e3c; }

    /* ── Item dots ── */
    .item-dots {
      display: flex;
      gap: 4px;
      justify-content: center;
      padding: 0.75rem 1.5rem 0;
      flex-wrap: wrap;
    }
    .item-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #e0e0e0;
      flex-shrink: 0;
    }
    .item-dot.answered-correct { background: #4caf50; }
    .item-dot.answered-wrong   { background: #f44336; }
    .item-dot.current          { background: #1976d2; transform: scale(1.4); }

    /* ── Loading spinner ── */
    .spinner {
      display: inline-block;
      width: 18px; height: 18px;
      border: 2px solid rgba(255,255,255,0.4);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      vertical-align: middle;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($themeName) ?>">

<header class="session-header <?= htmlspecialchars($biomeHeaderClass) ?>">
  <span class="biome-icon"><?= htmlspecialchars($biomeIcon) ?></span>
  <div class="header-info">
    <h2><?= htmlspecialchars($unit['quest_title'] ?? 'Übungseinheit') ?></h2>
    <div class="sub">
      <?= htmlspecialchars($unit['biome_name'] ?? '') ?> ·
      <?= htmlspecialchars($formatLabel) ?> ·
      Kat. <?= htmlspecialchars($unit['category'] ?? '') ?>
    </div>
  </div>
  <a href="/learn/questlog" class="back-link">← Karte</a>
</header>

<!-- Progress bar -->
<?php if ($session): ?>
<div class="session-progress">
  <?php
    $total    = (int)($progress['total']    ?? count($items));
    $answered = (int)($progress['answered'] ?? 0);
    $pct      = $total > 0 ? round($answered / $total * 100) : 0;
  ?>
  <div class="progress-bar-outer">
    <div class="progress-bar-fill" id="progress-fill" style="width:<?= $pct ?>%"></div>
  </div>
  <div class="progress-label">
    <span id="progress-text"><?= $answered ?>/<?= $total ?> Wörter</span>
    <span id="progress-pct"><?= $pct ?>%</span>
  </div>
</div>
<?php endif; ?>

<main class="session-container">

<?php if (!$session): ?>
  <!-- Start Screen -->
  <div class="start-screen">
    <div class="icon"><?= htmlspecialchars($biomeIcon) ?></div>
    <h2><?= htmlspecialchars($unit['quest_title'] ?? 'Übungseinheit') ?></h2>
    <p>Bereit für deine nächste Einheit?</p>
    <div class="meta">
      <span class="meta-chip">📝 <?= htmlspecialchars($formatLabel) ?></span>
      <span class="meta-chip">🎯 <?= (int)$unit['word_count'] ?> Wörter</span>
      <span class="meta-chip">⭐ Schwierigkeit <?= (int)$unit['difficulty'] ?></span>
    </div>
    <form method="POST" action="/learn/session/start">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="unit_id"   value="<?= (int)$unit['id'] ?>">
      <button type="submit" class="btn-practice" style="padding:1rem 2.5rem;font-size:1.1rem;">
        Los geht's! ⚔️
      </button>
    </form>
  </div>

<?php else: ?>

  <!-- Item Dots -->
  <div class="item-dots" id="item-dots">
    <?php foreach ($items as $item): ?>
      <?php
        $dotClass = 'item-dot';
        if ($item['final_correct'] !== null) {
          $dotClass .= $item['final_correct'] ? ' answered-correct' : ' answered-wrong';
        }
      ?>
      <span class="<?= $dotClass ?>"
            data-item-id="<?= (int)$item['id'] ?>"></span>
    <?php endforeach; ?>
  </div>

  <!-- Exercise Area -->
  <div id="exercise-area" style="margin-top:0.75rem;">
    <div class="exercise-top">
      <div class="tts-area">
        <button class="tts-btn" id="tts-btn" type="button">
          <span id="tts-icon">🔊</span>
          <span id="tts-label">Wort anhören</span>
        </button>
        <br>
        <button class="tts-slow-btn" id="tts-slow-btn" type="button">
          🐢 Langsam
        </button>
      </div>
      <!-- Gap context (hidden by default, shown for gap format) -->
      <div class="gap-context-text" id="gap-context" style="display:none;"></div>
    </div>

    <div class="answer-area">
      <div class="answer-label">Deine Antwort:</div>
      <input type="text"
             id="answer-input"
             autocomplete="off"
             autocorrect="off"
             autocapitalize="off"
             spellcheck="false"
             placeholder="Schreibe das Wort hier..."
             inputmode="text">
      <div class="submit-row">
        <button type="button" id="submit-btn" disabled>Prüfen ✓</button>
      </div>
    </div>

    <div id="feedback-box">
      <div class="feedback-text" id="feedback-text"></div>
      <div class="feedback-hint" id="feedback-hint" style="display:none;"></div>
      <div class="correct-answer-show" id="correct-answer-show" style="display:none;"></div>
      <button type="button" id="next-btn">Weiter →</button>
    </div>
  </div>

  <!-- Complete Screen (hidden until done) -->
  <div id="complete-screen">
    <div class="icon">🎉</div>
    <h2>Einheit abgeschlossen!</h2>
    <div id="quest-banner" class="quest-complete-banner" style="display:none;"></div>
    <div class="complete-stats" id="complete-stats"></div>
    <button type="button" id="map-btn">Zur Karte 🗺️</button>
  </div>

  <!-- Data for JS -->
  <script>
    var SESSION_DATA = <?= json_encode([
      'sessionId'  => (int)$session['id'],
      'unitId'     => (int)$unit['id'],
      'csrfToken'  => $csrfToken,
      'items'      => $itemsForJs,
      'format'     => $unit['format'],
    ], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  </script>

<?php endif; ?>

</main>

<script src="/js/session.js"></script>
</body>
</html>
