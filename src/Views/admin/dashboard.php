<?php
/**
 * Papa-Dashboard
 *
 * Variablen (von DashboardController gesetzt):
 *   $children  — Array mit Kinder-Daten inkl. test_results, draft_plan, plan_biomes
 *   $flash     — ['type' => 'success'|'error', 'message' => '...'] | null
 */
use App\Helpers\Auth;

$csrfToken = Auth::csrfToken();
$pageTitle = 'Dashboard — ' . APP_NAME;

// Severity → CSS-Klasse + Farbe
$severityColor = [
    'none'     => '#4caf50',
    'mild'     => '#ffc107',
    'moderate' => '#ff9800',
    'severe'   => '#f44336',
];
$severityLabel = [
    'none'     => 'Gut',
    'mild'     => 'Leicht',
    'moderate' => 'Mittel',
    'severe'   => 'Schwer',
];

// Block-Labels
$blockLabels = [
    'A' => ['icon' => '🔊', 'name' => 'Block A', 'desc' => 'Laut-Buchstaben-Zuordnung'],
    'B' => ['icon' => '📖', 'name' => 'Block B', 'desc' => 'Regelwissen'],
    'C' => ['icon' => '🔄', 'name' => 'Block C', 'desc' => 'Ableitungswissen'],
    'D' => ['icon' => '🔤', 'name' => 'Block D', 'desc' => 'Groß-/Kleinschreibung'],
];

