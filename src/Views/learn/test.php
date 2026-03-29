<?php
/**
 * Einstufungstest — View
 *
 * Verfügbare Variablen (vom TestController gesetzt):
 *   $viewState  — 'start' | 'test' | 'results'
 *   $theme      — theme.json als Array
 *   $test       — tests-Zeile (oder null bei 'start')
 *   $section    — test_sections-Zeile (oder null)
 *   $item       — test_items-Zeile des aktuellen Items (oder null)
 *   $progress   — ['total', 'answered', 'percent', 'sections']
 *   $sectionResults — Ergebnis-Daten (nur bei 'results')
 *   $hasCompleted   — bool (nur bei 'start')
 */

use App\Helpers\Auth;

$csrfToken = Auth::csrfToken();

// Theme-Farben
$colorPrimary   = $theme['colors']['primary']    ?? '#5d8a38';
$colorPrimaryDk = $theme['colors']['primary_dk'] ?? '#3b5e1e';
$colorAccent    = $theme['colors']['accent']     ?? '#8B4513';
$colorSurface   = $theme['colors']['surface']    ?? '#f0e8d0';

// Biom-Mapping: Block A→0, B→1, C→2, D→3
$blocks     = ['A', 'B', 'C', 'D'];
$blockLabels = [
    'A' => 'Laut-Buchstaben-Zuordnung',
    'B' => 'Regelwissen',
    'C' => 'Ableitungswissen',
    'D' => 'Groß-/Kleinschreibung',
];
$blockHints = [
    'A' => 'Schreibe das Wort wie du es hörst.',
    'B' => 'Achte auf die Rechtschreib-Regeln.',
    'C' => 'Denke an Ableitungen (ä, äu …).',
    'D' => 'Achte auf Groß- und Kleinschreibung!',
];

$currentBlock = $section['block'] ?? 'A';
$blockIdx     = array_search($currentBlock, $blocks);
$biome        = $theme['biomes'][$blockIdx !== false ? $blockIdx : 0] ?? [
    'icon' => '🌲', 'label' => 'Der Wald',
];

