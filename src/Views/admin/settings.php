<?php
/**
 * Admin — Familieneinstellungen (KI-Backend + TTS)
 * Keys werden pro Admin-User gespeichert und gelten für alle Kinder dieser Familie.
 */
use App\Helpers\Auth;
$csrfToken   = Auth::csrfToken();
$pageTitle   = 'Einstellungen — ' . APP_NAME;

$aiProvider  = $settings['ai_provider']  ?? 'claude';
$ttsProvider = $settings['tts_provider'] ?? 'browser';
$hasAiKey    = !empty($settings['ai_api_key']);
$hasTtsKey   = !empty($settings['tts_api_key']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="/public/css/app.css">
  <style>
    .settings-wrap { max-width: 600px; margin: 2rem auto; padding: 0 1rem; }
    .settings-card {
      background: #fff;
      border: 1px solid var(--color-border);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
    }
    .settings-card h3 {
      font-size: 1rem; font-weight: 700; margin: 0 0 1rem;
      padding-bottom: .5rem; border-bottom: 2px solid var(--color-border);
      color: var(--color-primary-dk);
    }
    .form-row { margin-bottom: 1rem; }
    .form-row label {
      display: block; font-size: .83rem; color: #666;
      margin-bottom: .3rem; font-weight: 600;
    }
    .form-row select,
    .form-row input[type=password] {
      width: 100%; padding: .45rem .7rem;
      border: 1px solid var(--color-border); border-radius: 6px;
      font-size: .9rem; box-sizing: border-box;
    }
    .key-row {
      display: flex; gap: .5rem; align-items: center;
    }
    .key-row input { flex: 1; }
    .key-status {
      font-size: .78rem; border-radius: 12px; padding: .2rem .65rem;
      white-space: nowrap; flex-shrink: 0;
    }
    .key-status.set   { background: #dcfce7; color: #15803d; }
    .key-status.unset { background: #fee2e2; color: #dc2626; }
    .help-text {
      font-size: .78rem; color: #888; margin-top: .4rem; line-height: 1.5;
    }
    .help-text a { color: #1a56db; }
    .provider-hint {
      background: #f8fafc; border: 1px solid var(--color-border);
      border-radius: 8px; padding: .75rem 1rem; margin-top: .75rem;
      font-size: .82rem; line-height: 1.6; color: #555;
    }
    .provider-hint strong { color: #222; }
    .tts-browser-note {
      background: #f0fdf4; border-radius: 8px; padding: .65rem 1rem;
      font-size: .82rem; color: #15803d; margin-top: .6rem;
    }
    .family-note {
      background: #fffde7; border: 1px solid #ffd54f;
      border-radius: 8px; padding: .75rem 1rem;
      font-size: .85rem; color: #5d4037; margin-bottom: 1.5rem;
    }
  </style>
</head>
<body class="theme-<?= htmlspecialchars($_SESSION['theme'] ?? 'minecraft') ?>">

<nav class="navbar">
  <span class="navbar-brand">⛏️ <?= htmlspecialchars(APP_NAME) ?></span>
  <span class="navbar-user">👤 <?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
  <a href="<?= url('/admin/dashboard') ?>" class="btn btn-sm btn-secondary" style="margin-right:.35rem">← Dashboard</a>
  <a href="<?= url('/logout') ?>" class="btn btn-sm">Abmelden</a>
</nav>

<div class="settings-wrap">
  <h2 style="margin-bottom:1.25rem">⚙️ Familieneinstellungen</h2>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?>" style="margin-bottom:1rem">
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  <?php endif ?>

  <div class="family-note">
    💡 Diese Einstellungen gelten für alle Kinder in deiner Familie.
    API-Keys werden sicher verschlüsselt gespeichert — nie im Klartext.
  </div>

  <form method="POST" action="<?= url('/admin/settings') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <!-- KI-Backend -->
    <div class="settings-card">
      <h3>🤖 KI-Backend (Auswertung + Feedback)</h3>

      <div class="form-row">
        <label for="ai_provider">Anbieter</label>
        <select id="ai_provider" name="ai_provider" onchange="updateAiHint()">
          <option value="claude"  <?= $aiProvider==='claude'  ?'selected':'' ?>>Claude (Anthropic) — empfohlen</option>
          <option value="openai"  <?= $aiProvider==='openai'  ?'selected':'' ?>>ChatGPT (OpenAI)</option>
          <option value="gemini"  <?= $aiProvider==='gemini'  ?'selected':'' ?>>Gemini (Google)</option>
        </select>
      </div>

      <div class="form-row">
        <label for="ai_api_key">API-Key</label>
        <div class="key-row">
          <input type="password" id="ai_api_key" name="ai_api_key"
                 placeholder="<?= $hasAiKey ? '(unverändert lassen = bestehender Key bleibt)' : 'sk-ant-…  /  sk-…  /  AIzaSy…' ?>"
                 autocomplete="new-password">
          <span class="key-status <?= $hasAiKey ? 'set' : 'unset' ?>">
            <?= $hasAiKey ? '✓ Key gesetzt' : 'Kein Key' ?>
          </span>
        </div>
        <p class="help-text" id="ai-key-help"></p>
      </div>

      <div class="provider-hint" id="ai-provider-hint"></div>
    </div>

    <!-- TTS -->
    <div class="settings-card">
      <h3>🔊 Text-to-Speech (Vorlesen)</h3>

      <div class="form-row">
        <label for="tts_provider">Anbieter</label>
        <select id="tts_provider" name="tts_provider" onchange="updateTtsHint()">
          <option value="openai_tts" <?= $ttsProvider==='openai_tts' ?'selected':'' ?>>OpenAI TTS (Stimme: nova) — beste Qualität</option>
          <option value="google_tts" <?= $ttsProvider==='google_tts' ?'selected':'' ?>>Google Cloud TTS</option>
          <option value="browser"    <?= $ttsProvider==='browser'    ?'selected':'' ?>>Browser-TTS (kostenlos, kein Key nötig)</option>
        </select>
      </div>

      <div id="tts-key-row" class="form-row">
        <label for="tts_api_key">TTS API-Key</label>
        <div class="key-row">
          <input type="password" id="tts_api_key" name="tts_api_key"
                 placeholder="<?= $hasTtsKey ? '(unverändert lassen = bestehender Key bleibt)' : 'sk-… oder AIzaSy…' ?>"
                 autocomplete="new-password">
          <span class="key-status <?= $hasTtsKey ? 'set' : 'unset' ?>" id="tts-key-status">
            <?= $hasTtsKey ? '✓ Key gesetzt' : 'Kein Key' ?>
          </span>
        </div>
      </div>

      <div id="tts-browser-note" class="tts-browser-note" style="display:none">
        🌐 Kein API-Key nötig — der Browser liest direkt vor. Qualität je nach Gerät/Browser.
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
    <a href="<?= url('/admin/dashboard') ?>" class="btn btn-secondary" style="margin-left:.5rem">Abbrechen</a>
  </form>
</div>

<script>
const AI_HINTS = {
  claude:  { url: 'console.anthropic.com/settings/keys', prefix: 'sk-ant-…', label: 'Anthropic Console' },
  openai:  { url: 'platform.openai.com/api-keys',        prefix: 'sk-…',    label: 'OpenAI Platform' },
  gemini:  { url: 'aistudio.google.com/apikey',          prefix: 'AIzaSy…', label: 'Google AI Studio' },
};
const TTS_HINTS = {
  openai_tts: 'Gleicher API-Key wie OpenAI ChatGPT — kein separater Key nötig.',
  google_tts: 'Google Cloud API-Key mit aktivierter Text-to-Speech API.',
  browser:    '',
};

function updateAiHint() {
  const prov = document.getElementById('ai_provider').value;
  const h    = AI_HINTS[prov] || {};
  const hintEl = document.getElementById('ai-provider-hint');
  const helpEl = document.getElementById('ai-key-help');
  hintEl.innerHTML = h.label
    ? `<strong>${h.label}:</strong> API-Key beginnt mit <code>${h.prefix}</code><br>
       Erhältlich unter <a href="https://${h.url}" target="_blank" rel="noopener">${h.url}</a>`
    : '';
  helpEl.textContent = h.prefix ? 'Format: ' + h.prefix : '';
}

function updateTtsHint() {
  const prov    = document.getElementById('tts_provider').value;
  const keyRow  = document.getElementById('tts-key-row');
  const noteEl  = document.getElementById('tts-browser-note');
  const isBrowser = (prov === 'browser');
  keyRow.style.display  = isBrowser ? 'none' : '';
  noteEl.style.display  = isBrowser ? '' : 'none';
}

// Init
updateAiHint();
updateTtsHint();
</script>
</body>
</html>