$formatLabel = [
    'word'       => 'Einzelwort',
    'gap'        => 'Lückentext',
    'sentence'   => 'Satzdiktat',
    'mini_diktat'=> 'Mini-Diktat',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
  <style>
    /* ── Dashboard-spezifische Styles ── */
    .dash-section        { margin-bottom: 2.5rem; }
    .dash-section-title  {
      font-size: 1.1rem; font-weight: 700; color: var(--color-primary-dk);
      border-bottom: 2px solid var(--color-border);
      padding-bottom: .4rem; margin-bottom: 1rem;
    }

    /* ── Kinder-Tabelle ── */
    .children-table      { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .children-table th   {
      text-align: left; padding: .5rem .75rem; background: #f5f5f5;
      border-bottom: 2px solid var(--color-border); font-size: .8rem;
      color: var(--color-muted); text-transform: uppercase; letter-spacing: .04em;
    }
    .children-table td   { padding: .6rem .75rem; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
    .children-table tr:last-child td { border-bottom: none; }
    .children-table tr:hover td      { background: #fafafa; }

    .badge {
      display: inline-block; padding: .15rem .45rem; border-radius: 4px;
      font-size: .72rem; font-weight: 600;
    }
    .badge-active   { background: #e8f5e9; color: #2e7d32; }
    .badge-inactive { background: #fce4ec; color: #c62828; }
    .badge-pending  { background: #fff3e0; color: #e65100; }
    .badge-done     { background: #e8f5e9; color: #1b5e20; }

    /* ── Action-Buttons Tabelle ── */
    .btn-icon { padding: .3rem .6rem; font-size: .85rem; }

    /* ── Alert-Banner ── */
    .pending-banner {
      background: #fff8e1; border: 1px solid #ffcc80; border-radius: 8px;
      padding: .85rem 1.1rem; display: flex; align-items: center; gap: .75rem;
      margin-bottom: .75rem;
    }
    .pending-banner-icon { font-size: 1.5rem; flex-shrink: 0; }
    .pending-banner-text { flex: 1; }
    .pending-banner-text strong { display: block; font-size: .9rem; }
    .pending-banner-text span   { font-size: .8rem; color: var(--color-muted); }

    /* ── Plan-Review-Karte ── */
    .plan-card {
      border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden;
      margin-bottom: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,.06);
    }
    .plan-card-header {
      background: var(--color-primary-dk); color: #fff;
      padding: .85rem 1.25rem; display: flex; align-items: center; gap: .75rem;
    }
    .plan-card-header h3 { font-size: 1rem; font-weight: 700; flex: 1; }
    .plan-card-header .badge { background: rgba(255,255,255,.2); color: #fff; }
    .plan-card-body  { padding: 1.25rem; }

    /* ── Fehlerprofil-Bars ── */
    .error-profile-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: .75rem; margin-bottom: 1.25rem;
    }
    .ep-block {
      background: #f9f9f9; border: 1px solid #e0e0e0;
      border-radius: 8px; padding: .75rem;
    }
    .ep-block-header {
      display: flex; align-items: center; gap: .4rem;
      font-weight: 700; font-size: .85rem; margin-bottom: .6rem;
    }
    .ep-category { margin-bottom: .35rem; }
    .ep-cat-label {
      display: flex; justify-content: space-between;
      font-size: .75rem; margin-bottom: .15rem; color: var(--color-text);
    }
    .ep-cat-code   { font-family: monospace; font-weight: 700; color: var(--color-primary-dk); }
    .ep-severity   { font-weight: 600; font-size: .7rem; }
    .ep-bar-track  { height: 7px; background: #e0e0e0; border-radius: 4px; overflow: hidden; }
    .ep-bar-fill   { height: 100%; border-radius: 4px; transition: width .4s; }
    .ep-empty      { color: var(--color-muted); font-size: .8rem; font-style: italic; }

    /* ── KI-Begründung ── */
    .ai-notes-box {
      background: #f0f4ff; border: 1px solid #c5cae9; border-radius: 8px;
      padding: .85rem 1rem; font-size: .875rem; margin-bottom: 1.25rem;
      line-height: 1.6;
    }
    .ai-notes-title { font-weight: 700; font-size: .8rem; color: #3949ab; margin-bottom: .35rem; }

    /* ── Plan-Struktur ── */
    .plan-biomes-list { margin-bottom: 1.25rem; }
    .plan-biome {
      border: 1px solid var(--color-border); border-radius: 8px;
      margin-bottom: .75rem; overflow: hidden;
    }
    .plan-biome-header {
      background: #f5f5f5; padding: .6rem .9rem;
      display: flex; align-items: center; gap: .5rem;
      font-weight: 700; font-size: .9rem;
    }
    .biome-block-badge {
      background: var(--color-primary); color: #fff;
      padding: .1rem .4rem; border-radius: 4px; font-size: .72rem;
    }
    .quest-list { padding: .4rem 0; }
    .quest-row {
      display: flex; align-items: center; gap: .6rem;
      padding: .4rem .9rem; font-size: .85rem;
      border-top: 1px solid #f0f0f0;
    }
    .quest-row:first-child { border-top: none; }
    .quest-row.skipped  { opacity: .5; }
    .quest-cat   { font-family: monospace; font-weight: 700; color: var(--color-primary-dk); width: 28px; flex-shrink: 0; }
    .quest-title { flex: 1; }
    .quest-units { font-size: .72rem; color: var(--color-muted); white-space: nowrap; }
    .quest-toggle {
      width: 28px; height: 18px; border-radius: 9px;
      border: 2px solid #bdbdbd; background: #e0e0e0;
      cursor: pointer; position: relative; flex-shrink: 0;
      transition: background .2s, border-color .2s;
    }
    .quest-toggle::after {
      content: ''; position: absolute;
      width: 12px; height: 12px; border-radius: 50%;
      background: #fff; top: 1px; left: 1px;
      transition: left .2s, box-shadow .2s;
      box-shadow: 0 1px 3px rgba(0,0,0,.3);
    }
    .quest-toggle.on { background: var(--color-primary); border-color: var(--color-primary); }
    .quest-toggle.on::after { left: 9px; }

    /* ── Plan-Aktionen ── */
    .plan-actions {
      display: flex; gap: .75rem; align-items: center;
      padding-top: 1rem; border-top: 1px solid var(--color-border);
    }
    .btn-approve {
      background: var(--color-primary); color: #fff;
      border: none; border-radius: 8px; padding: .65rem 1.5rem;
      font-size: .95rem; font-weight: 700; cursor: pointer;
      transition: background .15s;
    }
    .btn-approve:hover { background: var(--color-primary-dk); }
    .btn-approve:disabled { opacity: .5; cursor: default; }
    .plan-actions-note { font-size: .78rem; color: var(--color-muted); }

    /* ── No-Content ── */
    .empty-state {
      text-align: center; color: var(--color-muted);
      padding: 2rem; font-size: .9rem;
    }
    .empty-state-icon { font-size: 2.5rem; display: block; margin-bottom: .5rem; }

    /* ── Toast ── */
    #toast {
      position: fixed; bottom: 1.5rem; right: 1.5rem;
      background: #323232; color: #fff; padding: .75rem 1.25rem;
      border-radius: 8px; font-size: .875rem; box-shadow: 0 4px 12px rgba(0,0,0,.3);
      display: none; z-index: 1000; animation: slide-up .2s ease-out;
    }
    #toast.show { display: block; }
    #toast.success { background: #1b5e20; }
    #toast.error   { background: #b71c1c; }
    @keyframes slide-up {
      from { transform: translateY(12px); opacity: 0; }
      to   { transform: translateY(0);    opacity: 1; }
    }

    @media (max-width: 640px) {
      .error-profile-grid { grid-template-columns: 1fr; }
      .children-table th:nth-child(3),
      .children-table td:nth-child(3) { display: none; }
    }

    /* ── Motivation: Nachrichten & Ziele ── */
    .motiv-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.25rem;
      margin-bottom: 1.75rem;
    }
    @media (max-width: 700px) { .motiv-grid { grid-template-columns: 1fr; } }

    .motiv-card {
      background: #fff;
      border: 1px solid var(--color-border);
      border-radius: 10px;
      overflow: hidden;
    }
    .motiv-card-head {
      background: var(--color-primary-dk);
      color: #fff;
      padding: .6rem 1rem;
      font-size: .88rem;
      font-weight: 700;
    }
    .motiv-card-body { padding: 1rem; }

    .msg-list { margin-bottom: .75rem; }
    .msg-item {
      display: flex; align-items: flex-start; gap: .5rem;
      background: #fff8e1; border-radius: 8px; padding: .5rem .7rem;
      margin-bottom: .4rem; font-size: .85rem;
    }
    .msg-item-emoji { font-size: 1.1rem; flex-shrink: 0; }
    .msg-item-text  { flex: 1; color: #3e2723; }
    .msg-item-meta  { font-size: .72rem; color: #a0897a; }
    .msg-item-del   {
      background: none; border: none; color: #ccc;
      cursor: pointer; font-size: 1rem; padding: 0; flex-shrink: 0;
    }
    .msg-item-del:hover { color: #e53935; }
    .msg-empty { color: #999; font-size: .83rem; margin-bottom: .75rem; }

    .goal-current {
      background: #f1f8e9; border-radius: 8px; padding: .75rem;
      margin-bottom: .75rem; font-size: .88rem;
    }
    .goal-current-title { font-weight: 700; color: #33691e; margin-bottom: .4rem; }
    .goal-progress-bar  {
      height: 10px; background: #dcedc8; border-radius: 5px; overflow: hidden; margin-bottom: .3rem;
    }
    .goal-progress-fill {
      height: 100%; background: #7cb342; border-radius: 5px; transition: width .4s;
    }
    .goal-progress-sub  { font-size: .75rem; color: #558b2f; }
    .goal-reward-badge  {
      display: inline-block; background: #e8f5e9; border-radius: 6px;
      padding: .2rem .5rem; font-size: .78rem; color: #2e7d32; margin-top: .35rem;
    }
    .goal-cancel-btn { font-size: .75rem; color: #999; background: none; border: none; cursor: pointer; margin-top: .3rem; display: block; }
    .goal-cancel-btn:hover { color: #e53935; }

    .motiv-form label  { display: block; font-size: .78rem; color: #666; margin-bottom: .15rem; }
    .motiv-form input,
    .motiv-form select,
    .motiv-form textarea { width: 100%; box-sizing: border-box; margin-bottom: .6rem; }
    .motiv-form textarea { resize: vertical; min-height: 60px; }
    .motiv-form .emoji-row {
      display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: .6rem;
    }
    .motiv-form .emoji-btn {
      background: #f5f5f5; border: 2px solid transparent;
      border-radius: 8px; font-size: 1.2rem; cursor: pointer; padding: .2rem .4rem;
      transition: border-color .1s;
    }
    .motiv-form .emoji-btn.selected { border-color: var(--color-primary-dk); }
  </style>
</head>
<body>
<nav class="navbar">
  <span class="navbar-brand">⛏️ <?= htmlspecialchars(APP_NAME) ?></span>
  <span class="navbar-user">👤 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
  <a href="<?= url('/admin/words') ?>" class="btn btn-sm btn-secondary" style="margin-right:.35rem">📝 Wörter</a>
  <a href="<?= url('/logout') ?>" class="btn btn-sm">Abmelden</a>
</nav>

<main class="container">
  <h2 style="margin-bottom:1.5rem">Papa-Dashboard</h2>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?>" style="margin-bottom:1rem">
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════════
       1. KINDER-ÜBERSICHT
  ══════════════════════════════════════════════════════════════════════ -->
  <section class="dash-section">
    <div class="dash-section-title">🧒 Kinder-Übersicht</div>

    <?php if (empty($children)): ?>
      <div class="empty-state">
        <span class="empty-state-icon">👶</span>
        Noch keine Kinder angelegt.
        <br><a href="<?= url('/setup/wizard') ?>" class="btn btn-primary" style="margin-top:.75rem;display:inline-block">Kind hinzufügen</a>
      </div>
    <?php else: ?>
      <a href="<?= url('/setup/wizard') ?>" class="btn btn-secondary btn-sm" style="margin-bottom:.75rem">+ Kind hinzufügen</a>
      <table class="children-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Klasse</th>
            <th>Schulform</th>
            <th>Theme</th>
            <th>Letzter Login</th>
            <th>Test-Status</th>
            <th>Plan</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($children as $child): ?>
          <tr>
            <td><strong><?= htmlspecialchars($child['display_name']) ?></strong></td>
            <td><?= htmlspecialchars($child['grade_level'] ?? '—') ?></td>
            <td><?= htmlspecialchars($child['school_type'] ?? '—') ?></td>
            <td>⛏️ <?= htmlspecialchars(ucfirst($child['theme'] ?? 'minecraft')) ?></td>
            <td>
              <?php if ($child['last_login']): ?>
                <?= date('d.m.Y', strtotime($child['last_login'])) ?>
              <?php else: ?>
                <span style="color:var(--color-muted)">noch nie</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$child['latest_test']): ?>
                <span class="badge badge-pending">Kein Test</span>
              <?php elseif ($child['latest_test']['status'] !== 'completed'): ?>
                <span class="badge badge-pending">Läuft</span>
              <?php elseif ($child['analysis_status'] === 'pending'): ?>
                <span class="badge badge-pending">Auswertung ausstehend</span>
              <?php else: ?>
                <span class="badge badge-done">Ausgewertet</span>
                <?php if ($child['progress_test']['due'] ?? false): ?>
                  <span class="badge" style="background:#fff3e0;color:#e65100;margin-left:.25rem">⏰ Fortschritt fällig</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($child['active_plan']): ?>
                <span class="badge badge-active">Aktiv</span>
              <?php elseif ($child['draft_plan']): ?>
                <span class="badge badge-pending">Entwurf</span>
              <?php else: ?>
                <span style="color:var(--color-muted);font-size:.8rem">—</span>
              <?php endif; ?>
              <?php if ($child['analysis_status'] === 'done'): ?>
                <a href="<?= url('/admin/report/' . (int)$child['id']) ?>"
                   class="btn btn-sm btn-icon"
                   title="PDF-Bericht"
                   style="margin-left:.25rem"
                   target="_blank">📄</a>
              <?php endif; ?>
              <?php if ($child['analysis_status'] === 'done' && !$child['active_plan'] && !$child['draft_plan']): ?>
                <button class="btn btn-sm btn-primary"
                        style="margin-left:.25rem;font-size:.75rem"
                        onclick="runAnalysis(<?= (int)$child['latest_test']['id'] ?>, this, true)"
                        data-child="<?= htmlspecialchars($child['display_name']) ?>"
                        title="Lernplan neu generieren (Analyse bereits vorhanden)">
                  ♻️ Plan
                </button>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= url('/admin/child/' . (int)$child['id'] . '/edit') ?>"
                 class="btn btn-sm btn-secondary" title="Profil bearbeiten">✏️</a>
              <a href="<?= url('/admin/words?child_id=' . (int)$child['id']) ?>"
                 class="btn btn-sm btn-secondary" title="Wortliste" style="margin-left:.25rem">📝</a>
              <a href="<?= url('/admin/adventures?child_id=' . (int)$child['id']) ?>"
                 class="btn btn-sm btn-secondary" title="Abenteuer verwalten" style="margin-left:.25rem">🗺️</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <!-- ══════════════════════════════════════════════════════════════════
       2. AUSSTEHENDE AUSWERTUNGEN
  ══════════════════════════════════════════════════════════════════════ -->
  <?php
  $pendingAnalysis = array_filter($children, fn($c) =>
    $c['latest_test'] && $c['latest_test']['status'] === 'completed' &&
    $c['analysis_status'] === 'pending'
  );
  ?>
  <?php if (!empty($pendingAnalysis)): ?>
  <section class="dash-section">
    <div class="dash-section-title">⏳ Ausstehende Auswertungen</div>
    <?php foreach ($pendingAnalysis as $child): ?>
    <div class="pending-banner">
      <span class="pending-banner-icon">📋</span>
      <div class="pending-banner-text">
        <strong><?= htmlspecialchars($child['display_name']) ?> hat den Einstufungstest abgeschlossen</strong>
        <span>Test vom <?= date('d.m.Y H:i', strtotime($child['latest_test']['completed_at'] ?? 'now')) ?> Uhr</span>
      </div>
      <button class="btn btn-primary btn-sm"
              onclick="runAnalysis(<?= (int)$child['latest_test']['id'] ?>, this)"
              data-child="<?= htmlspecialchars($child['display_name']) ?>">
        🔍 Jetzt auswerten
      </button>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════════
       2b. FORTSCHRITTSTEST WARNUNGEN
  ══════════════════════════════════════════════════════════════════════ -->
  <?php
  $progressDueChildren = array_filter($children, fn($c) =>
    ($c['progress_test']['due'] ?? false) &&
    ($c['has_initial_test'] ?? false) &&
    ($c['active_plan'] ?? null)
  );
  ?>
  <?php if (!empty($progressDueChildren)): ?>
  <section class="dash-section">
    <div class="dash-section-title">⏰ Fortschrittstest fällig</div>
    <?php foreach ($progressDueChildren as $child): ?>
    <div class="pending-banner" style="border-left-color:#ff9800;">
      <span class="pending-banner-icon">🏆</span>
      <div class="pending-banner-text">
        <strong><?= htmlspecialchars($child['display_name']) ?> — Fortschrittstest fällig</strong>
        <span>
          Letzter Test: <?= htmlspecialchars($child['progress_test']['last_test_date'] ?? '—') ?> ·
          Intervall: <?= (int)($child['progress_test']['interval_days'] ?? 42) ?> Tage
          <?php if ($child['progress_test']['days_overdue'] > 0): ?>
            · <strong style="color:#c62828">+<?= (int)$child['progress_test']['days_overdue'] ?> Tage überfällig</strong>
          <?php endif; ?>
        </span>
      </div>
      <span style="font-size:.8rem;color:#888;">Kind muss selbst starten</span>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════════
       3. DRAFT-PLÄNE PRÜFEN + FEHLERPROFIL
  ══════════════════════════════════════════════════════════════════════ -->
  <?php
  $withDraftPlan = array_filter($children, fn($c) => $c['draft_plan'] !== null);
  ?>
  <?php if (!empty($withDraftPlan)): ?>
  <section class="dash-section">
    <div class="dash-section-title">📝 Pläne prüfen & bestätigen</div>

    <?php foreach ($withDraftPlan as $child): ?>
    <div class="plan-card" id="plan-card-<?= (int)$child['draft_plan']['id'] ?>">

      <!-- Plan-Kopf -->
      <div class="plan-card-header">
        <h3>🧒 <?= htmlspecialchars($child['display_name']) ?> — Lernplan (Entwurf)</h3>
        <span class="badge">Klasse <?= htmlspecialchars($child['grade_level'] ?? '?') ?></span>
      </div>

      <div class="plan-card-body">

        <!-- ── Fehlerprofil ── -->
        <?php if (!empty($child['test_results'])): ?>
        <div style="margin-bottom:.5rem">
          <div style="font-weight:700;font-size:.9rem;margin-bottom:.75rem">📊 Fehlerprofil</div>
          <div class="error-profile-grid">
            <?php
            // Ergebnisse nach Block gruppieren
            $byBlock = [];
            foreach ($child['test_results'] as $tr) {
                $byBlock[$tr['block']][] = $tr;
            }
            foreach (['A','B','C','D'] as $blk):
              $blockData = $byBlock[$blk] ?? [];
              if (empty($blockData)) continue;
              $bl = $blockLabels[$blk] ?? ['icon'=>'🔤','name'=>'Block '.$blk,'desc'=>''];
            ?>
            <div class="ep-block">
              <div class="ep-block-header">
                <?= $bl['icon'] ?> <?= htmlspecialchars($bl['name']) ?>
                <span style="font-weight:400;font-size:.75rem;color:var(--color-muted)">
                  — <?= htmlspecialchars($bl['desc']) ?>
                </span>
              </div>
              <?php foreach ($blockData as $tr): ?>
              <?php
                $errorPct  = (int)round($tr['error_rate'] * 100);
                $sev       = $tr['severity'] ?? 'none';
                $barColor  = $severityColor[$sev] ?? '#ccc';
                $sevLabel  = $severityLabel[$sev] ?? $sev;
              ?>
              <div class="ep-category">
                <div class="ep-cat-label">
                  <span>
                    <span class="ep-cat-code"><?= htmlspecialchars($tr['category']) ?></span>
                  </span>
                  <span>
                    <span class="ep-severity" style="color:<?= $barColor ?>">
                      <?= $sevLabel ?>
                    </span>
                    &nbsp;<?= $errorPct ?>% Fehler
                    <span style="color:var(--color-muted);font-size:.68rem">
                      (<?= (int)$tr['correct_items'] ?>/<?= (int)$tr['total_items'] ?>)
                    </span>
                  </span>
                </div>
                <div class="ep-bar-track">
                  <div class="ep-bar-fill"
                       style="width:<?= $errorPct ?>%;background:<?= $barColor ?>"></div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="ep-empty" style="margin-bottom:1rem">Kein Fehlerprofil vorhanden.</div>
        <?php endif; ?>

        <!-- ── KI-Begründung ── -->
        <?php if ($child['draft_plan']['ai_notes']): ?>
        <div class="ai-notes-box">
          <div class="ai-notes-title">🤖 KI-Begründung für diesen Plan</div>
          <?= nl2br(htmlspecialchars($child['draft_plan']['ai_notes'])) ?>
        </div>
        <?php endif; ?>

        <!-- ── Plan-Struktur ── -->
        <?php if (!empty($child['plan_biomes'])): ?>
        <div style="font-weight:700;font-size:.9rem;margin-bottom:.6rem">🗺️ Lernplan-Struktur</div>
        <div class="plan-biomes-list">
          <?php foreach ($child['plan_biomes'] as $biome): ?>
          <div class="plan-biome">
            <div class="plan-biome-header">
              <span class="biome-block-badge">Block <?= htmlspecialchars($biome['block']) ?></span>
              <?= htmlspecialchars($biome['name']) ?>
              <span style="color:var(--color-muted);font-weight:400;font-size:.8rem;margin-left:.3rem">
                (<?= count($biome['quests']) ?> Quests)
              </span>
            </div>
            <div class="quest-list">
              <?php foreach ($biome['quests'] as $quest): ?>
              <?php
                $isOn    = ($quest['status'] !== 'skipped');
                $unitTxt = (int)$quest['unit_count'] . ' ' . ($quest['unit_count'] == 1 ? 'Einheit' : 'Einheiten');
              ?>
              <div class="quest-row <?= $isOn ? '' : 'skipped' ?>"
                   id="quest-row-<?= (int)$quest['id'] ?>">
                <span class="quest-cat"><?= htmlspecialchars($quest['category']) ?></span>
                <span class="quest-title">
                  <?= htmlspecialchars($quest['title']) ?>
                  <?php if ($quest['ai_notes']): ?>
                    <span style="color:var(--color-muted);font-size:.72rem">
                      — <?= htmlspecialchars($quest['ai_notes']) ?>
                    </span>
                  <?php endif; ?>
                </span>
                <span class="quest-units"><?= $isOn ? $unitTxt : 'übersprungen' ?></span>
                <div class="quest-toggle <?= $isOn ? 'on' : '' ?>"
                     title="Quest ein-/ausschalten"
                     onclick="toggleQuest(<?= (int)$quest['id'] ?>, this)"
                     data-plan="<?= (int)$child['draft_plan']['id'] ?>"></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Plan-Aktionen ── -->
        <div class="plan-actions">
          <button class="btn-approve"
                  id="approve-btn-<?= (int)$child['draft_plan']['id'] ?>"
                  onclick="approvePlan(<?= (int)$child['draft_plan']['id'] ?>, this)">
            ✅ Plan bestätigen & aktivieren
          </button>
          <span class="plan-actions-note">
            Nach der Bestätigung beginnt <?= htmlspecialchars($child['display_name']) ?> mit dem Üben.
          </span>
        </div>

      </div><!-- /plan-card-body -->
    </div><!-- /plan-card -->
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════════
       4. AKTIVE PLÄNE (kompakt)
  ══════════════════════════════════════════════════════════════════════ -->
  <?php
  $withActivePlan = array_filter($children, fn($c) => $c['active_plan'] !== null);
  ?>
  <?php if (!empty($withActivePlan)): ?>
  <section class="dash-section">
    <div class="dash-section-title">✅ Aktive Lernpläne</div>
    <?php foreach ($withActivePlan as $child): ?>
    <div style="padding:.75rem 1rem;background:#e8f5e9;border:1px solid #a5d6a7;
                border-radius:8px;margin-bottom:.5rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <span style="font-size:1.3rem">🧒</span>
      <div style="flex:1">
        <strong><?= htmlspecialchars($child['display_name']) ?></strong>
        — Plan aktiviert am <?= date('d.m.Y', strtotime($child['active_plan']['activated_at'] ?? 'now')) ?>
      </div>
      <button class="btn btn-sm btn-secondary"
              style="font-size:.75rem"
              onclick="resetPlan(<?= (int)$child['active_plan']['id'] ?>, '<?= htmlspecialchars(addslashes($child['display_name'])) ?>', <?= (int)($child['latest_test']['id'] ?? 0) ?>)"
              title="Lernplan zurücksetzen und neu generieren (Fehleranalyse bleibt erhalten)">
        🔄 Plan zurücksetzen
      </button>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════════
       5. FORTSCHRITTSGRAFIKEN (Chart.js)
  ══════════════════════════════════════════════════════════════════════ -->
  <?php
  $withCharts = array_filter($children, fn($c) =>
    !empty($c['chart_data']) &&
    // Mindestens 2 Test-Datenpunkte für eine sinnvolle Grafik
    count($c['chart_data'][array_key_first($c['chart_data'])]['labels'] ?? []) >= 1
  );
  ?>
  <!-- ══ TTS-CACHE VORWÄRMEN ══════════════════════════════════════════ -->
  <section class="dash-section">
    <div class="dash-section-title">🔊 TTS-Cache vorwärmen</div>
    <p style="color:var(--color-muted);font-size:.9rem;margin-bottom:.75rem">
      Generiert Audio-Dateien für alle Wörter <em>und</em> Sätze im Voraus — danach startet der Test sofort ohne Ladepause.
    </p>
    <button class="btn btn-primary btn-sm" id="btn-tts-warm" type="button">▶ Jetzt vorwärmen</button>
    <span id="tts-warm-status" style="margin-left:1rem;font-size:.9rem;color:var(--color-muted)"></span>
    <div id="tts-warm-bar" style="display:none;margin-top:.75rem;background:#e0e0e0;border-radius:4px;height:8px;max-width:400px">
      <div id="tts-warm-fill" style="height:8px;background:#4caf50;border-radius:4px;width:0%;transition:width .3s"></div>
    </div>
    <script>
    (function () {
      var btn    = document.getElementById('btn-tts-warm');
      var status = document.getElementById('tts-warm-status');
      var bar    = document.getElementById('tts-warm-bar');
      var fill   = document.getElementById('tts-warm-fill');

      // Phase 1: Wörter, Phase 2: Sätze
      var phases = ['words', 'sentences'];
      var phaseLabels = {words: 'Wörter', sentences: 'Sätze'};
      var phaseIdx = 0;
      var phaseTotals = {};    // {words: N, sentences: M}
      var phaseProcessed = {}; // zählt Items (nicht Audiodateien)

      // Jede Phase trägt gleich viel zum Gesamtfortschritt bei (0–50% / 50–100%)
      function overallPct() {
        var pct = 0;
        for (var i = 0; i < phases.length; i++) {
          var ph = phases[i];
          var total = phaseTotals[ph] || 0;
          if (total > 0) {
            pct += Math.min(1, (phaseProcessed[ph] || 0) / total) / phases.length;
          }
        }
        return Math.min(100, Math.round(pct * 100));
      }

      function warmBatch(type, offset) {
        fetch('<?= url('/admin/tts/warm') ?>&type=' + type + '&offset=' + offset)
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.error) { status.textContent = '⚠ ' + data.error; btn.disabled = false; return; }
            if (data.provider === 'browser') {
              status.textContent = '✓ Browser-TTS benötigt keinen Cache.';
              btn.disabled = false; return;
            }
            // data.offset = nächste Position = verarbeitete Items bisher
            phaseTotals[type]    = data.total;
            phaseProcessed[type] = Math.min(data.offset, data.total);
            var pct = overallPct();
            fill.style.width    = pct + '%';
            status.textContent  = phaseLabels[type] + ': ' + phaseProcessed[type] + '/' + data.total
                                + ' — gesamt ' + pct + '%';
            if (!data.finished) {
              warmBatch(type, data.offset);
            } else {
              phaseIdx++;
              if (phaseIdx < phases.length) {
                warmBatch(phases[phaseIdx], 0);
              } else {
                fill.style.width   = '100%';
                status.textContent = '✅ Fertig! Wörter + Sätze gecacht.';
                btn.disabled = false;
              }
            }
          })
          .catch(function() { status.textContent = '⚠ Fehler.'; btn.disabled = false; });
      }

      btn.addEventListener('click', function() {
        btn.disabled = true;
        phaseIdx = 0; phaseTotals = {}; phaseProcessed = {};
        bar.style.display = '';
        fill.style.width  = '0%';
        status.textContent = 'Läuft…';
        warmBatch(phases[0], 0);
      });
    })();
    </script>
  </section>

  <?php if (!empty($withCharts)): ?>
  <section class="dash-section">
    <div class="dash-section-title">📈 Fortschrittsgrafiken</div>

    <?php foreach ($withCharts as $child):
      $cid = (int)$child['id'];
      $chartData = $child['chart_data'];
      $hasMultiplePoints = max(array_map(
        fn($b) => count($b['labels']),
        array_values($chartData)
      )) > 1;
    ?>
    <div style="margin-bottom:2rem">
      <h3 style="font-size:.95rem;color:#444;margin-bottom:1rem">
        🧒 <?= htmlspecialchars($child['display_name']) ?>
        <?php if (!$hasMultiplePoints): ?>
          <span style="font-size:.8rem;font-weight:normal;color:#999">
            (Erst nach dem zweiten Test verfügbar)
          </span>
        <?php endif; ?>
      </h3>

      <?php if ($hasMultiplePoints): ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem">
        <?php foreach (['A','B','C','D'] as $block):
          if (!isset($chartData[$block])) continue;
          $blockNames = ['A'=>'Block A — Laut/Buchstaben','B'=>'Block B — Regelwissen','C'=>'Block C — Ableitung','D'=>'Block D — Groß-/Klein'];
        ?>
        <div style="background:#fff;border:1px solid var(--color-border);border-radius:8px;padding:1rem">
          <div style="font-size:.8rem;font-weight:700;color:var(--color-muted);margin-bottom:.5rem">
            <?= htmlspecialchars($blockNames[$block] ?? $block) ?>
          </div>
          <div style="position:relative;height:180px">
            <canvas id="chart-<?= $cid ?>-<?= $block ?>"></canvas>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php else: ?>
      <!-- Noch kein Zeitverlauf — zeige Balkendiagramm des aktuellen Stands -->
      <div style="background:#fff;border:1px solid var(--color-border);border-radius:8px;padding:1rem">
        <div style="font-size:.8rem;color:#999;margin-bottom:.75rem">Aktueller Stand nach Einstufungstest</div>
        <canvas id="chart-current-<?= $cid ?>" style="max-height:200px"></canvas>
      </div>
      <?php endif; ?>
    </div>

    <!-- Chart-Daten für JS -->
    <script>
    (function() {
      var chartData = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
      var cid = <?= $cid ?>;
      var hasMulti = <?= $hasMultiplePoints ? 'true' : 'false' ?>;

      var lineOpts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                return ctx.dataset.label + ': ' + ctx.parsed.y + '%';
              }
            }
          }
        },
        scales: {
          y: {
            min: 0, max: 100,
            ticks: { callback: function(v) { return v + '%'; }, font: { size: 10 } },
            title: { display: true, text: 'Fehlerrate', font: { size: 10 } }
          },
          x: { ticks: { font: { size: 10 } } }
        }
      };

      if (hasMulti) {
        ['A','B','C','D'].forEach(function(block) {
          var d = chartData[block];
          if (!d) return;
          var canvas = document.getElementById('chart-' + cid + '-' + block);
          if (!canvas) return;
          new Chart(canvas, { type: 'line', data: d, options: lineOpts });
        });
      } else {
        // Balkendiagramm: alle Kategorien aus allen Blöcken
        var labels = [], values = [], colors = [];
        var palette = {
          'none':     '#4caf50',
          'mild':     '#ffc107',
          'moderate': '#ff9800',
          'severe':   '#f44336'
        };
        var severityFromRate = function(r) {
          if (r < 10) return 'none';
          if (r < 30) return 'mild';
          if (r < 60) return 'moderate';
          return 'severe';
        };
        ['A','B','C','D'].forEach(function(block) {
          var d = chartData[block];
          if (!d) return;
          d.datasets.forEach(function(ds) {
            labels.push(ds.label);
            var val = ds.data[0] !== null ? ds.data[0] : 0;
            values.push(val);
            colors.push(palette[severityFromRate(val)]);
          });
        });
        var canvas = document.getElementById('chart-current-' + cid);
        if (canvas) {
          new Chart(canvas, {
            type: 'bar',
            data: {
              labels: labels,
              datasets: [{
                label: 'Fehlerrate (%)',
                data: values,
                backgroundColor: colors,
                borderRadius: 4,
              }]
            },
            options: {
              responsive: true,
              plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: function(c) { return c.parsed.y + '%'; } } }
              },
              scales: {
                y: { min: 0, max: 100, ticks: { callback: function(v) { return v + '%'; } } }
              }
            }
          });
        }
      }
    })();
    </script>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════════
       SESSION-VERLAUF pro Kind
  ══════════════════════════════════════════════════════════════════════ -->
  <?php if (!empty($children)): ?>
  <section class="dash-section">
    <div class="dash-section-title">📋 Übungseinheiten — Verlauf</div>
    <?php foreach ($children as $child):
      $cid = (int)$child['id'];
      // Letzte 20 abgeschlossene Sessions laden
      $sesHistStmt = db()->prepare(
        "SELECT s.id, s.started_at, s.correct_first_try, s.wrong_total, s.total_items,
                s.duration_seconds,
                CASE WHEN s.custom_adventure_id IS NOT NULL THEN ca.title
                     ELSE COALESCE(q.title, pu.format)
                END AS label,
                CASE WHEN s.custom_adventure_id IS NOT NULL THEN 1 ELSE 0 END AS is_adventure
         FROM sessions s
         LEFT JOIN plan_units pu ON s.plan_unit_id = pu.id
         LEFT JOIN quests q ON pu.quest_id = q.id
         LEFT JOIN custom_adventures ca ON s.custom_adventure_id = ca.id
         WHERE s.user_id=? AND s.status='completed'
         ORDER BY s.started_at DESC
         LIMIT 20"
      );
      $sesHistStmt->execute([$cid]);
      $sessionHistory = $sesHistStmt->fetchAll();
    ?>
    <div style="margin-bottom:1.5rem">
      <h3 style="font-size:.95rem;color:#444;margin:.5rem 0 .75rem">
        🧒 <?= htmlspecialchars($child['display_name']) ?>
      </h3>
      <?php if (empty($sessionHistory)): ?>
        <p style="color:#999;font-size:.85rem">Noch keine Übungseinheiten.</p>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table class="table" style="font-size:.83rem">
          <thead>
            <tr>
              <th>Datum</th>
              <th>Einheit</th>
              <th style="text-align:center">✅ Richtig</th>
              <th style="text-align:center">❌ Falsch</th>
              <th style="text-align:center">Gesamt</th>
              <th style="text-align:center">Dauer</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sessionHistory as $sh): ?>
            <tr>
              <td><?= date('d.m.Y', strtotime($sh['started_at'])) ?></td>
              <td>
                <?php if ($sh['is_adventure']): ?>
                  <span title="Zusätzliches Abenteuer">🗺️</span>
                <?php endif; ?>
                <?= htmlspecialchars($sh['label'] ?? '—') ?>
              </td>
              <td style="text-align:center;color:#2e7d32;font-weight:600">
                <?= (int)$sh['correct_first_try'] ?>
              </td>
              <td style="text-align:center;color:#c62828;font-weight:600">
                <?= (int)$sh['wrong_total'] ?>
              </td>
              <td style="text-align:center"><?= (int)$sh['total_items'] ?></td>
              <td style="text-align:center;color:#666">
                <?php
                  $dur = (int)$sh['duration_seconds'];
                  echo $dur > 0 ? floor($dur/60) . 'min' : '—';
                ?>
              </td>
              <td>
                <button class="btn btn-sm btn-secondary"
                        onclick="loadSessionDetail(<?= (int)$sh['id'] ?>, this)"
                        data-session="<?= (int)$sh['id'] ?>">
                  Details
                </button>
              </td>
            </tr>
            <tr id="detail-row-<?= (int)$sh['id'] ?>" style="display:none">
              <td colspan="7" style="padding:.25rem .75rem .75rem">
                <div id="detail-body-<?= (int)$sh['id'] ?>"
                     style="font-size:.82rem;background:#fafafa;border-radius:6px;padding:.75rem">
                  Lade…
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════════════
       MOTIVATION: NACHRICHTEN & FAMILIENZIELE
  ════════════════════════════════════════════════════════════════════ -->
  <?php if (!empty($children)): ?>
  <section class="dash-section">
    <div class="dash-section-title">💌 Motivation — Nachrichten & Familienziele</div>

    <?php foreach ($children as $child):
      $cid      = (int)$child['id'];
      $messages = $child['messages']    ?? [];
      $goal     = $child['active_goal'] ?? null;
    ?>
    <div style="margin-bottom:2rem">
      <h3 style="font-size:.95rem;color:#444;margin:0 0 .75rem">
        🧒 <?= htmlspecialchars($child['display_name']) ?>
      </h3>

      <div class="motiv-grid">

        <!-- ── Nachrichten ── -->
        <div class="motiv-card">
          <div class="motiv-card-head">💌 Nachrichten an <?= htmlspecialchars($child['display_name']) ?></div>
          <div class="motiv-card-body">

            <?php if (!empty($messages)): ?>
              <div class="msg-list">
                <?php foreach ($messages as $msg): ?>
                <div class="msg-item">
                  <span class="msg-item-emoji"><?= htmlspecialchars($msg['emoji'] ?? '💌') ?></span>
                  <div style="flex:1">
                    <div class="msg-item-text"><?= htmlspecialchars($msg['message']) ?></div>
                    <div class="msg-item-meta">
                      <?= date('d.m. H:i', strtotime($msg['created_at'])) ?>
                      <?= $msg['seen_at'] ? '· gesehen ✓' : '· noch nicht gesehen' ?>
                    </div>
                  </div>
                  <form method="POST" action="<?= url('/admin/message/delete') ?>" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="message_id" value="<?= (int)$msg['id'] ?>">
                    <button type="submit" class="msg-item-del" title="Nachricht löschen">✕</button>
                  </form>
                </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="msg-empty">Keine Nachrichten.</div>
            <?php endif; ?>

            <!-- Neue Nachricht senden -->
            <form method="POST" action="<?= url('/admin/message/send') ?>" class="motiv-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="child_id"   value="<?= $cid ?>">
              <input type="hidden" name="emoji" id="emoji-val-<?= $cid ?>" value="💌">

              <div class="emoji-row" id="emoji-row-<?= $cid ?>">
                <?php foreach (['💌','⭐','🚀','🏆','💪','🔥','🎉','👏','❤️','🌟'] as $em): ?>
                  <button type="button" class="emoji-btn <?= $em === '💌' ? 'selected' : '' ?>"
                          onclick="selectEmoji(<?= $cid ?>, '<?= $em ?>', this)">
                    <?= $em ?>
                  </button>
                <?php endforeach; ?>
              </div>

              <label>Nachricht</label>
              <textarea name="message" placeholder="Toller Job heute! Ich bin stolz auf dich 🌟" rows="2" required></textarea>
              <button type="submit" class="btn btn-sm btn-primary">Nachricht senden 💌</button>
            </form>
          </div>
        </div>

        <!-- ── Familienziel ── -->
        <div class="motiv-card">
          <div class="motiv-card-head">🎯 Familienziel für <?= htmlspecialchars($child['display_name']) ?></div>
          <div class="motiv-card-body">

            <?php if ($goal): ?>
              <?php
                $gp  = (int)($goal['progress'] ?? 0);
                $gv  = (int)$goal['goal_value'];
                $pct = $gv > 0 ? min(100, round($gp / $gv * 100)) : 0;
                $gtl = match($goal['goal_type']) {
                  'sessions' => 'Einheiten', 'quests' => 'Quests', default => 'Tage Streak'
                };
                $periodLabel = match($goal['period']) {
                  'week' => 'diese Woche', 'month' => 'diesen Monat', default => 'gesamt'
                };
              ?>
              <div class="goal-current">
                <div class="goal-current-title">
                  🎯 <?= htmlspecialchars($goal['title']) ?>
                  <?php if ($goal['status'] === 'completed'): ?>
                    <span style="color:#4caf50"> ✅ Erreicht!</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:.78rem;color:#558b2f;margin-bottom:.5rem">
                  <?= $gtl ?> <?= htmlspecialchars($periodLabel) ?>
                  · Ziel: <?= $gv ?>
                </div>
                <div class="goal-progress-bar">
                  <div class="goal-progress-fill" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="goal-progress-sub"><?= $gp ?> / <?= $gv ?> <?= $gtl ?> (<?= $pct ?>%)</div>
                <?php if ($goal['reward_text']): ?>
                  <span class="goal-reward-badge">🎁 <?= htmlspecialchars($goal['reward_text']) ?></span>
                <?php endif; ?>
                <form method="POST" action="<?= url('/admin/goal/delete') ?>" style="display:inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                  <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
                  <button type="submit" class="goal-cancel-btn"
                          onclick="return confirm('Ziel wirklich löschen?')">
                    Ziel löschen
                  </button>
                </form>
              </div>
            <?php else: ?>
              <div class="msg-empty">Kein aktives Ziel.</div>
            <?php endif; ?>

            <!-- Neues Ziel setzen -->
            <form method="POST" action="<?= url('/admin/goal/save') ?>" class="motiv-form"
                  <?= $goal && $goal['status'] === 'active' ? 'style="opacity:.75"' : '' ?>>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
              <input type="hidden" name="child_id"   value="<?= $cid ?>">

              <label>Titel des Ziels</label>
              <input type="text" name="title" placeholder="Diese Woche 5 Einheiten!" required
                     maxlength="200">

              <label>Zeitraum &amp; Typ</label>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin-bottom:.6rem">
                <select name="period">
                  <option value="week">Diese Woche</option>
                  <option value="month">Diesen Monat</option>
                  <option value="alltime">Gesamt</option>
                </select>
                <select name="goal_type">
                  <option value="sessions">Einheiten</option>
                  <option value="quests">Quests</option>
                  <option value="streak">Tage Streak</option>
                </select>
              </div>

              <label>Anzahl (Zielwert)</label>
              <input type="number" name="goal_value" min="1" max="999" value="5" required>

              <label>Belohnung (optional)</label>
              <input type="text" name="reward_text" placeholder="Dann gehen wir Eis essen 🍦"
                     maxlength="200">

              <button type="submit" class="btn btn-sm btn-primary"
                      <?= $goal && $goal['status'] === 'active'
                          ? 'onclick="return confirm(\'Läuft bereits ein Ziel. Altes Ziel ersetzen?\')"'
                          : '' ?>>
                🎯 Ziel setzen
              </button>
            </form>
          </div>
        </div>

      </div><!-- .motiv-grid -->
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- Keine Kinder + kein Ausstehend -->
  <?php if (empty($pendingAnalysis) && empty($withDraftPlan) && !empty($children)): ?>
  <div class="empty-state" style="margin-top:2rem">
    <span class="empty-state-icon">🎉</span>
    Alles erledigt — keine ausstehenden Aktionen.
  </div>
  <?php endif; ?>

</main>

<!-- Toast-Notification -->
<div id="toast"></div>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;
const URL_ANALYSIS_STEP1   = <?= json_encode(url('/admin/analysis/step1')) ?>;
const URL_ANALYSIS_STEP2   = <?= json_encode(url('/admin/analysis/step2')) ?>;
const URL_ANALYSIS_RUN     = <?= json_encode(url('/admin/analysis/run')) ?>;
const URL_QUEST_TOGGLE     = <?= json_encode(url('/admin/plan/quest-toggle')) ?>;
const URL_PLAN_APPROVE     = <?= json_encode(url('/admin/plan/approve')) ?>;
const URL_SESSION_DETAIL   = <?= json_encode(url('/admin/sessions/detail')) ?>;
const URL_PLAN_RESET       = <?= json_encode(url('/admin/plan/reset')) ?>;

// ── Session-Details (pro Wort) ────────────────────────────────────────
var _sessionDetailLoaded = {};
function loadSessionDetail(sessionId, btn) {
  var detailRow  = document.getElementById('detail-row-'  + sessionId);
  var detailBody = document.getElementById('detail-body-' + sessionId);
  if (!detailRow) return;

  // Toggle
  if (detailRow.style.display !== 'none') {
    detailRow.style.display = 'none';
    btn.textContent = 'Details';
    return;
  }

  detailRow.style.display = 'table-row';
  btn.textContent = 'Verbergen';

  if (_sessionDetailLoaded[sessionId]) return; // schon geladen

  fetch(URL_SESSION_DETAIL + '&session_id=' + sessionId)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      _sessionDetailLoaded[sessionId] = true;
      if (data.error) { detailBody.textContent = data.error; return; }
      var html = '<table style="width:100%;border-collapse:collapse">' +
        '<tr style="color:#666;font-size:.78rem">' +
        '<th style="text-align:left;padding:.2rem .4rem">Wort / Text</th>' +
        '<th style="padding:.2rem .4rem">Eingabe</th>' +
        '<th style="padding:.2rem .4rem">Ergebnis</th>' +
        '</tr>';
      data.items.forEach(function(item) {
        var ok = item.final_correct;
        var color = ok === null ? '#999' : (ok ? '#2e7d32' : '#c62828');
        var icon  = ok === null ? '—'   : (ok ? '✅' : '❌');
        html += '<tr style="border-top:1px solid #eee">' +
          '<td style="padding:.25rem .4rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
            escHtml(item.text) + '</td>' +
          '<td style="padding:.25rem .4rem;text-align:center;color:' + color + '">' +
            escHtml(item.user_input || '—') + '</td>' +
          '<td style="padding:.25rem .4rem;text-align:center">' + icon + '</td>' +
          '</tr>';
      });
      html += '</table>';
      detailBody.innerHTML = html;
    })
    .catch(function() { detailBody.textContent = 'Fehler beim Laden.'; });
}

function escHtml(s) {
  return String(s || '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;');
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'show ' + type;
  setTimeout(() => { t.className = ''; }, 3500);
}

// ── Auswertung starten (Admin-seitig, 2-Schritt) ──────────────────────
function runAnalysis(testId, btn, planOnly = false) {
  const child       = btn.dataset.child || 'Kind';
  const origLabel   = btn.textContent;
  btn.disabled      = true;

  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(r => r.text().then(txt => {
      try { return JSON.parse(txt); }
      catch(e) { throw new Error('Server-Antwort kein JSON: ' + txt.substring(0, 200)); }
    }));
  }

  function onError(msg) {
    showToast('❌ Fehler: ' + msg, 'error');
    btn.disabled    = false;
    btn.textContent = origLabel;
  }

  if (planOnly) {
    // Nur Schritt 2 (test_results vorhanden, nur Plan fehlt)
    btn.textContent = '⏳ Plan wird erstellt…';
    postJSON(URL_ANALYSIS_STEP2, { csrf_token: CSRF, test_id: testId, child_id: 0 })
      .then(d => {
        if (d.success) {
          showToast('✅ Plan für ' + child + ' erstellt. Seite wird neu geladen…');
          setTimeout(() => location.reload(), 1500);
        } else {
          onError(d.message || d.error || 'Unbekannter Fehler');
        }
      })
      .catch(err => onError(err.message || 'Netzwerkfehler'));
    return;
  }

  // Schritt 1: Fehleranalyse
  btn.textContent = '⏳ Schritt 1/2: Analysiere…';
  postJSON(URL_ANALYSIS_STEP1, { csrf_token: CSRF, test_id: testId })
    .then(d1 => {
      if (d1.already_done) {
        // Analyse schon vorhanden → direkt Plan erstellen
        btn.textContent = '⏳ Schritt 2/2: Plan wird erstellt…';
        return postJSON(URL_ANALYSIS_STEP2, {
          csrf_token: CSRF, test_id: d1.test_id || testId, child_id: d1.child_id || 0,
        });
      }
      if (!d1.success) throw new Error(d1.message || d1.error || 'Schritt 1 fehlgeschlagen');

      // Schritt 2: Plan generieren
      btn.textContent = '⏳ Schritt 2/2: Plan wird erstellt…';
      return postJSON(URL_ANALYSIS_STEP2, {
        csrf_token: CSRF, test_id: d1.test_id, child_id: d1.child_id,
      });
    })
    .then(d2 => {
      if (d2 && (d2.success || d2.already_done)) {
        showToast('✅ Auswertung für ' + child + ' abgeschlossen. Seite wird neu geladen…');
        setTimeout(() => location.reload(), 1500);
      } else if (d2) {
        onError(d2.message || d2.error || 'Schritt 2 fehlgeschlagen');
      }
    })
    .catch(err => onError(err.message || 'Netzwerkfehler'));
}

