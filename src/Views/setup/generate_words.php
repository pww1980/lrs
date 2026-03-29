<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wörter generieren — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    body { background: linear-gradient(145deg, #1b5e20 0%, #2e7d32 45%, #1565c0 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .gen-card { background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(0,0,0,.3); padding: 2.5rem 2rem; max-width: 480px; width: 100%; }
    .gen-icon { font-size: 3rem; text-align: center; display: block; margin-bottom: .5rem; }
    h1 { text-align: center; font-size: 1.3rem; margin-bottom: .25rem; }
    .gen-sub { text-align: center; color: #666; font-size: .9rem; margin-bottom: 1.75rem; }
    .progress-wrap { background: #eee; border-radius: 8px; height: 14px; overflow: hidden; margin-bottom: .5rem; }
    .progress-fill { height: 100%; background: linear-gradient(90deg, #2e7d32, #66bb6a); border-radius: 8px; transition: width .4s ease; width: 0%; }
    .progress-label { text-align: right; font-size: .8rem; color: #666; margin-bottom: 1.25rem; }
    .category-list { display: flex; flex-direction: column; gap: .35rem; max-height: 260px; overflow-y: auto; padding-right: .25rem; }
    .cat-row { display: flex; align-items: center; gap: .6rem; font-size: .85rem; padding: .3rem .5rem; border-radius: 6px; background: #f5f5f5; }
    .cat-badge { font-size: .72rem; font-weight: 700; background: #e8f5e9; color: #2e7d32; border-radius: 4px; padding: .1rem .4rem; min-width: 2rem; text-align: center; }
    .cat-status { margin-left: auto; font-size: .8rem; }
    .status-pending  { color: #bbb; }
    .status-running  { color: #1565c0; }
    .status-done     { color: #2e7d32; }
    .status-skipped  { color: #888; }
    .status-error    { color: #c62828; }
    .gen-actions { margin-top: 1.75rem; display: flex; flex-direction: column; gap: .6rem; }
    .gen-note { font-size: .78rem; color: #999; text-align: center; margin-top: .5rem; }
  </style>
</head>
<body>
<div class="gen-card">
  <span class="gen-icon">📚</span>
  <h1>Übungswörter werden generiert</h1>
  <p class="gen-sub">
    Für <strong><?= htmlspecialchars($childName) ?></strong> werden Wörter aus dem Lehrplan
    per KI erstellt — einmalig, dauert ~1–2 Minuten.
  </p>

  <div class="progress-wrap">
    <div class="progress-fill" id="progress-fill"></div>
  </div>
  <div class="progress-label" id="progress-label">0 / 0 Kategorien</div>

  <div class="category-list" id="cat-list">
    <div style="color:#999;font-size:.85rem;text-align:center">Wird geprüft…</div>
  </div>

  <div class="gen-actions" id="gen-actions" style="display:none">
    <a href="<?= url('/admin/dashboard') ?>" class="btn btn-primary" id="btn-done">Zum Dashboard →</a>
    <p class="gen-note" id="gen-note"></p>
  </div>
</div>

<script>
const CSRF      = <?= json_encode(\App\Helpers\Auth::csrfToken()) ?>;
const CHILD_ID  = <?= (int)$childId ?>;
const START_TEST = <?= $startTest ? 'true' : 'false' ?>;

let categories  = [];
let current     = 0;
let totalNew    = 0;
let totalErrors = 0;

const fill  = document.getElementById('progress-fill');
const label = document.getElementById('progress-label');
const list  = document.getElementById('cat-list');
const actions = document.getElementById('gen-actions');
const note    = document.getElementById('gen-note');

// Schritt 1: Kategorie-Liste vom Server holen
fetch('<?= url('/setup/generate-words/list') ?>', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({csrf_token: CSRF, child_id: CHILD_ID})
}).then(r => r.json()).then(d => {
  if (d.error || !d.categories || d.categories.length === 0) {
    list.innerHTML = '<div style="color:#c62828">⚠️ ' + (d.error || 'Kein Lehrplan gefunden — Wörter wurden bereits per Fallback geladen.') + '</div>';
    finish();
    return;
  }
  categories = d.categories;
  renderList();
  processNext();
}).catch(() => {
  list.innerHTML = '<div style="color:#c62828">⚠️ Verbindungsfehler</div>';
  finish();
});

function renderList() {
  list.innerHTML = '';
  categories.forEach(cat => {
    const row = document.createElement('div');
    row.className = 'cat-row';
    row.id = 'row-' + cat;
    row.innerHTML = `<span class="cat-badge">${cat}</span><span class="cat-label" id="label-${cat}">${cat}</span><span class="cat-status status-pending" id="status-${cat}">⏳ Ausstehend</span>`;
    list.appendChild(row);
  });
  updateProgress(0);
}

function updateProgress(done) {
  const pct = categories.length > 0 ? Math.round(done / categories.length * 100) : 100;
  fill.style.width = pct + '%';
  label.textContent = done + ' / ' + categories.length + ' Kategorien';
}

function processNext() {
  if (current >= categories.length) { finish(); return; }
  const cat = categories[current];
  setStatus(cat, 'running', '⏳ Wird generiert…');

  // Scroll to current row
  const row = document.getElementById('row-' + cat);
  if (row) row.scrollIntoView({block: 'nearest'});

  fetch('<?= url('/setup/generate-words/batch') ?>', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({csrf_token: CSRF, category: cat})
  }).then(r => r.json()).then(d => {
    if (d.skipped) {
      setStatus(cat, 'skipped', '✓ Vorhanden (' + (d.new_words ?? 0) + ' neu)');
    } else if (d.ok && !d.error) {
      totalNew += d.new_words ?? 0;
      setStatus(cat, 'done', '✅ +' + (d.new_words ?? 0) + ' Wörter');
    } else {
      totalErrors++;
      setStatus(cat, 'error', '⚠️ ' + (d.error ?? 'Fehler'));
    }
    current++;
    updateProgress(current);
    processNext();
  }).catch(() => {
    totalErrors++;
    setStatus(cat, 'error', '⚠️ Verbindungsfehler');
    current++;
    updateProgress(current);
    processNext();
  });
}

function setStatus(cat, cls, text) {
  const el = document.getElementById('status-' + cat);
  if (el) { el.className = 'cat-status status-' + cls; el.textContent = text; }
}

function finish() {
  updateProgress(categories.length || 1);
  actions.style.display = 'flex';
  if (totalErrors > 0) {
    note.textContent = totalErrors + ' Kategorien konnten nicht generiert werden (KI-Key prüfen). Fallback-Wörter werden verwendet.';
  } else {
    note.textContent = totalNew + ' neue Wörter generiert und in der Datenbank gespeichert.';
  }
  if (START_TEST) {
    document.getElementById('btn-done').href = '<?= url('/admin/dashboard') ?>';
    document.getElementById('btn-done').textContent = 'Zum Dashboard → Test starten';
  }
  // Session-Daten bereinigen
  fetch('<?= url('/setup/generate-words/done') ?>', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:CSRF})});
}
</script>
</body>
</html>
