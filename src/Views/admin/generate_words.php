<?php
/**
 * Admin — Wörter neu generieren + Block-Übersicht
 *
 * Variablen (von WordController::generatePage()):
 *   $children        array   — alle Kinder des Admins
 *   $childInfo       array|null
 *   $categoryStatus  array   — code → {label, curriculum_text, examples_official, word_count, min_words, block}
 *   $curriculumMeta  array   — {source, federal_state, school_type, grades}
 *   $error           string|null
 */
use App\Helpers\Auth;
$csrfToken = Auth::csrfToken();
$pageTitle = 'Wörter generieren — ' . APP_NAME;

// Block-Labels & Farben
$blockInfo = [
    'A' => ['icon' => '🔊', 'name' => 'Block A', 'desc' => 'Laut-Buchstaben-Zuordnung', 'color' => '#1565c0', 'bg' => '#e3f2fd'],
    'B' => ['icon' => '📖', 'name' => 'Block B', 'desc' => 'Regelwissen',               'color' => '#2e7d32', 'bg' => '#e8f5e9'],
    'C' => ['icon' => '🔄', 'name' => 'Block C', 'desc' => 'Ableitungswissen',          'color' => '#6a1b9a', 'bg' => '#f3e5f5'],
    'D' => ['icon' => '🔤', 'name' => 'Block D', 'desc' => 'Groß-/Kleinschreibung',     'color' => '#e65100', 'bg' => '#fff3e0'],
];

