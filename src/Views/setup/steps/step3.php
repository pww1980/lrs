<?php
// Schritt 3: Erklärung der Fehlerkategorien A1–D4 (reine Info-Seite)
// Verfügbare Vars: $categories (aus DB), $csrfToken

// Kategorien nach Block gruppieren
$blocks = [];
foreach ($categories as $cat) {
    $blocks[$cat['block']][] = $cat;
}

$blockMeta = [
    'A' => [
        'title' => 'Block A — Laut-Buchstaben-Zuordnung',
        'desc'  => 'Die phonetische Basis: Kinder lernen, wie Laute und Buchstaben zusammenhängen.',
        'icon'  => '🔤',
        'color' => 'block-a',
    ],
    'B' => [
        'title' => 'Block B — Regelwissen',
        'desc'  => 'Orthografische Regeln: Wann schreibt man was, und warum?',
        'icon'  => '📏',
        'color' => 'block-b',
    ],
    'C' => [
        'title' => 'Block C — Ableitungswissen',
        'desc'  => 'Morphologisches Denken: Wörter herleiten und Zusammenhänge verstehen.',
        'icon'  => '🔗',
        'color' => 'block-c',
    ],
    'D' => [
        'title' => 'Block D — Groß- und Kleinschreibung',
        'desc'  => 'Ein eigenständiger Block — besonders unter Schreibdruck fehleranfällig.',
        'icon'  => '🅰️',
        'color' => 'block-d',
    ],
];
?>

<div class="category-intro">
  <p>
    Der Diktat-Trainer arbeitet mit <strong>4 Fehlerblöcken</strong> und
    <strong>15 Kategorien</strong>. Der Einstufungstest ermittelt, wo
    <?= htmlspecialchars($_SESSION['wizard']['step1']['display_name'] ?? 'dein Kind') ?>
    gerade steht — danach erstellt die KI einen passenden Übungsplan.
  </p>
</div>

<?php foreach (['A','B','C','D'] as $blockKey): ?>
  <?php if (empty($blocks[$blockKey])) continue; ?>
  <?php $meta = $blockMeta[$blockKey]; ?>

  <div class="block-card <?= $meta['color'] ?>">
    <div class="block-card-header">
      <span class="block-icon"><?= $meta['icon'] ?></span>
      <div>
        <h3><?= htmlspecialchars($meta['title']) ?></h3>
        <p class="block-desc"><?= htmlspecialchars($meta['desc']) ?></p>
      </div>
    </div>
    <div class="category-grid">
      <?php foreach ($blocks[$blockKey] as $cat): ?>
        <div class="category-item">
          <span class="cat-code"><?= htmlspecialchars($cat['code']) ?></span>
          <span class="cat-label"><?= htmlspecialchars($cat['label']) ?></span>
          <span class="cat-desc"><?= htmlspecialchars($cat['description']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

<?php endforeach; ?>

<div class="info-box">
  💡 <strong>Jedes Wort hat eine Primärkategorie</strong> — und kann optionale
  Nebenkategorien haben. Die KI berücksichtigt das beim Erstellen des Übungsplans.
</div>

<form method="post" action="<?= url('/setup/wizard') ?>" novalidate>
  <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="wizard_action" value="next">

  <div class="wizard-nav">
    <button type="submit" name="wizard_action" value="back"
            class="btn btn-secondary">
      ← Zurück
    </button>
    <button type="submit" class="btn btn-primary btn-wizard-next">
      Verstanden — Weiter <span class="btn-arrow">→</span>
    </button>
  </div>
</form>
