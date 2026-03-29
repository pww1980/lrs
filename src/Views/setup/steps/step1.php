<?php
// Schritt 1: Kind anlegen
// Verfügbare Vars: $d1 (vorherige Eingaben), $themes, $schulformen, $bundeslaender, $csrfToken

$v = fn(string $k, $default = '') => htmlspecialchars($d1[$k] ?? $default);
?>

<form method="post" action="/setup/wizard" novalidate class="wizard-form">
  <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="wizard_action" value="next">

  <div class="form-row">
    <!-- Name -->
    <div class="form-group">
      <label for="display_name">Name des Kindes <span class="required">*</span></label>
      <input type="text" id="display_name" name="display_name"
             value="<?= $v('displayName', $d1['display_name'] ?? '') ?>"
             placeholder="z.B. Lennart"
             required autofocus maxlength="100">
    </div>

    <!-- Benutzername -->
    <div class="form-group">
      <label for="username">
        Benutzername <span class="required">*</span>
        <span class="label-hint">(für den Login)</span>
      </label>
      <input type="text" id="username" name="username"
             value="<?= $v('username') ?>"
             placeholder="z.B. lennart"
             required pattern="[a-zA-Z0-9_\-]{3,50}"
             title="3–50 Zeichen, nur Buchstaben, Ziffern, _ und -">
      <span class="field-hint">Wird automatisch vorgeschlagen — kann geändert werden.</span>
    </div>
  </div>

  <div class="form-row">
    <!-- Passwort -->
    <div class="form-group">
      <label for="password">
        Passwort <span class="required">*</span>
        <span class="label-hint">(min. 6 Zeichen)</span>
      </label>
      <input type="password" id="password" name="password"
             required autocomplete="new-password"
             minlength="6">
    </div>

    <!-- Passwort bestätigen -->
    <div class="form-group">
      <label for="password_confirm">Passwort bestätigen <span class="required">*</span></label>
      <input type="password" id="password_confirm" name="password_confirm"
             required autocomplete="new-password"
             minlength="6">
    </div>
  </div>

  <div class="form-row three-col">
    <!-- Klasse -->
    <div class="form-group">
      <label for="grade_level">Klasse <span class="required">*</span></label>
      <select id="grade_level" name="grade_level" required>
        <option value="">— wählen —</option>
        <?php for ($i = 1; $i <= 13; $i++): ?>
          <option value="<?= $i ?>"
            <?= (int)($d1['grade_level'] ?? $d1['gradeLevel'] ?? 0) === $i ? 'selected' : '' ?>>
            <?= $i ?>. Klasse
          </option>
        <?php endfor; ?>
      </select>
    </div>

    <!-- Schulform -->
    <div class="form-group">
      <label for="school_type">Schulform <span class="required">*</span></label>
      <select id="school_type" name="school_type" required>
        <option value="">— wählen —</option>
        <?php foreach ($schulformen as $sf): ?>
          <option value="<?= htmlspecialchars($sf) ?>"
            <?= ($d1['school_type'] ?? $d1['schoolType'] ?? '') === $sf ? 'selected' : '' ?>>
            <?= htmlspecialchars($sf) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Bundesland -->
    <div class="form-group">
      <label for="federal_state">Bundesland <span class="required">*</span></label>
      <select id="federal_state" name="federal_state" required>
        <option value="">— wählen —</option>
        <?php foreach ($bundeslaender as $bl): ?>
          <option value="<?= htmlspecialchars($bl) ?>"
            <?= ($d1['federal_state'] ?? $d1['federalState'] ?? '') === $bl ? 'selected' : '' ?>>
            <?= htmlspecialchars($bl) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="field-hint">Bestimmt den Lehrplan für Übungswörter.</span>
    </div>
  </div>

  <!-- Theme-Auswahl -->
  <div class="form-group">
    <label>Theme <span class="required">*</span>
      <span class="label-hint">(weitere Themes werden durch Fortschritt freigeschaltet)</span>
    </label>
    <div class="theme-picker">
      <?php foreach ($themes as $theme): ?>
        <?php
          $tid = htmlspecialchars($theme['id']);
          $currentTheme = $d1['theme'] ?? $d1['theme'] ?? 'minecraft';
          $checked = $currentTheme === $theme['id'] ? 'checked' : '';
        ?>
        <label class="theme-card <?= $checked ? 'selected' : '' ?>" for="theme_<?= $tid ?>">
          <input type="radio" id="theme_<?= $tid ?>" name="theme"
                 value="<?= $tid ?>" <?= $checked ?> required>
          <span class="theme-icon"><?= htmlspecialchars($theme['icon'] ?? '🎮') ?></span>
          <span class="theme-name"><?= htmlspecialchars($theme['name'] ?? $theme['id']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Navigation -->
  <div class="wizard-nav">
    <div></div><!-- Platzhalter links (kein Zurück in Schritt 1) -->
    <button type="submit" class="btn btn-primary btn-wizard-next">
      Weiter <span class="btn-arrow">→</span>
    </button>
  </div>
</form>

<script>
// Benutzername automatisch aus Name vorschlagen
(function () {
  const nameInput     = document.getElementById('display_name');
  const userInput     = document.getElementById('username');
  let userTouched     = userInput.value !== '';

  userInput.addEventListener('input', () => { userTouched = true; });

  nameInput.addEventListener('input', function () {
    if (userTouched) return;
    // Einfache Client-seitige Vorschau (Server normalisiert final)
    let v = this.value.toLowerCase()
      .replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss')
      .replace(/[^a-z0-9_\-]/g,'').replace(/^[-_]+|[-_]+$/g,'');
    userInput.value = v.substring(0, 50);
  });

  // Theme-Cards bei Klick markieren
  document.querySelectorAll('.theme-card input[type=radio]').forEach(radio => {
    radio.addEventListener('change', function () {
      document.querySelectorAll('.theme-card').forEach(c => c.classList.remove('selected'));
      this.closest('.theme-card').classList.add('selected');
    });
  });
})();
</script>
