<?php
// Schritt 5: Zusammenfassung + Einstufungstest starten oder später
// Verfügbare Vars: $d1, $d2, $d4, $csrfToken, $themes

$d1  = $_SESSION['wizard']['step1'] ?? [];
$d2  = $_SESSION['wizard']['step2'] ?? [];
$d4  = $_SESSION['wizard']['step4'] ?? ['progress_test_interval' => 42];

// Theme-Name nachschlagen
$themeName = $d1['theme'] ?? 'minecraft';
foreach ($themes as $t) {
    if ($t['id'] === $themeName) {
        $themeName = ($t['icon'] ?? '') . ' ' . ($t['name'] ?? $t['id']);
        break;
    }
}

$aiProviderLabels = ['claude' => 'Anthropic Claude', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini'];
$ttsProviderLabels = [
    'browser'    => 'Browser-TTS (kostenlos)',
    'openai_tts' => 'OpenAI TTS',
    'google_tts' => 'Google Cloud TTS',
];

$interval = (int) ($d4['progress_test_interval'] ?? 42);
$weeks    = round($interval / 7, 1);
?>

<div class="summary-box">
  <h3>✅ Alles bereit — kurze Zusammenfassung:</h3>

  <table class="summary-table">
    <tr>
      <th>Kind</th>
      <td>
        <strong><?= htmlspecialchars($d1['display_name'] ?? '—') ?></strong>
        (Login: <code><?= htmlspecialchars($d1['username'] ?? '—') ?></code>)
      </td>
    </tr>
    <tr>
      <th>Klasse</th>
      <td>
        <?= (int)($d1['grade_level'] ?? 0) ?>. Klasse ·
        <?= htmlspecialchars($d1['school_type'] ?? '—') ?> ·
        <?= htmlspecialchars($d1['federal_state'] ?? '—') ?>
      </td>
    </tr>
    <tr>
      <th>Theme</th>
      <td><?= htmlspecialchars($themeName) ?></td>
    </tr>
    <tr>
      <th>KI-Backend</th>
      <td>
        <?= htmlspecialchars($aiProviderLabels[$d2['ai_provider'] ?? ''] ?? ($d2['ai_provider'] ?? '—')) ?>
        <?= !empty($d2['ai_api_key']) ? ' · API-Key gesetzt ✓' : ' · <em class="muted">kein API-Key</em>' ?>
      </td>
    </tr>
    <tr>
      <th>TTS</th>
      <td>
        <?= htmlspecialchars($ttsProviderLabels[$d2['tts_provider'] ?? ''] ?? ($d2['tts_provider'] ?? '—')) ?>
        <?php if (($d2['tts_provider'] ?? '') !== 'browser'): ?>
          <?= !empty($d2['tts_api_key']) ? ' · API-Key gesetzt ✓' : ' · <em class="muted">kein TTS-Key</em>' ?>
        <?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>Fortschrittstest</th>
      <td>alle <?= $interval ?> Tage (≈ <?= $weeks ?> Wochen)</td>
    </tr>
  </table>
</div>

<div class="step5-question">
  <h3>Wie möchtest du weitermachen?</h3>
  <p>
    Der <strong>Einstufungstest</strong> stellt fest, welche Fehlerkategorien
    <?= htmlspecialchars($d1['display_name'] ?? 'dein Kind') ?> braucht.
    Er dauert ca. 15–20 Minuten und kann auch aufgeteilt werden.
  </p>
</div>

<form method="post" action="<?= url('/setup/wizard') ?>" novalidate>
  <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="wizard_action" value="next">

  <div class="step5-actions">
    <!-- Jetzt starten -->
    <button type="submit" name="action" value="start_test"
            class="btn btn-primary step5-btn">
      <span class="step5-btn-icon">🎯</span>
      <span class="step5-btn-text">
        <strong>Einstufungstest jetzt starten</strong>
        <small>Direkt loslegen — ca. 15–20 Min.</small>
      </span>
    </button>

    <!-- Später -->
    <button type="submit" name="action" value="later"
            class="btn btn-secondary step5-btn">
      <span class="step5-btn-icon">⏳</span>
      <span class="step5-btn-text">
        <strong>Später starten</strong>
        <small>Zum Dashboard — Test kann jederzeit gestartet werden.</small>
      </span>
    </button>
  </div>

  <!-- Zurück -->
  <div class="wizard-nav" style="margin-top:1rem">
    <button type="submit" name="wizard_action" value="back"
            class="btn btn-ghost btn-sm">
      ← Zurück zu Schritt 4
    </button>
    <div></div>
  </div>
</form>