// Fortschrittsbalken-Sektions-Info
$sections = $progress['sections'] ?? [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Einstufungstest — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="/css/app.css">
  <style>
    /* ── Test-spezifische Styles ── */
    :root {
      --mc-primary:    <?= htmlspecialchars($colorPrimary) ?>;
      --mc-primary-dk: <?= htmlspecialchars($colorPrimaryDk) ?>;
      --mc-accent:     <?= htmlspecialchars($colorAccent) ?>;
      --mc-surface:    <?= htmlspecialchars($colorSurface) ?>;
    }

    body.test-page {
      background: #1a1a1a;
      color: #f0e8d0;
      min-height: 100vh;
      font-family: system-ui, -apple-system, sans-serif;
    }

    /* ── Navbar ── */
    .test-navbar {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: .6rem 1.25rem;
      background: var(--mc-primary-dk);
      border-bottom: 3px solid var(--mc-accent);
    }
    .test-navbar-brand { font-weight: 700; font-size: 1rem; flex: 1; color: #fff; }
    .test-navbar-user  { font-size: .85rem; color: rgba(255,255,255,.85); }
    .btn-navbar {
      background: rgba(255,255,255,.15);
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: .35rem .75rem;
      font-size: .8rem;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
    }
    .btn-navbar:hover { background: rgba(255,255,255,.3); }

    /* ── Fortschrittsbalken oben ── */
    .test-progress-bar-wrap {
      background: #111;
      padding: .5rem 1.25rem;
      border-bottom: 2px solid #333;
    }
    .test-progress-meta {
      display: flex;
      justify-content: space-between;
      font-size: .75rem;
      color: rgba(240,232,208,.7);
      margin-bottom: .3rem;
    }
    .test-progress-track {
      height: 12px;
      background: #333;
      border-radius: 6px;
      overflow: hidden;
      display: flex;
      gap: 2px;
    }
    .test-progress-seg {
      flex: 1;
      border-radius: 4px;
      transition: background .4s;
    }
    .seg-done    { background: var(--mc-primary); }
    .seg-active  { background: repeating-linear-gradient(
      90deg, var(--mc-primary) 0px, var(--mc-primary) 8px, #2a5016 8px, #2a5016 16px
    ); animation: stripe-move .8s linear infinite; }
    .seg-pending { background: #444; }

    @keyframes stripe-move {
      from { background-position: 0 0; }
      to   { background-position: 16px 0; }
    }

    /* Sektion-Dots */
    .section-dots {
      display: flex;
      gap: .5rem;
      justify-content: center;
      margin-top: .4rem;
    }
    .sdot {
      display: flex;
      align-items: center;
      gap: .25rem;
      font-size: .72rem;
      padding: .15rem .5rem;
      border-radius: 12px;
      font-weight: 600;
    }
    .sdot-done    { background: var(--mc-primary);    color: #fff; }
    .sdot-active  { background: var(--mc-accent);     color: #fff; }
    .sdot-pending { background: #333;                 color: #888; }

    /* ── Haupt-Container ── */
    .test-main {
      max-width: 560px;
      margin: 1.5rem auto;
      padding: 0 1rem;
    }

    /* ── Biom-Header ── */
    .biome-header {
      text-align: center;
      margin-bottom: 1.25rem;
    }
    .biome-icon  { font-size: 2.5rem; display: block; }
    .biome-name  { font-size: 1.1rem; font-weight: 700; color: var(--mc-surface); margin-top: .2rem; }
    .biome-label { font-size: .8rem;  color: rgba(240,232,208,.6); margin-top: .1rem; }

    /* ── Test-Karte ── */
    .test-card {
      background: #2a2a2a;
      border: 2px solid var(--mc-accent);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 24px rgba(0,0,0,.5);
    }
    .test-card-header {
      background: var(--mc-primary-dk);
      padding: .75rem 1.25rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: .85rem;
    }
    .item-counter { color: rgba(255,255,255,.8); }
    .block-badge  {
      background: var(--mc-accent);
      color: #fff;
      padding: .15rem .55rem;
      border-radius: 6px;
      font-weight: 700;
      font-size: .78rem;
    }

    /* ── TTS-Bereich ── */
    .tts-area {
      padding: 2rem 1.5rem 1.5rem;
      text-align: center;
    }
    .tts-icon {
      font-size: 3.5rem;
      display: block;
      margin-bottom: .5rem;
      transition: transform .15s;
    }
    .tts-icon.playing { animation: pulse-icon .6s ease-in-out infinite alternate; }
    @keyframes pulse-icon {
      from { transform: scale(1);    }
      to   { transform: scale(1.15); }
    }
    .tts-status {
      font-size: .9rem;
      color: rgba(240,232,208,.7);
      min-height: 1.4em;
    }
    .tts-hint {
      font-size: .78rem;
      color: rgba(240,232,208,.5);
      margin-top: .75rem;
      font-style: italic;
    }

    /* TTS-Buttons */
    .tts-buttons {
      display: flex;
      gap: .65rem;
      justify-content: center;
      margin-top: 1rem;
    }
    .btn-tts {
      display: flex;
      align-items: center;
      gap: .35rem;
      padding: .5rem 1rem;
      border: 2px solid;
      border-radius: 8px;
      font-size: .85rem;
      font-weight: 600;
      cursor: pointer;
      transition: background .15s, transform .1s;
      background: transparent;
    }
    .btn-tts:active  { transform: scale(.96); }
    .btn-tts:disabled { opacity: .4; cursor: default; }
    .btn-tts-normal  { border-color: var(--mc-primary); color: var(--mc-primary); }
    .btn-tts-normal:hover:not(:disabled) { background: var(--mc-primary); color: #fff; }
    .btn-tts-slow    { border-color: #7cb3e0; color: #7cb3e0; }
    .btn-tts-slow:hover:not(:disabled)   { background: #7cb3e0; color: #111; }

    /* ── Antwort-Bereich ── */
    .answer-area {
      padding: 0 1.5rem 1.5rem;
    }
    .answer-label {
      font-size: .8rem;
      color: rgba(240,232,208,.6);
      margin-bottom: .4rem;
      display: block;
    }
    .answer-input-wrap {
      display: flex;
      gap: .5rem;
    }
    .answer-input {
      flex: 1;
      padding: .75rem 1rem;
      background: #1a1a1a;
      border: 2px solid #555;
      border-radius: 8px;
      color: #f0e8d0;
      font-size: 1.2rem;
      font-family: inherit;
      transition: border-color .15s;
      outline: none;
    }
    .answer-input:focus   { border-color: var(--mc-primary); }
    .answer-input:disabled { opacity: .5; }
    .btn-submit {
      padding: .75rem 1.25rem;
      background: var(--mc-primary);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: background .15s, transform .1s;
      white-space: nowrap;
    }
    .btn-submit:hover:not(:disabled) { background: var(--mc-primary-dk); }
    .btn-submit:active { transform: scale(.97); }
    .btn-submit:disabled { opacity: .5; cursor: default; }

    .hint-text {
      font-size: .75rem;
      color: rgba(240,232,208,.45);
      margin-top: .4rem;
    }

    /* ── Feedback-Overlay ── */
    .feedback-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.75);
      z-index: 100;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }
    .feedback-overlay.visible { display: flex; }
    .feedback-box {
      background: #2a2a2a;
      border: 3px solid #555;
      border-radius: 14px;
      padding: 2rem 2.5rem;
      text-align: center;
      max-width: 380px;
      width: 100%;
      animation: pop-in .2s ease-out;
    }
    @keyframes pop-in {
      from { transform: scale(.85); opacity: 0; }
      to   { transform: scale(1);   opacity: 1; }
    }
    .feedback-box.correct { border-color: var(--mc-primary); }
    .feedback-box.wrong   { border-color: #c0392b; }
    .feedback-emoji  { font-size: 3rem; display: block; margin-bottom: .5rem; }
    .feedback-main   { font-size: 1.15rem; font-weight: 700; margin-bottom: .5rem; }
    .feedback-answer { font-size: .95rem; color: rgba(240,232,208,.75); }
    .feedback-answer strong { color: var(--mc-surface); }
    .feedback-next   {
      margin-top: 1rem;
      font-size: .8rem;
      color: rgba(240,232,208,.45);
    }

    /* ── Sektion-Übergang ── */
    .section-transition {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.85);
      z-index: 110;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      flex-direction: column;
    }
    .section-transition.visible { display: flex; }
    .transition-box {
      background: #2a2a2a;
      border: 3px solid var(--mc-accent);
      border-radius: 16px;
      padding: 2rem;
      max-width: 420px;
      width: 100%;
      text-align: center;
      animation: pop-in .25s ease-out;
    }
    .transition-biome-icon { font-size: 3.5rem; display: block; margin-bottom: .5rem; }
    .transition-title      { font-size: 1.4rem; font-weight: 700; margin-bottom: .5rem; }
    .transition-stats      {
      display: flex;
      gap: 1rem;
      justify-content: center;
      margin: 1rem 0;
    }
    .stat-box {
      background: #1a1a1a;
      border-radius: 8px;
      padding: .6rem 1rem;
      min-width: 80px;
    }
    .stat-val   { font-size: 1.6rem; font-weight: 700; color: var(--mc-primary); }
    .stat-label { font-size: .7rem; color: rgba(240,232,208,.6); margin-top: .1rem; }
    .fatigue-warn {
      background: #3a2a00;
      border: 1px solid #8B6914;
      border-radius: 8px;
      padding: .65rem 1rem;
      font-size: .85rem;
      color: #ffd54f;
      margin: .75rem 0;
    }
    .next-biome-preview {
      background: #1a1a1a;
      border-radius: 8px;
      padding: .65rem 1rem;
      font-size: .85rem;
      margin: .75rem 0;
      color: rgba(240,232,208,.8);
    }
    .btn-transition {
      display: block;
      width: 100%;
      padding: .85rem;
      background: var(--mc-primary);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      margin-top: .75rem;
      transition: background .15s;
    }
    .btn-transition:hover { background: var(--mc-primary-dk); }
    .btn-transition.pause {
      background: #444;
      color: rgba(240,232,208,.8);
      margin-top: .5rem;
      font-size: .875rem;
    }
    .btn-transition.pause:hover { background: #555; }

    /* ── Start-Screen ── */
    .start-screen {
      text-align: center;
      padding: 2rem 1rem;
    }
    .start-icon   { font-size: 4rem; display: block; margin-bottom: 1rem; }
    .start-title  { font-size: 1.6rem; font-weight: 700; margin-bottom: .5rem; }
    .start-sub    { color: rgba(240,232,208,.65); font-size: .95rem; margin-bottom: 1.5rem; }
    .start-blocks {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .65rem;
      margin-bottom: 1.5rem;
    }
    .start-block-card {
      background: #2a2a2a;
      border: 1px solid #444;
      border-radius: 10px;
      padding: .75rem;
      text-align: center;
      font-size: .82rem;
    }
    .sbc-icon  { font-size: 1.4rem; display: block; margin-bottom: .2rem; }
    .sbc-name  { font-weight: 700; font-size: .9rem; }
    .sbc-label { color: rgba(240,232,208,.55); margin-top: .1rem; }
    .btn-start {
      display: block;
      width: 100%;
      padding: 1rem;
      background: var(--mc-primary);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 1.1rem;
      font-weight: 700;
      cursor: pointer;
      transition: background .15s;
    }
    .btn-start:hover { background: var(--mc-primary-dk); }
    .start-note {
      font-size: .78rem;
      color: rgba(240,232,208,.45);
      margin-top: .75rem;
    }

    /* ── Ergebnis-Screen ── */
    .results-screen { text-align: center; padding: 2rem 1rem; }
    .results-title  { font-size: 1.5rem; font-weight: 700; margin-bottom: 1.25rem; }
    .results-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: 1.5rem; }
    .result-card {
      background: #2a2a2a;
      border: 1px solid #444;
      border-radius: 10px;
      padding: 1rem;
      text-align: center;
    }
    .rc-icon  { font-size: 1.8rem; display: block; }
    .rc-block { font-weight: 700; font-size: .9rem; margin: .2rem 0; }
    .rc-score { font-size: 1.4rem; font-weight: 700; }
    .rc-score.good  { color: var(--mc-primary); }
    .rc-score.ok    { color: #ffd54f; }
    .rc-score.bad   { color: #e57373; }
    .rc-detail      { font-size: .72rem; color: rgba(240,232,208,.5); margin-top: .2rem; }
    .results-note {
      background: #2a2a2a;
      border: 1px solid #444;
      border-radius: 10px;
      padding: 1rem;
      font-size: .875rem;
      color: rgba(240,232,208,.75);
      margin-bottom: 1.25rem;
    }
    .btn-home {
      display: inline-block;
      padding: .85rem 2rem;
      background: var(--mc-accent);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      transition: background .15s;
    }
    .btn-home:hover { background: #a0522d; }

    /* ── Analyse-Box ── */
    .analysis-box {
      background: #2a2a2a; border: 2px solid #555;
      border-radius: 12px; padding: 1.25rem;
      margin-bottom: 1.25rem;
    }
    .analysis-box.pending  { border-color: #7cb3e0; }
    .analysis-box.running  { border-color: #ffd54f; }
    .analysis-box.done     { border-color: var(--mc-primary); }
    .analysis-box.error    { border-color: #e57373; }
    .analysis-icon  { font-size: 2rem; display: block; margin-bottom: .4rem; }
    .analysis-title { font-size: 1rem; font-weight: 700; margin-bottom: .3rem; }
    .analysis-sub   { font-size: .82rem; color: rgba(240,232,208,.6); margin-bottom: .85rem; }
    .analysis-spinner {
      display: inline-block; width: 24px; height: 24px;
      border: 3px solid rgba(240,232,208,.2);
      border-top-color: #ffd54f;
      border-radius: 50%;
      animation: spin .8s linear infinite;
      vertical-align: middle; margin-right: .4rem;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .btn-analyze {
      display: inline-block; padding: .65rem 1.5rem;
      background: #7cb3e0; color: #111;
      border: none; border-radius: 8px;
      font-size: .9rem; font-weight: 700; cursor: pointer;
      transition: background .15s;
    }
    .btn-analyze:hover    { background: #90c4e8; }
    .btn-analyze:disabled { opacity: .5; cursor: default; }

    /* ── Responsive ── */
    @media (max-width: 480px) {
      .start-blocks, .results-grid { grid-template-columns: 1fr; }
      .test-card { border-radius: 10px; }
      .feedback-box { padding: 1.5rem; }
    }
  </style>
</head>
<body class="test-page">

<!-- Navbar -->
<nav class="test-navbar">
  <span class="test-navbar-brand">⛏️ <?= htmlspecialchars(APP_NAME) ?></span>
  <span class="test-navbar-user">🎮 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
  <?php if ($viewState === 'test'): ?>
    <button class="btn-navbar" id="btn-pause-nav" type="button">⏸ Pause</button>
  <?php else: ?>
    <a href="<?= url('/logout') ?>" class="btn-navbar">Abmelden</a>
  <?php endif; ?>
</nav>

<?php /* ========================================================
       START-SCREEN
   ======================================================== */ ?>
<?php if ($viewState === 'start'): ?>

<div class="test-main">
  <div class="start-screen">
    <span class="start-icon">⛏️</span>
    <div class="start-title"><?= htmlspecialchars($theme['flavor_texts']['welcome'] ?? 'Bereit für dein Abenteuer?') ?></div>
    <div class="start-sub">
      <?= $hasCompleted
        ? 'Du hast den Einstufungstest bereits abgeschlossen. Du kannst ihn erneut machen.'
        : 'Wir testen heute, welche Wörter dir leicht fallen und wo wir üben müssen.' ?>
    </div>

    <!-- Block-Vorschau -->
    <div class="start-blocks">
      <?php
      $startBiomeIdx = 0;
      foreach (['A', 'B', 'C', 'D'] as $b):
        $bBiome = $theme['biomes'][$startBiomeIdx++] ?? ['icon' => '🔤', 'label' => 'Block ' . $b];
      ?>
      <div class="start-block-card">
        <span class="sbc-icon"><?= $bBiome['icon'] ?></span>
        <div class="sbc-name"><?= htmlspecialchars($bBiome['label']) ?></div>
        <div class="sbc-label">Block <?= $b ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" action="<?= url('/learn/test') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <button class="btn-start" type="submit">
        🗡️ Test starten
      </button>
    </form>
    <div class="start-note">Ca. <?= count($blocks) ?> × 9–15 Wörter · Du kannst jederzeit pausieren</div>
  </div>
</div>

<?php /* ========================================================
       AKTIVER TEST
   ======================================================== */ ?>
<?php elseif ($viewState === 'test' && $item): ?>

<!-- Fortschrittsbalken -->
<div class="test-progress-bar-wrap">
  <div class="test-progress-meta">
    <span>Einstufungstest</span>
    <span id="progress-label"><?= $progress['answered'] ?> / <?= $progress['total'] ?> Wörter</span>
  </div>
  <div class="test-progress-track">
    <?php foreach ($sections as $sec):
      $pct = $sec['total_items'] > 0
           ? (int)round($sec['answered_items'] / $sec['total_items'] * 100)
           : 0;
      if ($sec['status'] === 'completed') $cls = 'seg-done';
      elseif ($sec['status'] === 'in_progress') $cls = 'seg-active';
      else $cls = 'seg-pending';
    ?>
      <div class="test-progress-seg <?= $cls ?>" style="<?= $cls === 'seg-active' ? "background-size: 16px 16px;" : "" ?>"></div>
    <?php endforeach; ?>
  </div>
  <div class="section-dots">
    <?php
    $sdIdx = 0;
    foreach ($sections as $sec):
      $sdBiome = $theme['biomes'][$sdIdx++] ?? ['icon' => '🔤', 'label' => $sec['block']];
      if ($sec['status'] === 'completed') $sdCls = 'sdot-done';
      elseif ($sec['status'] === 'in_progress') $sdCls = 'sdot-active';
      else $sdCls = 'sdot-pending';
    ?>
      <span class="sdot <?= $sdCls ?>">
        <?= $sdBiome['icon'] ?> <?= htmlspecialchars($sdBiome['label']) ?>
      </span>
    <?php endforeach; ?>
  </div>
</div>

<!-- Haupt-Content -->
<div class="test-main">
  <div class="biome-header">
    <span class="biome-icon"><?= $biome['icon'] ?></span>
    <div class="biome-name"><?= htmlspecialchars($biome['label']) ?></div>
    <div class="biome-label">Block <?= htmlspecialchars($currentBlock) ?> — <?= htmlspecialchars($blockLabels[$currentBlock] ?? '') ?></div>
  </div>

  <!-- Test-Karte -->
  <div class="test-card" id="test-card">
    <div class="test-card-header">
      <span class="item-counter" id="item-counter">Lade…</span>
      <span class="block-badge">Block <?= htmlspecialchars($currentBlock) ?></span>
    </div>

    <!-- TTS-Bereich -->
    <div class="tts-area">
      <span class="tts-icon" id="tts-icon">🔊</span>
      <div class="tts-status" id="tts-status">Wort wird geladen…</div>
      <div class="tts-hint" id="tts-hint"><?= htmlspecialchars($blockHints[$currentBlock] ?? '') ?></div>
      <div class="tts-buttons">
        <button class="btn-tts btn-tts-normal" id="btn-replay" type="button" disabled>
          ▶ Wiederholen
        </button>
        <button class="btn-tts btn-tts-slow" id="btn-slow" type="button" disabled>
          🐢 Langsam
        </button>
      </div>
    </div>

    <!-- Antwort -->
    <div class="answer-area">
      <label class="answer-label" for="answer-input">Deine Antwort:</label>
      <div class="answer-input-wrap">
        <input
          type="text"
          id="answer-input"
          class="answer-input"
          autocomplete="off"
          autocorrect="off"
          autocapitalize="<?= $currentBlock === 'D' ? 'words' : 'off' ?>"
          spellcheck="false"
          placeholder="Wort eintippen…"
          disabled
        >
        <button class="btn-submit" id="btn-submit" type="button" disabled>✓</button>
      </div>
      <div class="hint-text">Enter drücken oder ✓ klicken zum Bestätigen</div>
    </div>
  </div>
</div>

<!-- Feedback-Overlay -->
<div class="feedback-overlay" id="feedback-overlay">
  <div class="feedback-box" id="feedback-box">
    <span class="feedback-emoji" id="feedback-emoji"></span>
    <div class="feedback-main"  id="feedback-main"></div>
    <div class="feedback-answer" id="feedback-answer"></div>
    <div class="feedback-next">Weiter in <span id="feedback-countdown">2</span> s …</div>
  </div>
</div>

<!-- Sektions-Übergang -->
<div class="section-transition" id="section-transition">
  <div class="transition-box" id="transition-box">
    <span class="transition-biome-icon" id="tr-biome-icon"></span>
    <div class="transition-title"   id="tr-title"></div>
    <div class="transition-stats"   id="tr-stats"></div>
    <div class="fatigue-warn"       id="tr-fatigue" style="display:none"></div>
    <div class="next-biome-preview" id="tr-next"    style="display:none"></div>
    <button class="btn-transition"  id="btn-next-section" type="button">Weiter ➜</button>
    <button class="btn-transition pause" id="btn-pause-section" type="button">⏸ Pause machen</button>
  </div>
</div>

<!-- JavaScript-Daten (keine geheimen Infos — Wörter bleiben server-seitig) -->
<script>
const TEST_DATA = {
  csrfToken:  <?= json_encode($csrfToken) ?>,
  itemId:     <?= (int)$item['id'] ?>,
  sectionId:  <?= (int)$section['id'] ?>,
  block:      <?= json_encode($currentBlock) ?>,
  totalItems: <?= (int)($progress['total'] ?? 0) ?>,
  answered:   <?= (int)($progress['answered'] ?? 0) ?>,
  biomes:     <?= json_encode($theme['biomes'] ?? []) ?>,
  blocks:     ['A','B','C','D'],
  blockHints: <?= json_encode($blockHints) ?>,
  flavorCorrect: <?= json_encode($theme['flavor_texts']['correct'] ?? 'Richtig! +XP') ?>,
  flavorWrong:   <?= json_encode($theme['flavor_texts']['wrong']   ?? 'Noch einmal versuchen!') ?>,
};
</script>
<script src="/js/test.js"></script>

<?php /* ========================================================
       ERGEBNIS-SCREEN
   ======================================================== */ ?>
<?php elseif ($viewState === 'results'): ?>

<div class="test-main">
  <div class="results-screen">
    <div class="results-title">🌟 Test abgeschlossen!</div>

    <?php if (!empty($sectionResults)): ?>
    <div class="results-grid">
      <?php
      $rIdx = 0;
      foreach ($sectionResults as $sr):
        $rBiome  = $theme['biomes'][$rIdx++] ?? ['icon' => '🔤', 'label' => 'Block'];
        $total   = (int)$sr['total'];
        $correct = (int)$sr['correct'];
        $pct     = $total > 0 ? (int)round($correct / $total * 100) : 0;
        $cls     = $pct >= 75 ? 'good' : ($pct >= 50 ? 'ok' : 'bad');
      ?>
      <div class="result-card">
        <span class="rc-icon"><?= $rBiome['icon'] ?></span>
        <div class="rc-block">Block <?= htmlspecialchars($sr['block']) ?></div>
        <div class="rc-score <?= $cls ?>"><?= $pct ?> %</div>
        <div class="rc-detail"><?= $correct ?> / <?= $total ?> richtig</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── KI-Auswertungs-Box ── -->
    <?php if (($analysisStatus ?? 'pending') === 'done'): ?>
      <div class="analysis-box done">
        <span class="analysis-icon">✅</span>
        <div class="analysis-title">Auswertung abgeschlossen!</div>
        <div class="analysis-sub">Papa kann deinen Lernplan jetzt im Dashboard bestätigen.</div>
      </div>
    <?php else: ?>
      <div class="analysis-box pending" id="analysis-box">
        <span class="analysis-icon" id="analysis-icon">🔍</span>
        <div class="analysis-title" id="analysis-title">KI-Auswertung starten</div>
        <div class="analysis-sub"   id="analysis-sub">
          Die KI analysiert deine Antworten und erstellt einen Lernplan für Papa.
          Das dauert ca. 20–30 Sekunden.
        </div>
        <button class="btn-analyze" id="btn-analyze" type="button">
          🤖 Jetzt auswerten lassen
        </button>
      </div>
    <?php endif; ?>

    <a href="<?= url('/learn') ?>" class="btn-home" id="btn-home" style="display:none">🏠 Zur Startseite</a>
  </div>
</div>

<script>
(function() {
  var testId   = <?= (int)($test['id'] ?? 0) ?>;
  var csrf     = <?= json_encode($csrfToken) ?>;
  var btn      = document.getElementById('btn-analyze');
  var box      = document.getElementById('analysis-box');
  var icon     = document.getElementById('analysis-icon');
  var title    = document.getElementById('analysis-title');
  var sub      = document.getElementById('analysis-sub');
  var homeBtn  = document.getElementById('btn-home');

  if (!btn) return; // already done

  btn.addEventListener('click', function() {
    btn.disabled = true;
    box.className = 'analysis-box running';
    icon.innerHTML = '<span class="analysis-spinner"></span>';
    title.textContent = 'Auswertung läuft…';
    sub.textContent   = 'Die KI analysiert deine Antworten. Bitte warten…';

    fetch('/learn/test/analyze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf_token: csrf, test_id: testId }),
    })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success || data.already_done) {
          box.className     = 'analysis-box done';
          icon.textContent  = '✅';
          title.textContent = 'Auswertung abgeschlossen!';
          sub.textContent   = data.message || 'Papa kann deinen Lernplan jetzt im Dashboard bestätigen.';
          btn.remove();
          if (homeBtn) homeBtn.style.display = 'inline-block';
        } else {
          box.className     = 'analysis-box error';
          icon.textContent  = '❌';
          title.textContent = 'Auswertung fehlgeschlagen';
          sub.textContent   = data.message || 'Bitte Papa bitten, die Auswertung manuell zu starten.';
          btn.disabled      = false;
          btn.textContent   = '🔄 Erneut versuchen';
          if (homeBtn) homeBtn.style.display = 'inline-block';
        }
      })
      .catch(function() {
        box.className     = 'analysis-box error';
        icon.textContent  = '❌';
        title.textContent = 'Netzwerkfehler';
        sub.textContent   = 'Bitte Seite neu laden oder Papa bitten, die Auswertung manuell zu starten.';
        btn.disabled      = false;
        btn.textContent   = '🔄 Erneut versuchen';
        if (homeBtn) homeBtn.style.display = 'inline-block';
      });
  });
})();
</script>

<?php endif; /* viewState */ ?>

</body>
</html>
