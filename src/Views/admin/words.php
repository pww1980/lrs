<?php $title = 'Wörter verwalten'; ?>
<?php ob_start(); ?>

<style>
.words-filter { display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:1.25rem }
.words-filter select, .words-filter input[type=text] {
  padding:.35rem .6rem;border:1px solid var(--color-border);border-radius:6px;
  font-size:.88rem;background:#fff }
.words-filter input[type=text] { min-width:160px }
.word-table { width:100%;border-collapse:collapse;font-size:.88rem }
.word-table th { text-align:left;padding:.5rem .75rem;border-bottom:2px solid var(--color-border);
                 color:var(--color-muted);font-weight:600;white-space:nowrap }
.word-table td { padding:.45rem .75rem;border-bottom:1px solid #f0f0f0;vertical-align:middle }
.word-table tr:hover td { background:#fafafa }
.badge-cat { display:inline-block;padding:.15rem .45rem;border-radius:4px;font-size:.75rem;
             font-weight:700;background:#e8f0fe;color:#1a56db }
.badge-src { display:inline-block;padding:.1rem .4rem;border-radius:4px;font-size:.72rem;
             background:#f3f4f6;color:#6b7280 }
.badge-src.manual { background:#fef3c7;color:#92400e }
.badge-src.ai_generated { background:#f0fdf4;color:#15803d }
.diff-dot { display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:2px }
.toggle-btn { padding:.2rem .55rem;font-size:.78rem;border-radius:4px;cursor:pointer;
              border:1px solid transparent }
.toggle-btn.active { background:#dcfce7;border-color:#86efac;color:#15803d }
.toggle-btn.inactive { background:#fee2e2;border-color:#fca5a5;color:#dc2626 }
.del-btn { padding:.2rem .5rem;font-size:.78rem;border-radius:4px;cursor:pointer;
           border:1px solid #fca5a5;background:#fff;color:#dc2626 }
.stat-chip { display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .65rem;
             border-radius:20px;font-size:.8rem;background:#f3f4f6;margin:.15rem }
.add-form { display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;
            background:#f8fafc;border:1px solid var(--color-border);
            border-radius:8px;padding:.85rem 1rem;margin-bottom:1.25rem }
.add-form label { font-size:.8rem;color:var(--color-muted);display:block;margin-bottom:.2rem }
.add-form input, .add-form select {
  padding:.35rem .6rem;border:1px solid var(--color-border);border-radius:6px;font-size:.88rem }
</style>

<div class="dash-section">
  <div class="dash-section-title">📝 Wörter verwalten</div>

  <!-- Statistik -->
  <div style="margin-bottom:1rem">
    <span class="stat-chip">✅ <?= $totalActive ?> aktive Wörter</span>
    <span class="stat-chip">🚫 <?= $totalInactive ?> deaktiviert</span>
    <?php foreach (['A','B','C','D'] as $bl): ?>
      <?php
        $cnt = 0;
        foreach ($catCounts as $cat => $n) {
          if (str_starts_with($cat, $bl)) $cnt += $n;
        }
      ?>
      <span class="stat-chip">Block <?= $bl ?>: <?= $cnt ?></span>
    <?php endforeach ?>
  </div>

  <!-- Wort hinzufügen -->
  <details style="margin-bottom:1rem">
    <summary style="cursor:pointer;font-weight:600;font-size:.9rem;padding:.4rem 0">
      ➕ Wort manuell hinzufügen
    </summary>
    <div class="add-form" style="margin-top:.75rem" id="add-word-form">
      <div>
        <label>Wort</label>
        <input type="text" id="add-word" placeholder="z.B. Fahrrad" style="min-width:140px">
      </div>
      <div>
        <label>Primärkategorie</label>
        <select id="add-cat">
          <?php foreach (['A1','A2','A3','B1','B2','B3','B4','B5','C1','C2','C3','D1','D2','D3','D4'] as $c): ?>
            <option value="<?= $c ?>"><?= $c ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div>
        <label>Klasse</label>
        <select id="add-grade">
          <?php for ($g=1;$g<=8;$g++): ?>
            <option value="<?= $g ?>" <?= $g===4?'selected':'' ?>><?= $g ?>. Klasse</option>
          <?php endfor ?>
        </select>
      </div>
      <div>
        <label>Schwierigkeit</label>
        <select id="add-diff">
          <option value="1">1 — leicht</option>
          <option value="2">2 — mittel</option>
          <option value="3">3 — schwer</option>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" onclick="addWord()">Hinzufügen</button>
      <span id="add-msg" style="font-size:.85rem;color:var(--color-muted)"></span>
    </div>
  </details>

  <!-- Filter -->
  <form method="get" action="<?= url('/admin/words') ?>" class="words-filter">
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Suche…">
    <select name="cat">
      <option value="">Alle Kategorien</option>
      <?php foreach (['A1','A2','A3','B1','B2','B3','B4','B5','C1','C2','C3','D1','D2','D3','D4'] as $c): ?>
        <option value="<?= $c ?>" <?= $filterCat===$c?'selected':'' ?>><?= $c ?></option>
      <?php endforeach ?>
    </select>
    <select name="grade">
      <option value="0">Alle Klassen</option>
      <?php for ($g=1;$g<=8;$g++): ?>
        <option value="<?= $g ?>" <?= $filterGrade===$g?'selected':'' ?>><?= $g ?>. Klasse</option>
      <?php endfor ?>
    </select>
    <select name="source">
      <option value="">Alle Quellen</option>
      <option value="kmk" <?= $filterSource==='kmk'?'selected':'' ?>>KMK</option>
      <option value="ai_generated" <?= $filterSource==='ai_generated'?'selected':'' ?>>KI-generiert</option>
      <option value="manual" <?= $filterSource==='manual'?'selected':'' ?>>Manuell</option>
    </select>
    <select name="active">
      <option value="all"      <?= $filterActive==='all'?'selected':'' ?>>Aktiv + Inaktiv</option>
      <option value="active"   <?= $filterActive==='active'?'selected':'' ?>>Nur aktive</option>
      <option value="inactive" <?= $filterActive==='inactive'?'selected':'' ?>>Nur deaktivierte</option>
    </select>
    <button class="btn btn-sm" type="submit" style="background:#f3f4f6;border:1px solid var(--color-border)">
      Filtern
    </button>
    <?php if ($filterCat || $filterGrade || $filterSource || $filterActive !== 'all' || $search): ?>
      <a href="<?= url('/admin/words') ?>" style="font-size:.83rem;color:var(--color-muted)">✕ zurücksetzen</a>
    <?php endif ?>
  </form>

  <!-- Tabelle -->
  <div style="overflow-x:auto">
    <table class="word-table">
      <thead>
        <tr>
          <th>Wort</th>
          <th>Kategorie</th>
          <th>Klasse</th>
          <th>Schwierigkeit</th>
          <th>Quelle</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="word-tbody">
      <?php foreach ($words as $w): ?>
        <tr id="word-row-<?= $w['id'] ?>">
          <td><strong><?= htmlspecialchars($w['word']) ?></strong></td>
          <td><span class="badge-cat"><?= htmlspecialchars($w['primary_category']) ?></span></td>
          <td><?= (int)$w['grade_level'] ?>. Kl.</td>
          <td>
            <?php for ($d=1;$d<=3;$d++): ?>
              <span class="diff-dot" style="background:<?= $d<=(int)$w['difficulty']?'#f59e0b':'#e5e7eb' ?>"></span>
            <?php endfor ?>
          </td>
          <td>
            <span class="badge-src <?= htmlspecialchars($w['source']) ?>">
              <?= match($w['source']) { 'kmk'=>'KMK', 'ai_generated'=>'KI', 'manual'=>'Manuell', default=>$w['source'] } ?>
            </span>
          </td>
          <td>
            <button class="toggle-btn <?= $w['active']?'active':'inactive' ?>"
                    onclick="toggleWord(<?= $w['id'] ?>, this)"
                    data-active="<?= $w['active'] ?>">
              <?= $w['active'] ? '✅ Aktiv' : '🚫 Deaktiviert' ?>
            </button>
          </td>
          <td>
            <?php if ($w['source'] === 'manual'): ?>
              <button class="del-btn" onclick="deleteWord(<?= $w['id'] ?>, '<?= htmlspecialchars(addslashes($w['word'])) ?>')">🗑</button>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
      <?php if (empty($words)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--color-muted);padding:2rem">
          Keine Wörter gefunden.
        </td></tr>
      <?php endif ?>
      </tbody>
    </table>
  </div>
  <?php if (count($words) === 500): ?>
    <p style="font-size:.82rem;color:var(--color-muted);margin-top:.5rem">
      ⚠ Maximal 500 Einträge angezeigt — Filter verwenden um einzugrenzen.
    </p>
  <?php endif ?>
</div>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;

function toggleWord(id, btn) {
  fetch('<?= url('/admin/words/toggle') ?>', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'csrf_token=' + encodeURIComponent(CSRF) + '&word_id=' + id,
  })
  .then(r => r.json())
  .then(() => {
    const isNowActive = btn.dataset.active === '1' ? false : true;
    btn.dataset.active = isNowActive ? '1' : '0';
    btn.className = 'toggle-btn ' + (isNowActive ? 'active' : 'inactive');
    btn.textContent = isNowActive ? '✅ Aktiv' : '🚫 Deaktiviert';
  });
}

function deleteWord(id, word) {
  if (!confirm('Wort „' + word + '" dauerhaft löschen?')) return;
  fetch('<?= url('/admin/words/delete') ?>', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'csrf_token=' + encodeURIComponent(CSRF) + '&word_id=' + id,
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      const row = document.getElementById('word-row-' + id);
      if (row) row.remove();
    } else {
      alert(data.error || 'Fehler beim Löschen.');
    }
  });
}

function addWord() {
  const word  = document.getElementById('add-word').value.trim();
  const cat   = document.getElementById('add-cat').value;
  const grade = document.getElementById('add-grade').value;
  const diff  = document.getElementById('add-diff').value;
  const msg   = document.getElementById('add-msg');
  if (!word) { msg.textContent = 'Bitte Wort eingeben.'; return; }

  fetch('<?= url('/admin/words/add') ?>', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'csrf_token=' + encodeURIComponent(CSRF)
        + '&word=' + encodeURIComponent(word)
        + '&category=' + encodeURIComponent(cat)
        + '&grade=' + encodeURIComponent(grade)
        + '&difficulty=' + encodeURIComponent(diff),
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      msg.style.color = '#15803d';
      msg.textContent = '✅ „' + data.word + '" hinzugefügt.';
      document.getElementById('add-word').value = '';
      // Zeile oben in Tabelle einfügen
      const tbody = document.getElementById('word-tbody');
      const tr = document.createElement('tr');
      tr.id = 'word-row-' + data.id;
      tr.innerHTML = '<td><strong>' + data.word + '</strong></td>'
        + '<td><span class="badge-cat">' + cat + '</span></td>'
        + '<td>' + grade + '. Kl.</td>'
        + '<td><span style="color:#f59e0b">●</span></td>'
        + '<td><span class="badge-src manual">Manuell</span></td>'
        + '<td><button class="toggle-btn active" onclick="toggleWord(' + data.id + ', this)" data-active="1">✅ Aktiv</button></td>'
        + '<td><button class="del-btn" onclick="deleteWord(' + data.id + ', \'' + data.word.replace(/'/g, "\\'") + '\')">🗑</button></td>';
      tbody.insertBefore(tr, tbody.firstChild);
    } else {
      msg.style.color = '#dc2626';
      msg.textContent = data.error || 'Fehler.';
    }
  });
}
</script>

<?php $content = ob_get_clean(); ?>
<?php require BASE_DIR . '/src/Views/layouts/base.php'; ?>
