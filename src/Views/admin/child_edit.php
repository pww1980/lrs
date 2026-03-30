<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kind bearbeiten — <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    .edit-container { max-width: 560px; margin: 2rem auto; padding: 0 1rem; }
    .edit-card      { background: var(--color-surface); border-radius: var(--radius); box-shadow: var(--shadow); padding: 2rem; }
    .edit-title     { font-size: 1.3rem; font-weight: 700; margin-bottom: 1.5rem; display: flex; align-items: center; gap: .5rem; }
    .section-label  { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--color-muted); margin: 1.25rem 0 .75rem; padding-bottom: .3rem; border-bottom: 1px solid var(--color-border); }
    .radio-group    { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .5rem; }
    .radio-option   { flex: 1; min-width: 80px; }
    .radio-option input[type=radio] { display: none; }
    .radio-option label {
      display: block; text-align: center; padding: .55rem .5rem; border: 2px solid var(--color-border);
      border-radius: 8px; cursor: pointer; font-size: .85rem; transition: all .15s;
    }
    .radio-option input[type=radio]:checked + label {
      border-color: var(--color-primary); background: #e8f5e9; color: var(--color-primary); font-weight: 700;
    }
    .theme-option label { font-size: 1.2rem; }
    .form-actions { display: flex; gap: .75rem; margin-top: 1.75rem; }
    .form-actions .btn { flex: 1; }
  </style>
</head>
<body>

<div class="edit-container">
  <div class="edit-card">
    <div class="edit-title">✏️ Kind bearbeiten</div>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= url('/admin/child/' . (int)$child['id'] . '/edit') ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Helpers\Auth::csrfToken()) ?>">
      <input type="hidden" name="child_id"   value="<?= (int)$child['id'] ?>">

      <!-- Name -->
      <div class="section-label">Profil</div>

      <div class="form-group">
        <label for="display_name">Name</label>
        <input type="text" id="display_name" name="display_name"
               value="<?= htmlspecialchars($child['display_name']) ?>" required>
      </div>

      <!-- Klasse -->
      <div class="form-group">
        <label>Klasse</label>
        <div class="radio-group">
          <?php foreach (range(1, 8) as $g): ?>
          <div class="radio-option">
            <input type="radio" name="grade_level" id="grade_<?= $g ?>"
                   value="<?= $g ?>" <?= (int)$child['grade_level'] === $g ? 'checked' : '' ?>>
            <label for="grade_<?= $g ?>"><?= $g ?>.</label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Schulform -->
      <div class="form-group">
        <label for="school_type">Schulform</label>
        <select id="school_type" name="school_type">
          <?php
          $types = ['Grundschule','Mittelschule','Realschule','Gymnasium','Gesamtschule'];
          foreach ($types as $t):
          ?>
          <option value="<?= htmlspecialchars($t) ?>" <?= $child['school_type'] === $t ? 'selected' : '' ?>>
            <?= htmlspecialchars($t) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Theme -->
      <div class="section-label">Theme</div>
      <div class="form-group">
        <div class="radio-group">
          <?php
          $themes = ['minecraft' => '⛏️ Minecraft', 'space' => '🚀 Space', 'ocean' => '🌊 Ocean'];
          foreach ($themes as $key => $label):
          ?>
          <div class="radio-option theme-option">
            <input type="radio" name="theme" id="theme_<?= $key ?>"
                   value="<?= $key ?>" <?= $child['theme'] === $key ? 'checked' : '' ?>>
            <label for="theme_<?= $key ?>"><?= $label ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- TTS Geschwindigkeit -->
      <div class="section-label">Vorlese-Geschwindigkeit</div>
      <?php $currentSpeed = $childSettings['tts_speed'] ?? 'normal'; ?>
      <div class="form-group">
        <div class="radio-group">
          <div class="radio-option">
            <input type="radio" name="tts_speed" id="speed_normal" value="normal"
                   <?= $currentSpeed === 'normal' ? 'checked' : '' ?>>
            <label for="speed_normal">▶ Normal</label>
          </div>
          <div class="radio-option">
            <input type="radio" name="tts_speed" id="speed_slow" value="slow"
                   <?= $currentSpeed === 'slow' ? 'checked' : '' ?>>
            <label for="speed_slow">🐢 Langsam</label>
          </div>
          <div class="radio-option">
            <input type="radio" name="tts_speed" id="speed_very_slow" value="very_slow"
                   <?= $currentSpeed === 'very_slow' ? 'checked' : '' ?>>
            <label for="speed_very_slow">🦥 Sehr langsam</label>
          </div>
        </div>
        <p style="font-size:.78rem;color:var(--color-muted);margin:.4rem 0 0">
          Bestimmt die Standard-Abspielgeschwindigkeit beim Diktat.
          Das Kind kann jederzeit zwischen Normal und Langsam wechseln.
        </p>
      </div>

      <!-- Aktiv -->
      <div class="section-label">Status</div>
      <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
        <input type="checkbox" id="active" name="active" value="1"
               <?= $child['active'] ? 'checked' : '' ?> style="width:auto">
        <label for="active" style="margin:0">Kind-Account aktiv</label>
      </div>

      <div class="form-actions">
        <a href="<?= url('/admin/dashboard') ?>" class="btn btn-secondary">Abbrechen</a>
        <button type="submit" class="btn btn-primary">Speichern</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
