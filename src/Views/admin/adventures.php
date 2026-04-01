<?php
/**
 * Admin: Zusätzliche Abenteuer verwalten
 * Variablen: $child, $adventures, $children, $childId
 */
use App\Helpers\Auth;
$csrfToken = Auth::csrfToken();
$pageTitle = 'Abenteuer — ' . ($child['display_name'] ?? '') . ' — ' . APP_NAME;
$error      = $_GET['error'] ?? null;
$saved      = (int)($_GET['saved'] ?? 0);
$groupSaved = !empty($_GET['group_saved']);

$statusLabel = [
    'pending'   => ['label' => 'Ausstehend', 'color' => '#e65100', 'bg' => '#fff3e0'],
    'active'    => ['label' => 'Aktiv',      'color' => '#1565c0', 'bg' => '#e3f2fd'],
    'completed' => ['label' => 'Abgeschlossen', 'color' => '#2e7d32', 'bg' => '#e8f5e9'],
    'cancelled' => ['label' => 'Abgebrochen', 'color' => '#616161', 'bg' => '#f5f5f5'],
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    .adv-card { border:1px solid var(--color-border); border-radius:10px; margin-bottom:1rem;
                padding:1rem 1.25rem; background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.05); }
    .adv-meta  { font-size:.8rem; color:var(--color-muted); margin-top:.3rem; }
    .adv-words { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.6rem; }
    .adv-word  { background:#e8f5e9; color:#2e7d32; border-radius:4px; padding:.15rem .5rem;
                 font-size:.82rem; font-weight:600; }
    .adv-sentence { background:#f5f5f5; border-left:3px solid var(--color-primary);
                    padding:.4rem .75rem; margin:.25rem 0; font-size:.88rem; border-radius:0 4px 4px 0; }
    .status-badge { display:inline-block; padding:.15rem .55rem; border-radius:4px;
                    font-size:.75rem; font-weight:600; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    @media(max-width:600px){ .form-grid { grid-template-columns:1fr; } }
    .section-title { font-size:1.05rem; font-weight:700; color:var(--color-primary-dk);
                     border-bottom:2px solid var(--color-border); padding-bottom:.4rem; margin-bottom:1rem; }
    .sentence-list { margin:.5rem 0; }
    .sentence-edit { width:100%; margin:.25rem 0; padding:.4rem .6rem;
                     border:1px solid var(--color-border); border-radius:6px; font-size:.88rem; }
    .spinner { display:inline-block; width:1rem; height:1rem; border:2px solid #ccc;
               border-top-color:var(--color-primary); border-radius:50%;
               animation:spin .7s linear infinite; vertical-align:middle; margin-right:.4rem; }
    @keyframes spin { to { transform:rotate(360deg); } }
  </style>
</head>
<body>
<nav class="navbar">
  <div class="navbar-brand">📚 <?= APP_NAME ?></div>
  <div class="navbar-links">
    <a href="<?= url('/admin/dashboard') ?>">← Dashboard</a>
    <a href="<?= url('/admin/words?child_id=' . $childId) ?>">📝 Wörter</a>
    <span style="color:var(--color-muted);font-size:.85rem">👤 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
    <a href="<?= url('/logout') ?>">Abmelden</a>
  </div>
</nav>

<main class="container" style="max-width:860px;padding:1.5rem 1rem">

  <!-- Kind-Switcher -->
  <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap">
    <span style="font-size:.85rem;color:var(--color-muted)">Kind:</span>
    <?php foreach ($children as $ch): ?>
      <a href="<?= url('/admin/adventures?child_id=' . $ch['id']) ?>"
         class="btn btn-sm <?= $ch['id'] == $childId ? 'btn-primary' : 'btn-secondary' ?>">
        <?= htmlspecialchars($ch['display_name']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <h1 style="font-size:1.4rem;margin-bottom:1.5rem">
    🗺️ Zusätzliche Abenteuer — <?= htmlspecialchars($child['display_name']) ?>
  </h1>

  <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1rem">
      <?= htmlspecialchars($error === 'no_words' ? 'Bitte mindestens ein Wort eingeben.' : 'Fehler beim Speichern.') ?>
    </div>
  <?php endif; ?>

  <?php if ($saved): ?>
    <div class="alert alert-success" style="margin-bottom:1rem">
      ✅ Abenteuer gespeichert!
      Jetzt KI-Diktat generieren:
      <button class="btn btn-sm btn-primary" onclick="generateDiktat(<?= $saved ?>)"
              id="gen-btn-<?= $saved ?>">🤖 Diktat generieren</button>
    </div>
  <?php endif; ?>

  <!-- ── NEUES ABENTEUER ─────────────────────────────────────────────── -->
  <section style="margin-bottom:2rem">
    <div class="section-title">➕ Neues Abenteuer erstellen</div>
    <form method="post" action="<?= url('/admin/adventures/save') ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="child_id"   value="<?= $childId ?>">

      <div class="form-grid" style="margin-bottom:.75rem">
        <div class="form-group">
          <label class="form-label">Titel</label>
          <input type="text" name="title" class="form-input"
                 placeholder="z.B. Schulaufgabe KW 12" value="Schulaufgabe">
        </div>
        <div class="form-group">
          <label class="form-label">Einplanen für</label>
          <input type="date" name="scheduled_date" class="form-input"
                 value="<?= date('Y-m-d') ?>" required>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:.75rem">
        <label class="form-label">Schulaufgaben-Datum (optional — wann wurde das Diktat in der Schule geschrieben?)</label>
        <input type="date" name="school_date" class="form-input" style="max-width:200px">
      </div>

      <div class="form-group" style="margin-bottom:1rem">
        <label class="form-label">Lernwörter <small style="color:var(--color-muted)">(eine pro Zeile oder kommagetrennt)</small></label>
        <textarea name="words" class="form-input" rows="5"
                  placeholder="Schmetterling&#10;Erdbeere&#10;Baumhaus&#10;plötzlich"
                  style="font-family:monospace"></textarea>
      </div>

      <div class="form-group" style="margin-bottom:1rem">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.88rem">
          <input type="checkbox" name="repeatable" value="1">
          <span>🔁 <strong>Wiederholbar</strong> — Kind kann dieses Abenteuer beliebig oft spielen</span>
        </label>
      </div>

      <button type="submit" class="btn btn-primary">💾 Speichern &amp; weiter</button>
      <small style="color:var(--color-muted);margin-left:.75rem">
        Nach dem Speichern kannst du das KI-Diktat generieren lassen.
      </small>
    </form>
  </section>

  <!-- ── ABENTEUER-PAKET ─────────────────────────────────────────────── -->
  <?php $pendingAdvs = array_filter($adventures, fn($a) => $a['status'] === 'pending'); ?>
  <?php if (count($pendingAdvs) >= 2): ?>
  <section style="margin-bottom:2rem">
    <div class="section-title">📦 Abenteuer-Paket erstellen <small style="font-weight:400;color:var(--color-muted)">(mehrere Abenteuer zusammen planen)</small></div>

    <?php if ($groupSaved): ?>
      <div class="alert alert-success" style="margin-bottom:1rem">✅ Paket gespeichert!</div>
    <?php endif; ?>

    <form method="post" action="<?= url('/admin/adventure-groups/save') ?>">
      <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
      <input type="hidden" name="child_id"   value="<?= $childId ?>">

      <div class="form-grid" style="margin-bottom:.75rem">
        <div class="form-group">
          <label class="form-label">Paket-Titel</label>
          <input type="text" name="group_title" class="form-input" placeholder="z.B. Schulwoche KW 15">
        </div>
        <div class="form-group">
          <label class="form-label">Einplanen für</label>
          <input type="date" name="group_sched" class="form-input" value="<?= date('Y-m-d') ?>" required>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:.75rem">
        <label class="form-label">Enthaltene Abenteuer <small style="color:var(--color-muted)">(min. 2 auswählen)</small></label>
        <div style="border:1px solid var(--color-border);border-radius:6px;padding:.5rem .75rem;display:flex;flex-wrap:wrap;gap:.5rem">
          <?php foreach ($pendingAdvs as $pa): ?>
            <label style="display:flex;align-items:center;gap:.4rem;padding:.3rem .6rem;background:#f8fafc;border:1px solid var(--color-border);border-radius:5px;cursor:pointer;font-size:.85rem">
              <input type="checkbox" name="group_adventures[]" value="<?= $pa['id'] ?>">
              <?= htmlspecialchars($pa['title']) ?>
              <span style="color:var(--color-muted);font-size:.75rem">(<?= (int)$pa['word_count'] ?> Wörter)</span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:1rem">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.88rem">
          <input type="checkbox" name="group_repeatable" value="1">
          <span>🔁 <strong>Wiederholbar</strong></span>
        </label>
      </div>

      <button type="submit" class="btn btn-primary">📦 Paket erstellen</button>
    </form>
  </section>
  <?php endif; ?>

  <!-- ── BESTEHENDE PAKETE ───────────────────────────────────────────── -->
  <?php if (!empty($adventureGroups)): ?>
  <section style="margin-bottom:2rem">
    <div class="section-title">📦 Bestehende Pakete</div>
    <?php foreach ($adventureGroups as $grp):
      $gst = $statusLabel[$grp['status']] ?? $statusLabel['pending'];
    ?>
    <div class="adv-card">
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
        <div style="flex:1;min-width:0">
          <strong>📦 <?= htmlspecialchars($grp['title']) ?></strong>
          <span class="status-badge" style="background:<?= $gst['bg'] ?>;color:<?= $gst['color'] ?>;margin-left:.5rem">
            <?= $gst['label'] ?>
          </span>
          <?php if ($grp['repeatable']): ?>
            <span class="status-badge" style="background:#e8f5e9;color:#2e7d32;margin-left:.25rem">🔁 Wiederholbar</span>
          <?php endif; ?>
          <div class="adv-meta">
            📅 <?= date('d.m.Y', strtotime($grp['scheduled_date'])) ?>
            &nbsp;· <?= (int)$grp['adventure_count'] ?> Abenteuer
          </div>
        </div>
        <form method="post" action="<?= url('/admin/adventure-groups/delete') ?>" style="margin:0"
              onsubmit="return confirm('Paket löschen?')">
          <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
          <input type="hidden" name="group_id"   value="<?= $grp['id'] ?>">
          <input type="hidden" name="child_id"   value="<?= $childId ?>">
          <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif ?>

  <!-- ── BESTEHENDE ABENTEUER ───────────────────────────────────────── -->
  <section>
    <div class="section-title">📋 Bestehende Abenteuer</div>

    <?php if (empty($adventures)): ?>
      <p style="color:var(--color-muted)">Noch keine Abenteuer erstellt.</p>
    <?php else: ?>
      <?php foreach ($adventures as $adv):
        $st = $statusLabel[$adv['status']] ?? $statusLabel['pending'];
      ?>
      <div class="adv-card" id="adv-card-<?= $adv['id'] ?>">
        <div style="display:flex;align-items:flex-start;gap:.75rem;flex-wrap:wrap">
          <div style="flex:1;min-width:0">
            <strong><?= htmlspecialchars($adv['title']) ?></strong>
            <span class="status-badge" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;margin-left:.5rem">
              <?= $st['label'] ?>
            </span>
            <?php if (!empty($adv['repeatable'])): ?>
              <span class="status-badge" style="background:#e8f5e9;color:#2e7d32;margin-left:.25rem">🔁 Wiederholbar</span>
            <?php endif; ?>
            <div class="adv-meta">
              📅 Geplant: <strong><?= date('d.m.Y', strtotime($adv['scheduled_date'])) ?></strong>
              <?php if ($adv['school_date']): ?>
                &nbsp;· Schulaufgabe: <?= date('d.m.Y', strtotime($adv['school_date'])) ?>
              <?php endif; ?>
              &nbsp;· <?= (int)$adv['word_count'] ?> Wörter
              &nbsp;· <?= (int)$adv['sentence_count'] ?> Sätze
            </div>
          </div>
          <div style="display:flex;gap:.4rem;align-items:center;flex-shrink:0">
            <?php if ($adv['status'] === 'pending'): ?>
              <button class="btn btn-sm btn-primary"
                      id="gen-btn-<?= $adv['id'] ?>"
                      onclick="generateDiktat(<?= $adv['id'] ?>)">
                🤖 <?= $adv['diktat_generated'] ? 'Diktat neu generieren' : 'Diktat generieren' ?>
              </button>
            <?php endif; ?>
            <?php if (in_array($adv['status'], ['pending', 'cancelled'])): ?>
              <form method="post" action="<?= url('/admin/adventures/delete') ?>" style="margin:0"
                    onsubmit="return confirm('Abenteuer löschen?')">
                <input type="hidden" name="csrf_token"    value="<?= $csrfToken ?>">
                <input type="hidden" name="adventure_id"  value="<?= $adv['id'] ?>">
                <input type="hidden" name="child_id"      value="<?= $childId ?>">
                <button type="submit" class="btn btn-sm btn-danger">🗑️</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- Wörter -->
        <?php
          $wStmt = db()->prepare("SELECT word FROM custom_adventure_words WHERE adventure_id=? ORDER BY order_index");
          $wStmt->execute([$adv['id']]);
          $advWords = array_column($wStmt->fetchAll(), 'word');
        ?>
        <?php if (!empty($advWords)): ?>
        <div class="adv-words">
          <?php foreach ($advWords as $w): ?>
            <span class="adv-word"><?= htmlspecialchars($w) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Sätze -->
        <div id="sentences-<?= $adv['id'] ?>" class="sentence-list" style="margin-top:.6rem">
          <?php
            $sStmt = db()->prepare("SELECT id, sentence FROM custom_adventure_sentences WHERE adventure_id=? ORDER BY order_index");
            $sStmt->execute([$adv['id']]);
            $advSentences = $sStmt->fetchAll();
          ?>
          <?php if (!empty($advSentences)): ?>
            <div style="font-size:.8rem;color:var(--color-muted);margin-bottom:.3rem">📝 KI-Diktat:</div>
            <?php foreach ($advSentences as $i => $sent): ?>
              <div class="adv-sentence">
                <span style="color:var(--color-muted);font-size:.75rem;margin-right:.4rem"><?= $i+1 ?>.</span>
                <?= htmlspecialchars($sent['sentence']) ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div id="no-diktat-<?= $adv['id'] ?>" style="font-size:.82rem;color:var(--color-muted)">
              Noch kein Diktat generiert.
            </div>
          <?php endif; ?>
          <div id="diktat-loading-<?= $adv['id'] ?>" style="display:none;margin-top:.4rem;font-size:.85rem;color:var(--color-muted)">
            <span class="spinner"></span> KI generiert Diktat…
          </div>
        </div>

      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

</main>

<script>
const CSRF = <?= json_encode($csrfToken) ?>;
const URL_GENERATE = <?= json_encode(url('/admin/adventures/generate-diktat')) ?>;

function generateDiktat(advId) {
  const btn = document.getElementById('gen-btn-' + advId);
  const loading = document.getElementById('diktat-loading-' + advId);
  const container = document.getElementById('sentences-' + advId);
  const noLabel = document.getElementById('no-diktat-' + advId);

  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Generiert…'; }
  if (loading) loading.style.display = 'block';
  if (noLabel) noLabel.style.display = 'none';

  fetch(URL_GENERATE, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({csrf_token: CSRF, adventure_id: advId}),
  })
  .then(r => r.json())
  .then(data => {
    if (loading) loading.style.display = 'none';
    if (data.success && data.sentences) {
      // Alte Sätze entfernen, neue anzeigen
      const oldSents = container.querySelectorAll('.adv-sentence, .adv-sentence-label');
      oldSents.forEach(el => el.remove());

      const label = document.createElement('div');
      label.className = 'adv-sentence-label';
      label.style = 'font-size:.8rem;color:var(--color-muted);margin-bottom:.3rem';
      label.textContent = '📝 KI-Diktat:';
      container.insertBefore(label, loading);

      data.sentences.forEach((s, i) => {
        const div = document.createElement('div');
        div.className = 'adv-sentence';
        div.innerHTML = `<span style="color:var(--color-muted);font-size:.75rem;margin-right:.4rem">${i+1}.</span>${s}`;
        container.insertBefore(div, loading);
      });

      if (btn) { btn.disabled = false; btn.innerHTML = '🔄 Diktat neu generieren'; }
      const ttsNote = data.tts_errors > 0 ? ' (TTS: ' + data.tts_errors + ' Fehler)' : ' (TTS gecacht ✓)';
      showAlert('✅ Diktat generiert' + ttsNote, 'success');
    } else {
      if (btn) { btn.disabled = false; btn.innerHTML = '🤖 Diktat generieren'; }
      showAlert('❌ Fehler: ' + (data.error || 'Unbekannt'), 'error');
    }
  })
  .catch(err => {
    if (loading) loading.style.display = 'none';
    if (btn) { btn.disabled = false; btn.innerHTML = '🤖 Diktat generieren'; }
    showAlert('❌ Netzwerkfehler: ' + err.message, 'error');
  });
}

function showAlert(msg, type) {
  const el = document.createElement('div');
  el.className = 'alert alert-' + type;
  el.style = 'position:fixed;top:1rem;right:1rem;z-index:9999;max-width:360px;padding:.75rem 1rem;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15)';
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 4000);
}
</script>
</body>
</html>
