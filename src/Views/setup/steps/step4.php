<?php
// Schritt 4: Fortschrittstest-Intervall
// Verfügbare Vars: $d4, $csrfToken

$interval = (int) ($d4['progress_test_interval'] ?? 42);

// Vorschläge
$presets = [
    ['days' => 28, 'label' => '4 Wochen',  'desc' => 'Intensiv — für sehr regelmäßiges Üben'],
    ['days' => 42, 'label' => '6 Wochen',  'desc' => 'Standard — empfohlen für die meisten Kinder'],
    ['days' => 56, 'label' => '8 Wochen',  'desc' => 'Moderat — mehr Zeit zum Festigen'],
    ['days' => 84, 'label' => '12 Wochen', 'desc' => 'Entspannt — wenig Zeitdruck'],
];
?>

<div class="category-intro">
  <p>
    Der <strong>Fortschrittstest</strong> vergleicht den aktuellen Stand mit dem
    Einstufungstest und zeigt, was sich verbessert hat. Er wird automatisch
    fällig, sobald das Intervall abgelaufen ist.
  </p>
</div>

<form method="post" action="<?= url('/setup/wizard') ?>" novalidate class="wizard-form">
  <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="wizard_action" value="next">

  <!-- Vorschläge als visuelle Auswahl -->
  <div class="form-group">
    <label>Testintervall <span class="required">*</span></label>
    <div class="interval-presets">
      <?php foreach ($presets as $p): ?>
        <label class="interval-card <?= $interval === $p['days'] ? 'selected' : '' ?>"
               for="preset_<?= $p['days'] ?>">
          <input type="radio" id="preset_<?= $p['days'] ?>"
                 name="progress_test_interval"
                 value="<?= $p['days'] ?>"
                 <?= $interval === $p['days'] ? 'checked' : '' ?>
                 onchange="document.getElementById('custom_days').value=this.value;
                           updateCustom(this.value);">
          <span class="interval-days"><?= $p['days'] ?></span>
          <span class="interval-unit">Tage</span>
          <span class="interval-label"><?= htmlspecialchars($p['label']) ?></span>
          <span class="interval-desc"><?= htmlspecialchars($p['desc']) ?></span>
          <?php if ($p['days'] === 42): ?>
            <span class="badge-recommended">Empfohlen</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Benutzerdefiniert -->
  <div class="form-group">
    <label for="custom_days">
      Oder eigenen Wert eingeben:
      <span class="label-hint">(14–365 Tage)</span>
    </label>
    <div class="custom-interval-row">
      <input type="number" id="custom_days" name="progress_test_interval"
             min="14" max="365" value="<?= $interval ?>"
             class="input-narrow"
             onchange="syncPresets(this.value)">
      <span class="unit-label">Tage</span>
      <span class="unit-label muted" id="weeks_label"></span>
    </div>
  </div>

  <div class="info-box">
    ⏰ <strong>Kann jederzeit angepasst werden</strong> — im Papa-Dashboard unter
    den Kind-Einstellungen.
  </div>

  <!-- Navigation -->
  <div class="wizard-nav">
    <button type="submit" name="wizard_action" value="back"
            class="btn btn-secondary">
      ← Zurück
    </button>
    <button type="submit" class="btn btn-primary btn-wizard-next">
      Weiter <span class="btn-arrow">→</span>
    </button>
  </div>
</form>

<script>
(function () {
  const customInput = document.getElementById('custom_days');
  const weeksLabel  = document.getElementById('weeks_label');

  function updateCustom(val) {
    val = parseInt(val, 10);
    if (!isNaN(val) && val > 0) {
      const w = (val / 7).toFixed(1);
      weeksLabel.textContent = '≈ ' + w + ' Wochen';
    }
  }

  function syncPresets(val) {
    val = parseInt(val, 10);
    document.querySelectorAll('.interval-card').forEach(card => {
      const radio = card.querySelector('input[type=radio]');
      const match = parseInt(radio.value, 10) === val;
      card.classList.toggle('selected', match);
      radio.checked = match;
    });
    updateCustom(val);
  }

  window.syncPresets  = syncPresets;
  window.updateCustom = updateCustom;

  customInput.addEventListener('input', function () { syncPresets(this.value); });

  updateCustom(customInput.value);

  // Interval-Cards anklickbar machen
  document.querySelectorAll('.interval-card input[type=radio]').forEach(radio => {
    radio.addEventListener('change', function () {
      document.querySelectorAll('.interval-card').forEach(c => c.classList.remove('selected'));
      this.closest('.interval-card').classList.add('selected');
    });
  });
})();
</script>
