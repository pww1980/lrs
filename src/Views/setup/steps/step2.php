<?php
// Schritt 2: API-Keys (KI-Backend + TTS)
// Verfügbare Vars: $d2 (vorherige Eingaben), $csrfToken

$aiProvider  = $d2['ai_provider']  ?? $d2['aiProvider']  ?? 'claude';
$ttsProvider = $d2['tts_provider'] ?? $d2['ttsProvider'] ?? 'browser';

$encKeyOk = strlen(APP_ENCRYPTION_KEY) >= 16
         && APP_ENCRYPTION_KEY !== 'BITTE_AENDERN_min32zeichen_random';
?>

<?php if (!$encKeyOk): ?>
  <div class="alert alert-warn">
    ⚠️ <strong>APP_ENCRYPTION_KEY</strong> ist noch der Platzhalterwert aus der
    <code>.env.example</code>.
    Bitte ändere ihn in deiner <code>.env</code> Datei auf einen zufälligen String
    (min. 32 Zeichen), bevor du API-Keys speicherst.<br>
    <small>Generieren: <code>php -r "echo bin2hex(random_bytes(32));"</code></small>
  </div>
<?php endif; ?>

<form method="post" action="<?= url('/setup/wizard') ?>" novalidate class="wizard-form">
  <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrfToken) ?>">
  <input type="hidden" name="wizard_action" value="next">

  <!-- ── KI-Backend ────────────────────────────────────────────── -->
  <section class="api-section">
    <h3 class="api-section-title">🤖 KI-Backend <span class="label-hint">(für Auswertung & Übungsplan)</span></h3>

    <div class="form-row">
      <div class="form-group">
        <label for="ai_provider">Anbieter <span class="required">*</span></label>
        <select id="ai_provider" name="ai_provider" required>
          <option value="claude"  <?= $aiProvider === 'claude'  ? 'selected' : '' ?>>
            Anthropic Claude
          </option>
          <option value="openai"  <?= $aiProvider === 'openai'  ? 'selected' : '' ?>>
            OpenAI ChatGPT
          </option>
          <option value="gemini"  <?= $aiProvider === 'gemini'  ? 'selected' : '' ?>>
            Google Gemini
          </option>
        </select>
      </div>

      <div class="form-group">
        <label for="ai_api_key">API-Key</label>
        <input type="password" id="ai_api_key" name="ai_api_key"
               autocomplete="off"
               placeholder="sk-… oder AIza…"
               value="">
        <span class="field-hint" id="ai_key_hint"></span>
      </div>
    </div>

    <div class="api-info" id="ai_info_claude">
      <strong>Claude:</strong> API-Key von
      <a href="#" onclick="return false">console.anthropic.com</a>
      (Modell: claude-sonnet-4-6 wird empfohlen)
    </div>
    <div class="api-info hidden" id="ai_info_openai">
      <strong>OpenAI:</strong> API-Key von platform.openai.com
      (Modell: gpt-4o wird empfohlen)
    </div>
    <div class="api-info hidden" id="ai_info_gemini">
      <strong>Gemini:</strong> API-Key von aistudio.google.com
      (Modell: gemini-1.5-pro wird empfohlen)
    </div>
  </section>

  <hr class="section-divider">

  <!-- ── TTS ──────────────────────────────────────────────────── -->
  <section class="api-section">
    <h3 class="api-section-title">🔊 Text-to-Speech <span class="label-hint">(Wörter vorlesen)</span></h3>

    <div class="form-row">
      <div class="form-group">
        <label for="tts_provider">Anbieter <span class="required">*</span></label>
        <select id="tts_provider" name="tts_provider" required>
          <option value="browser"    <?= $ttsProvider === 'browser'    ? 'selected' : '' ?>>
            Browser-TTS (kostenlos, kein Key)
          </option>
          <option value="openai_tts" <?= $ttsProvider === 'openai_tts' ? 'selected' : '' ?>>
            OpenAI TTS (Stimme: nova)
          </option>
          <option value="google_tts" <?= $ttsProvider === 'google_tts' ? 'selected' : '' ?>>
            Google Cloud TTS
          </option>
        </select>
      </div>

      <div class="form-group" id="tts_key_group">
        <label for="tts_api_key">TTS API-Key</label>
        <input type="password" id="tts_api_key" name="tts_api_key"
               autocomplete="off"
               placeholder="sk-… oder AIza…"
               value="">
      </div>
    </div>

    <div class="api-info" id="tts_info_browser">
      <strong>Browser-TTS:</strong> Kostenlos, nutzt das eingebaute Sprachsystem des Geräts.
      Qualität variiert je nach Gerät und Betriebssystem. Empfehlung für den Einstieg.
    </div>
    <div class="api-info hidden" id="tts_info_openai_tts">
      <strong>OpenAI TTS:</strong> Sehr natürliche Stimme (nova, alloy, echo…).
      Gleicher API-Key wie für ChatGPT — kein separater Key nötig.
    </div>
    <div class="api-info hidden" id="tts_info_google_tts">
      <strong>Google Cloud TTS:</strong> Hochwertige deutsche Stimmen.
      Erfordert Google Cloud API-Key mit TTS-Berechtigung.
    </div>
  </section>

  <!-- Schlüssel später eingeben -->
  <div class="form-group" style="margin-top:.5rem">
    <label class="checkbox-label">
      <input type="checkbox" name="skip_keys" id="skip_keys"
             <?= isset($d2['skip_keys']) ? 'checked' : '' ?>>
      <span>API-Keys später in den Einstellungen eingeben</span>
    </label>
    <span class="field-hint">
      Du kannst API-Keys jederzeit im Admin-Dashboard unter Einstellungen nachtragen.
      Browser-TTS funktioniert ohne Key.
    </span>
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
  const aiSel     = document.getElementById('ai_provider');
  const ttsSel    = document.getElementById('tts_provider');
  const ttsKeyGrp = document.getElementById('tts_key_group');
  const skipChk   = document.getElementById('skip_keys');
  const aiKey     = document.getElementById('ai_api_key');
  const ttsKey    = document.getElementById('tts_api_key');

  function updateAiInfo() {
    ['claude','openai','gemini'].forEach(p => {
      document.getElementById('ai_info_' + p).classList.toggle('hidden', aiSel.value !== p);
    });
  }

  function updateTtsInfo() {
    ['browser','openai_tts','google_tts'].forEach(p => {
      document.getElementById('tts_info_' + p).classList.toggle('hidden', ttsSel.value !== p);
    });
    ttsKeyGrp.classList.toggle('hidden', ttsSel.value === 'browser');
  }

  function updateSkip() {
    const skip = skipChk.checked;
    aiKey.required  = !skip;
    ttsKey.required = !skip && ttsSel.value !== 'browser';
  }

  aiSel.addEventListener('change',  updateAiInfo);
  ttsSel.addEventListener('change', updateTtsInfo);
  skipChk.addEventListener('change', updateSkip);

  updateAiInfo();
  updateTtsInfo();
  updateSkip();
})();
</script>
