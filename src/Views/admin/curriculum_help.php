<?php
/**
 * Admin: Lehrplan-Übersicht für ein Kind (A1–D4 erklärt)
 * Variablen: $child, $curriculum, $blocks, $federalState
 */
use App\Helpers\Auth;

$pageTitle = 'Lehrplan-Hilfe — ' . ($child['display_name'] ?? '') . ' — ' . APP_NAME;
$blockColors = [
    'A' => ['bg' => '#e8f5e9', 'border' => '#4caf50', 'text' => '#1b5e20', 'label' => 'Block A — Laut-Buchstaben-Zuordnung'],
    'B' => ['bg' => '#e3f2fd', 'border' => '#2196f3', 'text' => '#0d47a1', 'label' => 'Block B — Regelwissen'],
    'C' => ['bg' => '#fff3e0', 'border' => '#ff9800', 'text' => '#e65100', 'label' => 'Block C — Ableitungswissen'],
    'D' => ['bg' => '#f3e5f5', 'border' => '#9c27b0', 'text' => '#4a148c', 'label' => 'Block D — Groß-/Kleinschreibung'],
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
    .help-header { background: var(--color-primary-dk); color:#fff; padding:1.25rem 1.5rem; }
    .help-header h1 { font-size:1.2rem; margin:0; }
    .help-header .meta { font-size:.82rem; opacity:.8; margin-top:.25rem; }
    .help-container { max-width:860px; margin:2rem auto; padding:0 1rem 3rem; }
    .block-section { margin-bottom:2rem; border-radius:12px; overflow:hidden;
                     box-shadow:0 2px 8px rgba(0,0,0,.08); }
    .block-header { padding:1rem 1.25rem; font-weight:700; font-size:1rem;
                    display:flex; align-items:center; gap:.6rem; }
    .block-code { font-size:1.3rem; font-weight:900; min-width:2rem; }
    .category-list { background:#fff; }
    .category-item { padding:.85rem 1.25rem; border-top:1px solid #f0f0f0; }
    .category-item:first-child { border-top:none; }
    .cat-head { display:flex; align-items:center; gap:.6rem; margin-bottom:.4rem; cursor:pointer; }
    .cat-badge { font-size:.75rem; font-weight:800; padding:.2rem .55rem;
                 border-radius:5px; font-family:monospace; flex-shrink:0; }
    .cat-label { font-weight:700; font-size:.95rem; flex:1; }
    .cat-toggle { font-size:.75rem; color:var(--color-muted); flex-shrink:0; }
    .cat-body { display:none; margin-top:.5rem; }
    .cat-body.open { display:block; }
    .cat-curriculum { font-size:.85rem; color:#444; line-height:1.6;
                      background:#fafafa; border-left:3px solid var(--color-border);
                      padding:.5rem .75rem; border-radius:0 4px 4px 0; margin-bottom:.5rem; }
    .cat-principle { font-size:.78rem; color:var(--color-muted); margin-bottom:.5rem; }
    .cat-grade { font-size:.78rem; color:var(--color-muted); }
    .examples-label { font-size:.78rem; font-weight:600; color:var(--color-muted);
                      margin:.5rem 0 .3rem; }
    .example-chips { display:flex; flex-wrap:wrap; gap:.35rem; }
    .example-chip { background:#f0f4ff; border:1px solid #c5cde8; color:#3f51b5;
                    border-radius:5px; padding:.15rem .55rem; font-size:.82rem; font-weight:600; }
    .no-curriculum { text-align:center; padding:3rem 1rem; color:var(--color-muted); }
    .no-curriculum .icon { font-size:3rem; margin-bottom:1rem; }
    .source-bar { background:#f5f5f5; border-radius:8px; padding:.6rem 1rem;
                  font-size:.8rem; color:var(--color-muted); margin-bottom:1.5rem; }
    .source-bar strong { color:var(--color-text); }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($_SESSION['theme'] ?? 'minecraft') ?>">

<nav class="navbar">
  <div class="navbar-brand"><?= themeIcon() ?> <?= htmlspecialchars(APP_NAME) ?></div>
  <div class="navbar-links">
    <a href="<?= url('/admin/dashboard') ?>">← Dashboard</a>
    <span class="navbar-user">👤 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
    <a href="<?= url('/logout') ?>">Abmelden</a>
  </div>
</nav>

<main class="help-container">

  <h1 style="font-size:1.4rem;margin-bottom:.25rem">
    📖 Lehrplan-Übersicht — <?= htmlspecialchars($child['display_name']) ?>
  </h1>
  <p style="color:var(--color-muted);margin-bottom:1.5rem;font-size:.9rem">
    Was bedeuten die Fehlerkategorien A1–D4?
    Klick auf eine Kategorie für die genaue Lehrplan-Erklärung.
  </p>

  <?php if ($curriculum): ?>
    <div class="source-bar">
      📚 Quelle: <strong><?= htmlspecialchars($curriculum['source'] ?? '—') ?></strong>
      &nbsp;·&nbsp; <?= htmlspecialchars($curriculum['federal_state'] ?? '') ?>
      &nbsp;·&nbsp; <?= htmlspecialchars($curriculum['school_type'] ?? '') ?>
      &nbsp;·&nbsp; Klasse <?= htmlspecialchars($curriculum['grades'] ?? '') ?>
      <?php if (!empty($curriculum['url'])): ?>
        &nbsp;·&nbsp; <a href="<?= htmlspecialchars($curriculum['url']) ?>" target="_blank"
                         style="font-size:.78rem">Lehrplan ansehen ↗</a>
      <?php endif; ?>
    </div>

    <?php foreach ($blocks as $blockKey => $cats):
      $bc = $blockColors[$blockKey] ?? ['bg'=>'#f5f5f5','border'=>'#999','text'=>'#333','label'=>'Block '.$blockKey];
    ?>
    <div class="block-section">
      <div class="block-header" style="background:<?= $bc['bg'] ?>;border-left:5px solid <?= $bc['border'] ?>;color:<?= $bc['text'] ?>">
        <span class="block-code"><?= htmlspecialchars($blockKey) ?></span>
        <span><?= htmlspecialchars($bc['label']) ?></span>
      </div>
      <div class="category-list">
        <?php foreach ($cats as $cat): ?>
        <div class="category-item">
          <div class="cat-head" onclick="toggleCat(this)">
            <span class="cat-badge" style="background:<?= $bc['bg'] ?>;color:<?= $bc['text'] ?>;border:1px solid <?= $bc['border'] ?>">
              <?= htmlspecialchars($cat['code']) ?>
            </span>
            <span class="cat-label"><?= htmlspecialchars($cat['label'] ?? $cat['code']) ?></span>
            <span class="cat-toggle">▼ mehr</span>
          </div>
          <div class="cat-body">
            <?php if (!empty($cat['curriculum_text'])): ?>
              <div class="cat-curriculum"><?= htmlspecialchars($cat['curriculum_text']) ?></div>
            <?php endif; ?>
            <?php if (!empty($cat['curriculum_principle'])): ?>
              <div class="cat-principle">🔬 Prinzip: <?= htmlspecialchars($cat['curriculum_principle']) ?></div>
            <?php endif; ?>
            <?php if (!empty($cat['grade_focus'])): ?>
              <div class="cat-grade">📅 <?= htmlspecialchars($cat['grade_focus']) ?></div>
            <?php endif; ?>
            <?php if (!empty($cat['examples_official'])): ?>
              <div class="examples-label">Lehrplan-Beispiele:</div>
              <div class="example-chips">
                <?php foreach ($cat['examples_official'] as $ex): ?>
                  <span class="example-chip"><?= htmlspecialchars($ex) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

  <?php else: ?>
    <div class="no-curriculum">
      <div class="icon">📋</div>
      <p><strong>Kein passender Lehrplan gefunden.</strong></p>
      <p style="margin-top:.5rem;font-size:.88rem">
        <?php if (!$federalState): ?>
          Für <?= htmlspecialchars($child['display_name']) ?> wurde noch kein Bundesland eingerichtet.<br>
          Bitte den Setup-Assistenten abschließen.
        <?php else: ?>
          Kein Lehrplan für <strong><?= htmlspecialchars($federalState) ?></strong>,
          <?= htmlspecialchars($child['school_type'] ?? '—') ?>,
          Klasse <?= (int)$child['grade_level'] ?> vorhanden.<br>
          <small>Verfügbare Lehrpläne: Bayern GS 3/4, Bayern Gymnasium 5/6, Bayern Mittelschule 5/6</small>
        <?php endif; ?>
      </p>
    </div>
  <?php endif; ?>

</main>

<script>
function toggleCat(head) {
  var body = head.nextElementSibling;
  var toggle = head.querySelector('.cat-toggle');
  if (body.classList.contains('open')) {
    body.classList.remove('open');
    toggle.textContent = '▼ mehr';
  } else {
    body.classList.add('open');
    toggle.textContent = '▲ weniger';
  }
}
</script>
</body>
</html>