// Kategorien nach Block gruppieren
$byBlock = [];
foreach ($categoryStatus as $code => $info) {
    $byBlock[$info['block']][$code] = $info;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    .gen-wrap  { max-width: 900px; margin: 1.5rem auto; padding: 0 1rem; }

    /* ── Toolbar ── */
    .gen-toolbar {
      display: flex; align-items: center; gap: .75rem;
      flex-wrap: wrap; margin-bottom: 1.5rem;
    }
    .gen-toolbar h2 { margin: 0; font-size: 1.15rem; flex: 1; }

    /* ── Kind-Auswahl ── */
    .child-sel {
      background: #f0f7ff; border: 1px solid #bdd7ee;
      border-radius: 8px; padding: .6rem 1rem;
      margin-bottom: 1.25rem; font-size: .9rem;
      display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
    }
    .child-sel a {
      padding: .2rem .65rem; border-radius: 6px;
      border: 1px solid #bdd7ee; background: #fff;
      color: #1a56db; text-decoration: none; font-size: .83rem;
    }
    .child-sel a.active {
      background: #1a56db; color: #fff; border-color: #1a56db;
    }

    /* ── Block-Karte ── */
    .block-card {
      border-radius: 12px; overflow: hidden; margin-bottom: 1.5rem;
      box-shadow: 0 2px 8px rgba(0,0,0,.08);
    }
    .block-head {
      display: flex; align-items: center; gap: .75rem;
      padding: .75rem 1.25rem; color: #fff;
    }
    .block-head h3 { margin: 0; font-size: 1rem; flex: 1; }
    .block-head .block-desc { font-size: .82rem; opacity: .88; }
    .block-head .btn-gen-block {
      background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.4);
      color: #fff; border-radius: 6px; padding: .3rem .8rem;
      cursor: pointer; font-size: .83rem; white-space: nowrap;
      transition: background .15s;
    }
    .block-head .btn-gen-block:hover { background: rgba(255,255,255,.35); }

    /* ── Kategorie-Zeilen ── */
    .block-body { background: #fff; padding: .75rem 1.25rem; }
    .cat-row {
      display: flex; align-items: flex-start; gap: .75rem;
      padding: .6rem 0; border-bottom: 1px solid #f0f0f0;
    }
    .cat-row:last-child { border-bottom: none; }
    .cat-code {
      font-weight: 700; font-size: .85rem;
      min-width: 2.5rem; padding-top: .1rem;
    }
    .cat-info { flex: 1; min-width: 0; }
    .cat-label { font-weight: 600; font-size: .9rem; margin-bottom: .2rem; }
    .cat-curriculum {
      font-size: .78rem; color: #666; line-height: 1.4; margin-bottom: .3rem;
    }
    .cat-examples {
      font-size: .76rem; color: #888;
    }
    .cat-examples span {
      display: inline-block; background: #f5f5f5; border-radius: 4px;
      padding: .05rem .35rem; margin: .1rem .1rem 0 0;
    }
    .cat-actions {
      display: flex; align-items: center; gap: .5rem; flex-shrink: 0;
      padding-top: .1rem;
    }
    .word-count {
      font-size: .8rem; font-weight: 700; padding: .15rem .5rem;
      border-radius: 12px; white-space: nowrap;
    }
    .word-count.ok      { background: #dcfce7; color: #15803d; }
    .word-count.warn    { background: #fef3c7; color: #92400e; }
    .word-count.danger  { background: #fee2e2; color: #dc2626; }
    .btn-gen-cat {
      font-size: .78rem; padding: .25rem .65rem;
      border-radius: 6px; border: 1px solid #bdd7ee;
      background: #f0f7ff; color: #1a56db; cursor: pointer;
      white-space: nowrap; transition: background .15s;
    }
    .btn-gen-cat:hover  { background: #dbeafe; }
    .btn-gen-cat:disabled { opacity: .5; cursor: not-allowed; }

    .cat-status {
      font-size: .75rem; color: #999;
      min-width: 80px; text-align: right; white-space: nowrap;
    }
    .cat-status.running { color: #1565c0; }
    .cat-status.done    { color: #15803d; }
    .cat-status.error   { color: #dc2626; }
    .cat-status.skipped { color: #888; }

    /* ── Gesamtfortschritt ── */
    .gen-progress {
      background: #fff; border: 1px solid var(--color-border);
      border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;
      display: none;
    }
    .gen-progress.show { display: block; }
    .prog-bar-wrap { height: 12px; background: #e0e0e0; border-radius: 6px; overflow: hidden; margin: .5rem 0; }
    .prog-bar-fill { height: 100%; background: linear-gradient(90deg, #1565c0, #42a5f5); border-radius: 6px; transition: width .35s ease; width: 0%; }
    .prog-label    { font-size: .82rem; color: #666; }

    /* ── Force-Checkbox ── */
    .force-row {
      background: #fff8e1; border: 1px solid #ffd54f;
      border-radius: 8px; padding: .6rem 1rem;
      margin-bottom: 1.25rem; font-size: .85rem;
      display: flex; align-items: center; gap: .6rem;
    }
    .force-row label { margin: 0; cursor: pointer; }

    /* ── Quick-Actions ── */
    .quick-actions {
      display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1.5rem;
    }

    /* ── Block-Übersicht Details ── */
    .block-overview-toggle {
      background: none; border: none; cursor: pointer;
      font-size: .88rem; color: #1a56db; text-decoration: underline;
      margin-bottom: 1rem; display: block;
    }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($_SESSION['theme'] ?? 'minecraft') ?>">

<nav class="navbar">
  <span class="navbar-brand"><?= themeIcon() ?> <?= htmlspecialchars(APP_NAME) ?></span>
  <span class="navbar-user">👤 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
  <a href="<?= url('/admin/words') ?>" class="btn btn-sm btn-secondary" style="margin-right:.35rem">← Wörter</a>
  <a href="<?= url('/admin/dashboard') ?>" class="btn btn-sm btn-secondary" style="margin-right:.35rem">Dashboard</a>
  <a href="<?= url('/logout') ?>" class="btn btn-sm">Abmelden</a>
</nav>

<div class="gen-wrap">
  <div class="gen-toolbar">
    <h2>🔄 Wörter generieren / nachgenerieren</h2>
  </div>

  <?php if (empty($children)): ?>
    <div class="alert alert-error">Keine Kinder gefunden.</div>
  <?php else: ?>

  <!-- Kind-Auswahl -->
  <div class="child-sel">
    <strong>Kind:</strong>
    <?php foreach ($children as $ch): ?>
      <a href="<?= url('/admin/words/generate?child_id=' . (int)$ch['id']) ?>"
         class="<?= (int)$ch['id'] === ($childInfo['id'] ?? 0) ? 'active' : '' ?>">
        🧒 <?= htmlspecialchars($ch['display_name']) ?> (Kl. <?= (int)$ch['grade_level'] ?>)
      </a>
    <?php endforeach ?>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1rem">
      ⚠️ <?= htmlspecialchars($error) ?>
    </div>
  <?php endif ?>

  <?php if ($childInfo && !empty($categoryStatus)): ?>

  <!-- Lehrplan-Info -->
  <?php if (!empty($curriculumMeta)): ?>
  <div style="font-size:.82rem;color:#888;margin-bottom:1rem">
    📋 Lehrplan: <strong><?= htmlspecialchars($curriculumMeta['source'] ?? '') ?></strong>
    · <?= htmlspecialchars($curriculumMeta['federal_state'] ?? '') ?>
    · <?= htmlspecialchars($curriculumMeta['school_type'] ?? '') ?>
    · Klasse <?= htmlspecialchars($curriculumMeta['grades'] ?? '') ?>
  </div>
  <?php endif ?>

  <!-- Force-Option -->
  <div class="force-row">
    <input type="checkbox" id="force-check" onchange="updateForce()">
    <label for="force-check">
      <strong>Neu generieren erzwingen</strong> — ignoriert den Mindestschwellwert (<?= (int)($categoryStatus[array_key_first($categoryStatus)]['min_words'] ?? 15) ?> Wörter).
      Sinnvoll wenn ein Block bei der KI fehlgeschlagen ist oder du mehr Wörter möchtest.
    </label>
  </div>

  <!-- Gesamtfortschritt -->
  <div class="gen-progress" id="gen-progress">
    <strong id="prog-title">Wird generiert…</strong>
    <div class="prog-bar-wrap"><div class="prog-bar-fill" id="prog-fill"></div></div>
    <div class="prog-label" id="prog-label"></div>
  </div>

  <!-- Schnell-Aktionen -->
  <div class="quick-actions">
    <?php foreach (array_keys($blockInfo) as $bl): if (!isset($byBlock[$bl])) continue; ?>
      <button class="btn btn-sm btn-secondary"
              onclick="generateBlock('<?= $bl ?>', this)">
        Alle Block <?= $bl ?> neu
      </button>
    <?php endforeach ?>
    <button class="btn btn-sm btn-primary"
            onclick="generateAll(this)"
            style="margin-left:auto">
      🔄 Alle Blöcke neu generieren
    </button>
  </div>

  <!-- Blöcke -->
  <?php foreach ($blockInfo as $block => $binfo): if (!isset($byBlock[$block])) continue; ?>
  <div class="block-card" id="block-card-<?= $block ?>">
    <div class="block-head" style="background:<?= $binfo['color'] ?>">
      <span style="font-size:1.4rem"><?= $binfo['icon'] ?></span>
      <div style="flex:1">
        <h3><?= $binfo['name'] ?> — <?= htmlspecialchars($binfo['desc']) ?></h3>
        <?php
          $blockWordCount = 0;
          foreach ($byBlock[$block] as $cInfo) $blockWordCount += $cInfo['word_count'];
        ?>
        <div class="block-desc"><?= $blockWordCount ?> Wörter gesamt</div>
      </div>
      <button class="btn-gen-block"
              onclick="generateBlock('<?= $block ?>', this)">
        Block <?= $block ?> komplett neu
      </button>
    </div>

    <div class="block-body">
      <?php foreach ($byBlock[$block] as $code => $info):
        $wc   = (int)$info['word_count'];
        $min  = (int)$info['min_words'];
        $wcClass = $wc >= $min ? 'ok' : ($wc >= ($min * 0.5) ? 'warn' : 'danger');
      ?>
      <div class="cat-row" id="cat-row-<?= $code ?>">
        <div class="cat-code" style="color:<?= $blockInfo[$block]['color'] ?>">
          <?= htmlspecialchars($code) ?>
        </div>
        <div class="cat-info">
          <div class="cat-label"><?= htmlspecialchars($info['label']) ?></div>
          <?php if ($info['curriculum_text']): ?>
            <div class="cat-curriculum">
              <?= htmlspecialchars(mb_substr($info['curriculum_text'], 0, 160)) ?>
              <?= mb_strlen($info['curriculum_text']) > 160 ? '…' : '' ?>
            </div>
          <?php endif ?>
          <?php if (!empty($info['examples_official'])): ?>
            <div class="cat-examples">
              Beispiele:
              <?php foreach (array_slice($info['examples_official'], 0, 6) as $ex): ?>
                <span><?= htmlspecialchars($ex) ?></span>
              <?php endforeach ?>
            </div>
          <?php endif ?>
        </div>
        <div class="cat-actions">
          <span class="word-count <?= $wcClass ?>" id="wc-<?= $code ?>">
            <?= $wc ?>/<?= $min ?>
          </span>
          <button class="btn-gen-cat" id="btn-<?= $code ?>"
                  onclick="generateCategory('<?= $code ?>', this)">
            Neu generieren
          </button>
          <span class="cat-status" id="status-<?= $code ?>"></span>
        </div>
      </div>
      <?php endforeach ?>
    </div>
  </div>
  <?php endforeach ?>

  <?php elseif ($childInfo): ?>
    <div class="alert alert-error">
      Kein Lehrplan für dieses Kind gefunden. Bitte zuerst den
      <a href="<?= url('/setup/wizard') ?>">Setup-Wizard</a> abschließen.
    </div>
  <?php endif ?>
  <?php endif ?>
</div><!-- .gen-wrap -->

<script>
const CSRF     = <?= json_encode($csrfToken) ?>;
const CHILD_ID = <?= (int)($childInfo['id'] ?? 0) ?>;
const BATCH_URL = <?= json_encode(url('/admin/words/generate-batch')) ?>;

let forceMode   = false;
let running     = false;
let totalJobs   = 0;
let doneJobs    = 0;

function updateForce() {
  forceMode = document.getElementById('force-check').checked;
}

// Einzelne Kategorie generieren
async function generateCategory(code, btn) {
  if (running) return;
  running = true;
  btn.disabled = true;

  setStatus(code, 'running', '⏳ Generiere…');
  try {
    const res = await callBatch(code);
    updateCatUI(code, res);
  } catch(e) {
    setStatus(code, 'error', '❌ ' + (e.message || 'Fehler'));
  }
  running = false;
  btn.disabled = false;
}

// Ganzen Block generieren
async function generateBlock(block, btn) {
  if (running) return;
  running = true;
  const cats = getCatsForBlock(block);
  showProgress('Block ' + block + ' wird generiert…', cats.length);

  for (const code of cats) {
    const catBtn = document.getElementById('btn-' + code);
    if (catBtn) catBtn.disabled = true;
    setStatus(code, 'running', '⏳ Generiere…');
    try {
      const res = await callBatch(code);
      updateCatUI(code, res);
    } catch(e) {
      setStatus(code, 'error', '❌ Fehler');
    }
    doneJobs++;
    updateProgress(doneJobs, cats.length);
    if (catBtn) catBtn.disabled = false;
  }

  finishProgress('Block ' + block + ' fertig');
  running = false;
}

// Alle Blöcke
async function generateAll(btn) {
  if (running) return;
  btn.disabled = true;
  running = true;
  const cats = Array.from(document.querySelectorAll('[id^="btn-"]'))
                    .map(b => b.id.replace('btn-', ''))
                    .filter(c => /^[A-D]\d/.test(c));

  showProgress('Alle Blöcke werden generiert…', cats.length);
  for (const code of cats) {
    const catBtn = document.getElementById('btn-' + code);
    if (catBtn) catBtn.disabled = true;
    setStatus(code, 'running', '⏳ Generiere…');
    try {
      const res = await callBatch(code);
      updateCatUI(code, res);
    } catch(e) {
      setStatus(code, 'error', '❌ Fehler');
    }
    doneJobs++;
    updateProgress(doneJobs, cats.length);
    if (catBtn) catBtn.disabled = false;
  }

  finishProgress('Alle Blöcke fertig!');
  running = false;
  btn.disabled = false;
}

function getCatsForBlock(block) {
  return Array.from(document.querySelectorAll('[id^="cat-row-"]'))
              .map(el => el.id.replace('cat-row-', ''))
              .filter(c => c.startsWith(block));
}

async function callBatch(code) {
  const r = await fetch(BATCH_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ csrf_token: CSRF, child_id: CHILD_ID, category: code, force: forceMode }),
  });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

function updateCatUI(code, res) {
  if (res.error) {
    setStatus(code, 'error', '❌ ' + res.error);
    return;
  }
  if (res.skipped) {
    setStatus(code, 'skipped', '✓ übersprungen');
    return;
  }
  setStatus(code, 'done', '✅ +' + (res.new_words || 0) + ' Wörter');

  // Wortanzahl im Badge aktualisieren
  const wcEl = document.getElementById('wc-' + code);
  if (wcEl) {
    const parts   = wcEl.textContent.trim().split('/');
    const min     = parseInt(parts[1] || '15');
    const newCount = (parseInt(parts[0] || '0')) + (res.new_words || 0);
    wcEl.textContent = newCount + '/' + min;
    wcEl.className = 'word-count ' + (newCount >= min ? 'ok' : newCount >= min * 0.5 ? 'warn' : 'danger');
  }
}

function setStatus(code, cls, text) {
  const el = document.getElementById('status-' + code);
  if (el) { el.className = 'cat-status ' + cls; el.textContent = text; }
}

// Fortschrittsbalken
function showProgress(title, total) {
  totalJobs = total; doneJobs = 0;
  const p = document.getElementById('gen-progress');
  p.classList.add('show');
  document.getElementById('prog-title').textContent = title;
  document.getElementById('prog-fill').style.width  = '0%';
  document.getElementById('prog-label').textContent = '0 / ' + total;
}
function updateProgress(done, total) {
  const pct = total > 0 ? Math.round(done / total * 100) : 0;
  document.getElementById('prog-fill').style.width   = pct + '%';
  document.getElementById('prog-label').textContent  = done + ' / ' + total + ' Kategorien';
}
function finishProgress(msg) {
  document.getElementById('prog-title').textContent = '✅ ' + msg;
  document.getElementById('prog-fill').style.width  = '100%';
}
</script>
</body>
</html>
