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
  <link rel="stylesheet" href="/css/app.css">
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
  </style>
</head>
<body>
<nav class="navbar">
  <span class="navbar-brand">⛏️ <?= htmlspecialchars(APP_NAME) ?></span>
  <span class="navbar-user">👤 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
  <a href="/logout" class="btn btn-sm">Abmelden</a>
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
        <br><a href="/setup/wizard" class="btn btn-primary" style="margin-top:.75rem;display:inline-block">Kind hinzufügen</a>
      </div>
    <?php else: ?>
      <a href="/setup/wizard" class="btn btn-secondary btn-sm" style="margin-bottom:.75rem">+ Kind hinzufügen</a>
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
                border-radius:8px;margin-bottom:.5rem;display:flex;align-items:center;gap:.75rem">
      <span style="font-size:1.3rem">🧒</span>
      <div style="flex:1">
        <strong><?= htmlspecialchars($child['display_name']) ?></strong>
        — Plan aktiviert am <?= date('d.m.Y', strtotime($child['active_plan']['activated_at'] ?? 'now')) ?>
      </div>
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

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = 'show ' + type;
  setTimeout(() => { t.className = ''; }, 3500);
}

// ── Auswertung starten (Admin-seitig) ──────────────────────────────────
function runAnalysis(testId, btn) {
  const child = btn.dataset.child || 'Kind';
  btn.disabled    = true;
  btn.textContent = '⏳ Läuft…';

  fetch('/admin/analysis/run', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf_token: CSRF, test_id: testId }),
  })
    .then(r => r.json())
    .then(data => {
      if (data.success || data.already_done) {
        showToast('✅ Auswertung für ' + child + ' abgeschlossen. Seite wird neu geladen…');
        setTimeout(() => location.reload(), 1500);
      } else {
        showToast('❌ Fehler: ' + (data.message || data.error), 'error');
        btn.disabled    = false;
        btn.textContent = '🔍 Jetzt auswerten';
      }
    })
    .catch(() => {
      showToast('❌ Netzwerkfehler.', 'error');
      btn.disabled    = false;
      btn.textContent = '🔍 Jetzt auswerten';
    });
}

// ── Quest ein-/ausschalten ─────────────────────────────────────────────
function toggleQuest(questId, toggleEl) {
  const row = document.getElementById('quest-row-' + questId);

  fetch('/admin/plan/quest-toggle', {
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

// ── Plan bestätigen ────────────────────────────────────────────────────
function approvePlan(planId, btn) {
  if (!confirm('Plan aktivieren? Das Kind kann dann sofort mit dem Üben beginnen.')) return;

  btn.disabled    = true;
  btn.textContent = '⏳ Wird aktiviert…';

  fetch('/admin/plan/approve', {
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
</script>
</body>
</html>