// ── Quest ein-/ausschalten ─────────────────────────────────────────────
function toggleQuest(questId, toggleEl) {
  const row = document.getElementById('quest-row-' + questId);

  fetch(URL_QUEST_TOGGLE, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf_token: CSRF, quest_id: questId }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        const isOn = (data.new_status !== 'skipped');
        toggleEl.classList.toggle('on', isOn);
        row.classList.toggle('skipped', !isOn);
        // Einheiten-Text aktualisieren
        const unitSpan = row.querySelector('.quest-units');
        if (unitSpan) unitSpan.textContent = isOn ? 'aktualisiert' : 'übersprungen';
      } else {
        showToast('❌ ' + (data.error || 'Fehler'), 'error');
      }
    })
    .catch(() => showToast('❌ Netzwerkfehler.', 'error'));
}

// ── Plan zurücksetzen & neu generieren ───────────────────────────────
function resetPlan(planId, childName, testId) {
  if (!confirm('Lernplan von ' + childName + ' zurücksetzen?\n\nDer aktuelle Plan wird archiviert und ein neuer Plan wird sofort generiert. Die Fehleranalyse bleibt erhalten.\n\nAchtung: Der Fortschritt im aktuellen Plan geht verloren.')) return;

  fetch(URL_PLAN_RESET, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf_token: CSRF, plan_id: planId }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast('✅ Plan zurückgesetzt — generiere neuen Plan…');
        // Automatisch neuen Plan generieren
        setTimeout(() => {
          const fakeBtn = document.createElement('button');
          fakeBtn.dataset.child = childName;
          document.body.appendChild(fakeBtn);
          runAnalysis(testId, fakeBtn, true);
        }, 800);
      } else {
        showToast('❌ ' + (data.error || 'Fehler'), 'error');
      }
    })
    .catch(() => showToast('❌ Netzwerkfehler.', 'error'));
}

