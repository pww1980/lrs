<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wörter — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    .words-wrap    { max-width:960px;margin:1.5rem auto;padding:0 1rem }
    .words-filter  { display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:1rem }
    .words-filter select, .words-filter input[type=text] {
      padding:.35rem .6rem;border:1px solid var(--color-border);border-radius:6px;font-size:.88rem;background:#fff }
    .words-filter input[type=text] { min-width:150px }
    .child-bar     { background:#f0f7ff;border:1px solid #bdd7ee;border-radius:8px;
                     padding:.6rem 1rem;margin-bottom:1rem;font-size:.9rem;
                     display:flex;align-items:center;gap:.75rem }
    .word-table    { width:100%;border-collapse:collapse;font-size:.875rem }
    .word-table th { text-align:left;padding:.45rem .7rem;border-bottom:2px solid var(--color-border);
                     color:var(--color-muted);font-weight:600;white-space:nowrap }
    .word-table td { padding:.4rem .7rem;border-bottom:1px solid #f0f0f0;vertical-align:middle }
    .word-table tr:hover td { background:#fafafa }
    .badge-cat  { display:inline-block;padding:.12rem .4rem;border-radius:4px;font-size:.75rem;
                  font-weight:700;background:#e8f0fe;color:#1a56db }
    .badge-src  { display:inline-block;padding:.1rem .38rem;border-radius:4px;font-size:.72rem;background:#f3f4f6;color:#6b7280 }
    .badge-src.manual       { background:#fef3c7;color:#92400e }
    .badge-src.ai_generated { background:#f0fdf4;color:#15803d }
    .toggle-btn { padding:.18rem .5rem;font-size:.78rem;border-radius:4px;cursor:pointer;border:1px solid transparent }
    .toggle-btn.active   { background:#dcfce7;border-color:#86efac;color:#15803d }
    .toggle-btn.inactive { background:#fee2e2;border-color:#fca5a5;color:#dc2626 }
    .del-btn    { padding:.18rem .45rem;font-size:.78rem;border-radius:4px;cursor:pointer;
                  border:1px solid #fca5a5;background:#fff;color:#dc2626 }
    .stat-chip  { display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .6rem;
                  border-radius:20px;font-size:.8rem;background:#f3f4f6;margin:.12rem }
    .add-row    { background:#f8fafc;border:1px solid var(--color-border);border-radius:8px;
                  padding:.75rem 1rem;margin-bottom:1rem }
    .add-row summary { cursor:pointer;font-weight:600;font-size:.88rem }
    .add-fields { display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin-top:.65rem }
    .add-fields label { font-size:.78rem;color:var(--color-muted);display:block;margin-bottom:.15rem }
    .add-fields input, .add-fields select {
      padding:.32rem .55rem;border:1px solid var(--color-border);border-radius:5px;font-size:.88rem }
    .diff-dot { display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:1px }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($_SESSION['theme'] ?? 'minecraft') ?>">

<nav class="navbar">
  <span class="navbar-brand"><?= themeIcon() ?> <?= htmlspecialchars(APP_NAME) ?></span>
  <span class="navbar-user">👤 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
  <a href="<?= url('/admin/dashboard') ?>" class="btn btn-sm btn-secondary" style="margin-right:.35rem">← Dashboard</a>
  <a href="<?= url('/admin/words/generate' . ($childId ? '?child_id=' . $childId : '')) ?>"
     class="btn btn-sm btn-secondary" style="margin-right:.35rem">🔄 Generieren</a>
  <a href="<?= url('/admin/settings') ?>" class="btn btn-sm btn-secondary" style="margin-right:.35rem">⚙️ Einstellungen</a>
  <a href="<?= url('/logout') ?>" class="btn btn-sm">Abmelden</a>
</nav>

<div class="words-wrap">
  <h2 style="margin-bottom:1rem">📝 Wörter verwalten</h2>

  <!-- Kind-Kontext-Banner -->
  <?php if ($childInfo): ?>
  <div class="child-bar">
    🧒 <strong><?= htmlspecialchars($childInfo['display_name']) ?></strong>
    — Klasse <?= (int)$childInfo['grade_level'] ?>
    <span style="color:var(--color-muted);font-size:.82rem">
      (Wörter für diese Klasse vorausgewählt)
    </span>
    <a href="<?= url('/admin/words') ?>" style="margin-left:auto;font-size:.82rem">Alle Wörter anzeigen</a>
  </div>
  <?php elseif (!empty($children)): ?>
  <div style="margin-bottom:.75rem;font-size:.88rem">
    Kind-Ansicht:
    <?php foreach ($children as $ch): ?>
      <a href="<?= url('/admin/words?child_id=' . (int)$ch['id']) ?>"
         style="margin-left:.4rem;padding:.15rem .5rem;border-radius:4px;
                background:#f0f7ff;border:1px solid #bdd7ee;color:#1a56db;text-decoration:none;font-size:.82rem">
        🧒 <?= htmlspecialchars($ch['display_name']) ?>
      </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <!-- Statistik -->
  <div style="margin-bottom:.9rem">
    <span class="stat-chip">✅ <?= $totalActive ?> aktiv</span>
    <span class="stat-chip">🚫 <?= $totalInactive ?> deaktiviert</span>
    <?php foreach (['A','B','C','D'] as $bl):
      $cnt = 0;
      foreach ($catCounts as $cat => $n) { if (str_starts_with($cat,$bl)) $cnt += $n; }
    ?>
      <span class="stat-chip">Block <?= $bl ?>: <?= $cnt ?></span>
    <?php endforeach ?>
  </div>

  <!-- Wort hinzufügen -->
  <details class="add-row">
    <summary>➕ Wort manuell hinzufügen</summary>
    <div class="add-fields">
      <div>
        <label>Wort</label>
        <input type="text" id="add-word" placeholder="z.B. Fahrrad" style="min-width:130px">
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
            <option value="<?= $g ?>" <?= $g===$filterGrade||($filterGrade===0&&$g===4)?'selected':'' ?>>
              <?= $g ?>. Klasse
            </option>
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
      <span id="add-msg" style="font-size:.84rem;color:var(--color-muted)"></span>
    </div>
  </details>

  <!-- Filter -->
  <form method="get" action="/index.php" class="words-filter">
    <input type="hidden" name="_r" value="/admin/words">
    <?php if ($childId): ?>
      <input type="hidden" name="child_id" value="<?= $childId ?>">
    <?php endif ?>
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
      <option value="kmk"          <?= $filterSource==='kmk'?'selected':'' ?>>KMK</option>
      <option value="ai_generated" <?= $filterSource==='ai_generated'?'selected':'' ?>>KI-generiert</option>
      <option value="manual"       <?= $filterSource==='manual'?'selected':'' ?>>Manuell</option>
    </select>
    <select name="active">
      <option value="active"   <?= $filterActive==='active'?'selected':'' ?>>Nur aktive</option>
      <option value="all"      <?= $filterActive==='all'?'selected':'' ?>>Aktiv + Inaktiv</option>
      <option value="inactive" <?= $filterActive==='inactive'?'selected':'' ?>>Nur deaktivierte</option>
    </select>
    <button type="submit" class="btn btn-sm" style="background:#f3f4f6;border:1px solid var(--color-border)">
      Filtern
    </button>
    <?php if ($filterCat||$filterSource||$filterActive!=='active'||$search): ?>
      <a href="<?= url('/admin/words' . ($childId ? '?child_id='.$childId : '')) ?>"
         style="font-size:.82rem;color:var(--color-muted)">✕ zurücksetzen</a>
    <?php endif ?>
  </form>

  <!-- Ergebnis -->
  <div style="margin-bottom:.4rem;font-size:.82rem;color:var(--color-muted)">
    <?= count($words) ?> Wörter<?= count($words)===500?' (max. 500 — bitte filtern)':'' ?>
  </div>
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
              <?= match($w['source']) { 'kmk'=>'KMK','ai_generated'=>'KI','manual'=>'Manuell',default=>$w['source'] } ?>
            </span>
          </td>
          <td>
            <button class="toggle-btn <?= $w['active']?'active':'inactive' ?>"
                    onclick="toggleWord(<?= $w['id'] ?>, this)"
                    data-active="<?= (int)$w['active'] ?>">
              <?= $w['active'] ? '✅ Aktiv' : '🚫 Deaktiviert' ?>
            </button>
          </td>
          <td>
            <?php if ($w['source'] === 'manual'): ?>
              <button class="del-btn" onclick="deleteWord(<?= $w['id'] ?>,'<?= htmlspecialchars(addslashes($w['word'])) ?>')">🗑</button>
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
</div>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;

function toggleWord(id, btn) {
  fetch('<?= url('/admin/words/toggle') ?>', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'csrf_token='+encodeURIComponent(CSRF)+'&word_id='+id,
  }).then(r=>r.json()).then(()=>{
    var now = btn.dataset.active === '1' ? false : true;
    btn.dataset.active = now ? '1' : '0';
    btn.className = 'toggle-btn '+(now?'active':'inactive');
    btn.textContent = now ? '✅ Aktiv' : '🚫 Deaktiviert';
  });
}

function deleteWord(id, word) {
  if (!confirm('Wort „'+word+'" dauerhaft löschen?')) return;
  fetch('<?= url('/admin/words/delete') ?>', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'csrf_token='+encodeURIComponent(CSRF)+'&word_id='+id,
  }).then(r=>r.json()).then(data=>{
    if (data.success) { var r=document.getElementById('word-row-'+id); if(r) r.remove(); }
    else alert(data.error||'Fehler.');
  });
}

function addWord() {
  var word=document.getElementById('add-word').value.trim(),
      cat=document.getElementById('add-cat').value,
      grade=document.getElementById('add-grade').value,
      diff=document.getElementById('add-diff').value,
      msg=document.getElementById('add-msg');
  if (!word) { msg.textContent='Bitte Wort eingeben.'; return; }
  fetch('<?= url('/admin/words/add') ?>', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'csrf_token='+encodeURIComponent(CSRF)+'&word='+encodeURIComponent(word)
        +'&category='+encodeURIComponent(cat)+'&grade='+grade+'&difficulty='+diff,
  }).then(r=>r.json()).then(data=>{
    if (data.success) {
      msg.style.color='#15803d'; msg.textContent='✅ „'+data.word+'" hinzugefügt.';
      document.getElementById('add-word').value='';
      var tbody=document.getElementById('word-tbody'), tr=document.createElement('tr');
      tr.id='word-row-'+data.id;
      tr.innerHTML='<td><strong>'+data.word+'</strong></td>'
        +'<td><span class="badge-cat">'+cat+'</span></td>'
        +'<td>'+grade+'. Kl.</td>'
        +'<td><span class="diff-dot" style="background:#f59e0b"></span></td>'
        +'<td><span class="badge-src manual">Manuell</span></td>'
        +'<td><button class="toggle-btn active" onclick="toggleWord('+data.id+',this)" data-active="1">✅ Aktiv</button></td>'
        +'<td><button class="del-btn" onclick="deleteWord('+data.id+',\''+data.word.replace(/\'/g,"\\'")+'\')"">🗑</button></td>';
      tbody.insertBefore(tr, tbody.firstChild);
    } else { msg.style.color='#dc2626'; msg.textContent=data.error||'Fehler.'; }
  });
}
</script>

<script src="/public/js/app.js"></script>
</body>
</html>
