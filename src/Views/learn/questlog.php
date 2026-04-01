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

// Theme-Farben + Labels
$tc = $theme['colors']  ?? [];
$tl = $theme['labels']  ?? [];
$tf = $theme['flavor_texts'] ?? [];
$themeColorPrimary   = $tc['primary']    ?? '#2d5016';
$themeColorPrimaryDk = $tc['primary_dk'] ?? '#1a3a08';
$themeColorAccent    = $tc['accent']     ?? '#4a9220';
$themeLabelQuest    = $tl['quest']    ?? 'Quest';
$themeLabelBiome    = $tl['biome']    ?? 'Biom';
$themeLabelPoints   = $tl['points']   ?? 'Punkte';
$themeLabelAch      = $tl['achievement'] ?? 'Auszeichnung';
$themeIcon          = $theme['icon']  ?? '⛏️';
$themeName_display  = $theme['name']  ?? 'Minecraft';

// Biom-Farben-Map aus theme.json (id → [color_from, color_to])
$biomeColorMap = [];
foreach ($theme['biomes'] ?? [] as $tb) {
    $biomeColorMap[$tb['id']] = [
        'from' => $tb['color_from'] ?? '#555',
        'to'   => $tb['color_to']   ?? '#777',
    ];
}

// Icon-Map: ersetzt Minecraft-Icons durch theme-spezifische Icons
$iconMap = $theme['icon_map'] ?? [];
$mapIcon = fn(string $icon) => $iconMap[$icon] ?? $icon;
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
      background: linear-gradient(135deg, <?= $themeColorPrimaryDk ?> 0%, <?= $themeColorPrimary ?> 60%, <?= $themeColorAccent ?> 100%);
      color: #fff;
      padding: 1.25rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      border-bottom: 3px solid <?= $themeColorPrimaryDk ?>;
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

    /* ── Achievements ── */
    .achievements-section {
      margin-bottom: 1.25rem;
    }
    .achievements-label {
      font-size: .78rem;
      font-weight: 700;
      color: #888;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-bottom: .5rem;
      text-align: center;
    }
    .achievements-row {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
      justify-content: center;
    }
    .ach-badge {
      background: #fff;
      border: 2px solid #e0e0e0;
      border-radius: 10px;
      padding: .35rem .65rem;
      display: flex;
      align-items: center;
      gap: .35rem;
      font-size: .82rem;
      font-weight: 600;
      box-shadow: 0 1px 4px rgba(0,0,0,.08);
      cursor: default;
      position: relative;
    }
    .ach-badge.new {
      border-color: #ffd700;
      background: #fffde7;
      animation: ach-glow 1.5s ease-in-out 3;
    }
    @keyframes ach-glow {
      0%,100% { box-shadow: 0 0 0 0 rgba(255,215,0,0); }
      50%      { box-shadow: 0 0 10px 3px rgba(255,215,0,.6); }
    }
    .ach-badge .ach-icon { font-size: 1.1rem; }
    .ach-tooltip {
      display: none;
      position: absolute;
      bottom: calc(100% + 6px);
      left: 50%;
      transform: translateX(-50%);
      background: #212121;
      color: #fff;
      font-size: .75rem;
      font-weight: 400;
      border-radius: 6px;
      padding: .35rem .6rem;
      white-space: nowrap;
      z-index: 10;
      pointer-events: none;
    }
    .ach-badge:hover .ach-tooltip { display: block; }

    /* ── Next Achievement ── */
    .next-ach-card {
      background: linear-gradient(135deg, #263238 0%, #37474f 100%);
      color: #fff;
      border-radius: 12px;
      padding: .85rem 1.1rem;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: .9rem;
    }
    .next-ach-icon { font-size: 2rem; flex-shrink: 0; filter: grayscale(60%) brightness(.7); }
    .next-ach-info { flex: 1; min-width: 0; }
    .next-ach-title { font-size: .72rem; opacity: .7; text-transform: uppercase; letter-spacing: .05em; }
    .next-ach-name  { font-size: 1rem; font-weight: 700; margin: .1rem 0 .3rem; }
    .progress-bar-wrap {
      background: rgba(255,255,255,.15);
      border-radius: 20px;
      height: 8px;
      overflow: hidden;
      margin: .3rem 0 .25rem;
    }
    .progress-bar-fill {
      height: 100%;
      border-radius: 20px;
      background: linear-gradient(90deg, #ffd700, #ffb300);
      transition: width .6s ease;
    }
    .next-ach-sub { font-size: .75rem; opacity: .75; }

    /* ── Adventure Banner ── */
    .adventure-banner {
      background: linear-gradient(135deg, #1a237e 0%, #283593 60%, #3949ab 100%);
      color: #fff;
      border-radius: 12px;
      padding: 1rem 1.25rem;
      margin-bottom: 1.25rem;
      box-shadow: 0 3px 12px rgba(0,0,0,.2);
    }
    .adventure-banner h3 {
      margin: 0 0 .6rem 0;
      font-size: 1rem;
      opacity: .85;
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .adventure-item {
      background: rgba(255,255,255,.12);
      border-radius: 8px;
      padding: .65rem 1rem;
      margin-bottom: .5rem;
      display: flex;
      align-items: center;
      gap: .75rem;
    }
    .adventure-item:last-child { margin-bottom: 0; }
    .adventure-item .adv-title { font-weight: 700; font-size: .95rem; flex: 1; }
    .adventure-item .adv-meta  { font-size: .78rem; opacity: .8; }
    .btn-adventure {
      background: #fff;
      color: #1a237e;
      border: none;
      padding: .45rem 1.1rem;
      border-radius: 20px;
      font-size: .85rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      flex-shrink: 0;
      animation: adv-pulse 1.8s ease-in-out infinite;
    }
    @keyframes adv-pulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(255,255,255,.5); }
      50%       { box-shadow: 0 0 0 8px rgba(255,255,255,0); }
    }

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

    /* ── Warm-Up Banner ── */
    .warmup-banner {
      background: linear-gradient(135deg, <?= $themeColorPrimary ?> 0%, <?= $themeColorAccent ?> 100%);
      color: #fff;
      border-radius: 14px;
      padding: 1rem 1.25rem;
      margin-bottom: 1.25rem;
      display: flex;
      align-items: center;
      gap: 0.9rem;
    }
    .warmup-banner .wu-icon { font-size: 2rem; flex-shrink: 0; }
    .warmup-banner .wu-text h3 { font-size: 1.05rem; font-weight: 700; margin: 0 0 0.15rem; }
    .warmup-banner .wu-text p  { font-size: 0.85rem; margin: 0; opacity: 0.9; }

    /* ── Parent Message Card ── */
    .parent-msg-card {
      background: #fff8e1;
      border: 2px solid #ffd54f;
      border-radius: 14px;
      padding: 1rem 1.25rem;
      margin-bottom: 1.25rem;
      position: relative;
    }
    .parent-msg-header {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      font-weight: 700;
      font-size: 0.95rem;
      color: #795548;
      margin-bottom: 0.6rem;
    }
    .parent-msg-text {
      font-size: 1rem;
      color: #3e2723;
      line-height: 1.5;
      white-space: pre-wrap;
    }
    .parent-msg-close {
      position: absolute;
      top: 0.7rem;
      right: 0.9rem;
      background: none;
      border: none;
      font-size: 1.2rem;
      cursor: pointer;
      color: #bbb;
      line-height: 1;
    }
    .parent-msg-close:hover { color: #888; }
    .parent-msg-counter {
      font-size: 0.75rem;
      color: #a0897a;
      margin-top: 0.5rem;
    }

    /* ── Family Goal Card ── */
    .family-goal-card {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 3px 12px rgba(0,0,0,.1);
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
    }
    .family-goal-header {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      font-weight: 700;
      font-size: 0.95rem;
      color: #333;
      margin-bottom: 0.6rem;
    }
    .family-goal-title { font-size: 1rem; color: #333; margin-bottom: 0.75rem; }
    .family-goal-bar-wrap {
      height: 14px;
      background: #e0e0e0;
      border-radius: 7px;
      overflow: hidden;
      margin-bottom: 0.4rem;
    }
    .family-goal-bar-fill {
      height: 100%;
      border-radius: 7px;
      background: linear-gradient(90deg, <?= $themeColorPrimary ?>, <?= $themeColorAccent ?>);
      transition: width .5s ease;
    }
    .family-goal-sub {
      font-size: 0.8rem;
      color: #888;
      display: flex;
      justify-content: space-between;
    }
    .family-goal-reward {
      margin-top: 0.75rem;
      background: #f1f8e9;
      border-left: 3px solid #7cb342;
      padding: 0.5rem 0.75rem;
      border-radius: 0 8px 8px 0;
      font-size: 0.88rem;
      color: #33691e;
    }
    .family-goal-completed {
      background: linear-gradient(135deg, #1b5e20, #2e7d32);
      color: #fff;
      text-align: center;
      padding: 1rem;
      border-radius: 14px;
      margin-bottom: 1.5rem;
    }
    .family-goal-completed h3 { font-size: 1.1rem; margin: 0 0 0.4rem; }
    .family-goal-completed p  { font-size: 0.9rem; margin: 0; opacity: 0.92; }

    /* ── Warm-Up Modal ── */
    .warmup-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.55);
      z-index: 1000;
      align-items: center;
      justify-content: center;
    }
    .warmup-overlay.active { display: flex; }
    .warmup-modal {
      background: #fff;
      border-radius: 20px;
      padding: 2rem 1.75rem;
      max-width: 400px;
      width: 90%;
      text-align: center;
      box-shadow: 0 10px 40px rgba(0,0,0,.25);
      animation: wu-pop .25s ease;
    }
    @keyframes wu-pop {
      from { transform: scale(.85); opacity: 0; }
      to   { transform: scale(1);   opacity: 1; }
    }
    .warmup-modal .wm-icon  { font-size: 3.5rem; margin-bottom: 0.75rem; }
    .warmup-modal h2        { font-size: 1.3rem; margin: 0 0 0.5rem; color: #222; }
    .warmup-modal .wm-sub   { color: #777; font-size: 0.9rem; margin-bottom: 1.25rem; }
    .warmup-modal .wm-msg   {
      background: #fff8e1; border-left: 3px solid #ffd54f;
      border-radius: 0 8px 8px 0; padding: .6rem .9rem;
      text-align: left; margin-bottom: 1rem; font-size: .9rem; color: #5d4037;
    }
    .warmup-modal .wm-goal  {
      background: #f1f8e9; border-radius: 8px;
      padding: .6rem .9rem; margin-bottom: 1rem;
      font-size: .88rem; color: #33691e; text-align: left;
    }
    .btn-warmup-start {
      display: block;
      width: 100%;
      padding: 0.85rem;
      background: <?= $themeColorAccent ?>;
      color: #fff;
      border: none;
      border-radius: 12px;
      font-size: 1.05rem;
      font-weight: 700;
      cursor: pointer;
      transition: background .15s;
    }
    .btn-warmup-start:hover { background: <?= $themeColorPrimaryDk ?>; }
    .warmup-countdown { font-size: 0.78rem; color: #aaa; margin-top: 0.5rem; }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($themeName) ?>">

<header class="questlog-header">
  <span class="logo"><?= $themeIcon ?></span>
  <div>
    <h1>Abenteuermap</h1>
    <div class="subtitle"><?= htmlspecialchars($childName) ?>s Lernreise · <?= htmlspecialchars($themeName_display) ?></div>
  </div>
  <div class="header-right">
    <a href="<?= url('/logout') ?>">Abmelden</a>
  </div>
</header>

<main class="map-container">

  <!-- Warm-Up Begrüßung -->
  <?php
    $hour = (int)date('G');
    if ($hour < 11)       { $greeting = 'Guten Morgen'; $wu_icon = '🌅'; }
    elseif ($hour < 17)   { $greeting = 'Hallo';        $wu_icon = '☀️'; }
    else                  { $greeting = 'Guten Abend';  $wu_icon = '🌙'; }

    $motivations = [
        'Du schaffst das — einen Schritt nach dem anderen! 💪',
        'Jede Übungseinheit macht dich besser. Weiter so! 🚀',
        'Heute bist du wieder ein Stück schlauer als gestern! ⭐',
        'Rechtschreiben lernt man durch Üben — und du übst! 🏆',
        'Jedes richtig geschriebene Wort ist ein Sieg! ✅',
    ];
    $motivText = $motivations[($totalSessions + (int)date('j')) % count($motivations)];
  ?>
  <div class="warmup-banner">
    <span class="wu-icon"><?= $wu_icon ?></span>
    <div class="wu-text">
      <h3><?= $greeting ?>, <?= htmlspecialchars($childName) ?>!</h3>
      <p><?= htmlspecialchars($motivText) ?></p>
    </div>
  </div>

  <!-- Eltern-Nachrichten -->
  <?php if (!empty($parentMessages)): ?>
    <div class="parent-msg-card" id="parent-msg-card">
      <div class="parent-msg-header">
        <span><?= htmlspecialchars($parentMessages[0]['emoji'] ?? '💌') ?></span>
        Nachricht von Papa
      </div>
      <div class="parent-msg-text"><?= htmlspecialchars($parentMessages[0]['message']) ?></div>
      <?php if (count($parentMessages) > 1): ?>
        <div class="parent-msg-counter">+ <?= count($parentMessages) - 1 ?> weitere Nachricht(en)</div>
      <?php endif; ?>
      <button class="parent-msg-close" onclick="dismissParentMsg()" title="Gelesen">✕</button>
    </div>
  <?php endif; ?>

  <?php if (!empty($pendingAdventures) || !empty($pendingAdventureGroups)): ?>
    <div class="adventure-banner">
      <h3>🗺️ Zusätzliche Abenteuer</h3>

      <?php foreach ($pendingAdventureGroups ?? [] as $grp): ?>
        <div class="adventure-item">
          <div>
            <div class="adv-title">📦 <?= htmlspecialchars($grp['title']) ?></div>
            <div class="adv-meta">
              <?= (int)$grp['adventure_count'] ?> Abenteuer im Paket
              <?php if ($grp['scheduled_date']): ?>
                · Geplant: <?= date('d.m.Y', strtotime($grp['scheduled_date'])) ?>
              <?php endif; ?>
              <?php if ($grp['repeatable']): ?> · 🔁<?php endif; ?>
            </div>
          </div>
          <?php if ($grp['active_session_id']): ?>
            <a href="<?= url('/learn/adventure?session_id=' . (int)$grp['active_session_id']) ?>"
               class="btn-adventure">Weiter →</a>
          <?php else: ?>
            <form method="post" action="<?= url('/learn/adventure-group/start') ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="group_id"   value="<?= (int)$grp['id'] ?>">
              <button type="submit" class="btn-adventure">Starten! 🚀</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php foreach ($pendingAdventures as $adv): ?>
        <div class="adventure-item">
          <div>
            <div class="adv-title"><?= htmlspecialchars($adv['title']) ?></div>
            <div class="adv-meta">
              <?= (int)$adv['word_count'] ?> Wörter
              <?php if ($adv['sentence_count'] > 0): ?>
                · <?= (int)$adv['sentence_count'] ?> Sätze
              <?php endif; ?>
              <?php if ($adv['scheduled_date']): ?>
                · Geplant: <?= date('d.m.Y', strtotime($adv['scheduled_date'])) ?>
              <?php endif; ?>
              <?php if (!empty($adv['repeatable'])): ?> · 🔁<?php endif; ?>
            </div>
          </div>
          <?php if ($adv['active_session_id']): ?>
            <a href="<?= url('/learn/adventure?session_id=' . (int)$adv['active_session_id']) ?>"
               class="btn-adventure">Weiter →</a>
          <?php elseif ($adv['diktat_generated']): ?>
            <form method="post" action="<?= url('/learn/adventure/start') ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="adventure_id" value="<?= (int)$adv['id'] ?>">
              <button type="submit" class="btn-adventure">Starten! ⚔️</button>
            </form>
          <?php else: ?>
            <span style="font-size:.78rem;opacity:.7">KI noch nicht generiert</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

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
      <?php if ($totalCorrectWords > 0): ?>
      <div class="stat-chip">
        <span class="icon">⭐</span>
        <?= $totalCorrectWords ?> Wörter
      </div>
      <?php endif; ?>
    </div>

    <!-- Familienziel -->
    <?php if (!empty($familyGoal)): ?>
      <?php if ($familyGoal['status'] === 'completed'): ?>
        <div class="family-goal-completed">
          <h3>🎉 Ziel erreicht!</h3>
          <p><?= htmlspecialchars($familyGoal['title']) ?></p>
          <?php if ($familyGoal['reward_text']): ?>
            <p style="margin-top:.5rem;font-weight:700">
              🎁 <?= htmlspecialchars($familyGoal['reward_text']) ?>
            </p>
          <?php endif; ?>
        </div>
      <?php else:
          $goalProgress  = (int)($familyGoal['progress'] ?? 0);
          $goalValue     = (int)$familyGoal['goal_value'];
          $goalPct       = min(100, $goalValue > 0 ? round($goalProgress / $goalValue * 100) : 0);
          $goalTypeLabel = match($familyGoal['goal_type']) {
            'sessions' => 'Einheiten',
            'quests'   => 'Quests',
            'streak'   => 'Tage Streak',
            default    => 'Einheiten',
          };
          $periodLabel = match($familyGoal['period']) {
            'week'    => 'diese Woche',
            'month'   => 'diesen Monat',
            default   => 'gesamt',
          };
      ?>
      <div class="family-goal-card">
        <div class="family-goal-header">🎯 Familienziel <?= htmlspecialchars($periodLabel) ?></div>
        <div class="family-goal-title"><?= htmlspecialchars($familyGoal['title']) ?></div>
        <div class="family-goal-bar-wrap">
          <div class="family-goal-bar-fill" style="width:<?= $goalPct ?>%"></div>
        </div>
        <div class="family-goal-sub">
          <span><?= $goalProgress ?> / <?= $goalValue ?> <?= htmlspecialchars($goalTypeLabel) ?></span>
          <span><?= $goalPct ?>%</span>
        </div>
        <?php if ($familyGoal['reward_text']): ?>
          <div class="family-goal-reward">
            🎁 Belohnung: <?= htmlspecialchars($familyGoal['reward_text']) ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Nächstes Achievement -->
    <?php if (!empty($nextAchievements)): $nxt = $nextAchievements[0]; ?>
    <div class="next-ach-card">
      <div class="next-ach-icon"><?= $mapIcon($nxt['icon']) ?></div>
      <div class="next-ach-info">
        <div class="next-ach-title">Nächstes Ziel</div>
        <div class="next-ach-name"><?= htmlspecialchars($nxt['title']) ?></div>
        <div class="progress-bar-wrap">
          <div class="progress-bar-fill" style="width:<?= $nxt['pct'] ?>%"></div>
        </div>
        <div class="next-ach-sub">
          <?= $nxt['current_value'] ?> / <?= (int)$nxt['trigger_value'] ?> <?= $nxt['label'] ?>
          &nbsp;·&nbsp; noch <?= $nxt['left'] ?> <?= $nxt['label'] ?>!
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Freigeschaltete Achievements -->
    <?php if (!empty($unlockedAchievements)): ?>
    <div class="achievements-section">
      <div class="achievements-label">🏆 Deine <?= htmlspecialchars($themeLabelAch) ?>en</div>
      <div class="achievements-row">
        <?php foreach ($unlockedAchievements as $ach):
          $isNew = !$ach['seen_by_user']; // war noch ungesehen vor diesem Laden
        ?>
          <div class="ach-badge <?= $isNew ? 'new' : '' ?>" title="">
            <span class="ach-icon"><?= $mapIcon($ach['icon']) ?></span>
            <?= htmlspecialchars($ach['title']) ?>
            <span class="ach-tooltip"><?= htmlspecialchars($ach['description']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="map-title">
      <h2>Deine Abenteuermap</h2>
      <p><?= htmlspecialchars($theme['flavor_texts']['welcome'] ?? 'Bereit für dein Abenteuer?') ?></p>
    </div>

    <?php foreach ($biomes as $biomeIndex => $biome):
      $biomeId  = $biome['theme_biome'] ?? 'forest';
      $isLocked    = $biome['status'] === 'locked';
      $isActive    = $biome['status'] === 'active';
      $isCompleted = $biome['status'] === 'completed';

      // Biom-Farbe aus theme.json, Fallback grau wenn gesperrt
      $bColors = $biomeColorMap[$biomeId] ?? ['from' => '#555', 'to' => '#777'];
      $biomeHeaderStyle = $isLocked
        ? 'background:linear-gradient(135deg,#555,#777)'
        : 'background:linear-gradient(135deg,' . $bColors['from'] . ',' . $bColors['to'] . ')';

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
        <div class="biome-header" style="<?= $biomeHeaderStyle ?>">
          <span class="biome-icon"><?= htmlspecialchars($biome['icon'] ?? '🌍') ?></span>
          <div>
            <div class="biome-name"><?= htmlspecialchars($biome['name']) ?></div>
            <div class="biome-block"><?= htmlspecialchars($themeLabelBiome) ?> · Block <?= htmlspecialchars($biome['block']) ?></div>
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
            <p style="color:#999;font-size:0.85rem;padding:0.5rem;">Keine <?= htmlspecialchars($themeLabelQuest) ?>s</p>
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
                     class="btn-practice pulse"
                     onclick="showWarmup(event, this.href, '<?= htmlspecialchars(addslashes($quest['title'])) ?>')">
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
                       class="btn-practice"
                       onclick="showWarmup(event, this.href, '<?= htmlspecialchars(addslashes($quest['title'])) ?>')">
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

<!-- Warm-Up Modal -->
<div class="warmup-overlay" id="warmup-overlay">
  <div class="warmup-modal">
    <div class="wm-icon">⚡</div>
    <h2>Bereit?</h2>
    <div class="wm-sub" id="wm-quest-title"></div>

    <?php if (!empty($parentMessages)): ?>
      <div class="wm-msg">
        <?= htmlspecialchars($parentMessages[0]['emoji'] ?? '💌') ?>
        <?= htmlspecialchars($parentMessages[0]['message']) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($familyGoal) && $familyGoal['status'] === 'active'): ?>
      <?php
        $gp  = (int)($familyGoal['progress'] ?? 0);
        $gv  = (int)$familyGoal['goal_value'];
        $gtl = match($familyGoal['goal_type']) {
          'sessions' => 'Einheiten', 'quests' => 'Quests', default => 'Tage'
        };
      ?>
      <div class="wm-goal">
        🎯 Ziel: <?= htmlspecialchars($familyGoal['title']) ?><br>
        Fortschritt: <?= $gp ?>/<?= $gv ?> <?= $gtl ?>
        <?php if ($familyGoal['reward_text']): ?>
          · 🎁 <?= htmlspecialchars($familyGoal['reward_text']) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <button class="btn-warmup-start" id="wm-start-btn" onclick="startSession()">
      Los geht's! 🚀
    </button>
    <div class="warmup-countdown" id="wm-countdown"></div>
  </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
let warmupTarget = null;
let warmupTimer  = null;

function showWarmup(e, href, questTitle) {
  e.preventDefault();
  warmupTarget = href;

  document.getElementById('wm-quest-title').textContent = questTitle || '';
  document.getElementById('warmup-overlay').classList.add('active');

  // Countdown: 5 Sek auto-start
  let secs = 5;
  const countdown = document.getElementById('wm-countdown');
  const btn       = document.getElementById('wm-start-btn');
  countdown.textContent = 'Startet automatisch in ' + secs + ' Sekunden …';
  warmupTimer = setInterval(() => {
    secs--;
    if (secs <= 0) {
      clearInterval(warmupTimer);
      startSession();
    } else {
      countdown.textContent = 'Startet automatisch in ' + secs + ' Sekunden …';
    }
  }, 1000);
}

function startSession() {
  clearInterval(warmupTimer);
  if (!warmupTarget) return;
  // Nachrichten als gelesen markieren (fire & forget)
  fetch(<?= json_encode(url('/learn/message/seen')) ?>, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf_token: CSRF_TOKEN }),
  }).catch(() => {});
  window.location.href = warmupTarget;
}

// Eltern-Nachricht wegklicken
function dismissParentMsg() {
  const card = document.getElementById('parent-msg-card');
  if (card) card.style.display = 'none';
  fetch(<?= json_encode(url('/learn/message/seen')) ?>, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf_token: CSRF_TOKEN }),
  }).catch(() => {});
}

// Klick außerhalb Modal schließt es
document.getElementById('warmup-overlay').addEventListener('click', function(e) {
  if (e.target === this) {
    clearInterval(warmupTimer);
    this.classList.remove('active');
  }
});
</script>

</body>
</html>