// ── Plan bestätigen ────────────────────────────────────────────────────
function approvePlan(planId, btn) {
  if (!confirm('Plan aktivieren? Das Kind kann dann sofort mit dem Üben beginnen.')) return;

  btn.disabled    = true;
  btn.textContent = '⏳ Wird aktiviert…';

  fetch(URL_PLAN_APPROVE, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf_token: CSRF, plan_id: planId }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast('✅ Plan aktiviert!');
        const card = document.getElementById('plan-card-' + planId);
        if (card) {
          card.style.transition = 'opacity .4s';
          card.style.opacity    = '0';
          setTimeout(() => location.reload(), 1200);
        }
      } else {
        showToast('❌ ' + (data.error || 'Fehler'), 'error');
        btn.disabled    = false;
        btn.textContent = '✅ Plan bestätigen & aktivieren';
      }
    })
    .catch(() => {
      showToast('❌ Netzwerkfehler.', 'error');
      btn.disabled    = false;
      btn.textContent = '✅ Plan bestätigen & aktivieren';
    });
}

// ── Emoji-Auswahl für Nachrichten ────────────────────────────────────
function selectEmoji(childId, emoji, btn) {
  document.getElementById('emoji-val-' + childId).value = emoji;
  const row = document.getElementById('emoji-row-' + childId);
  row.querySelectorAll('.emoji-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
}
</script>
</body>
</html>
