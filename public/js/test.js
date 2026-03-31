/**
 * Einstufungstest — JavaScript State Machine
 *
 * Zustände: loading → playing → answering → submitting → feedback → [next item | section_done]
 * TTS: server-seitig (Audio-Binary) oder Browser-TTS (Web Speech API), transparent behandelt.
 */

(function () {
  'use strict';

  // ── DOM-Referenzen ──────────────────────────────────────────────────────
  const ttsIcon          = document.getElementById('tts-icon');
  const ttsStatus        = document.getElementById('tts-status');
  const btnReplay        = document.getElementById('btn-replay');
  const btnSlow          = document.getElementById('btn-slow');
  const answerInput      = document.getElementById('answer-input');
  const btnSubmit        = document.getElementById('btn-submit');
  const itemCounter      = document.getElementById('item-counter');
  const progressLabel    = document.getElementById('progress-label');
  const feedbackOverlay  = document.getElementById('feedback-overlay');
  const feedbackBox      = document.getElementById('feedback-box');
  const feedbackEmoji    = document.getElementById('feedback-emoji');
  const feedbackMain     = document.getElementById('feedback-main');
  const feedbackAnswer   = document.getElementById('feedback-answer');
  const feedbackRule     = document.getElementById('feedback-rule');
  const feedbackCountdown = document.getElementById('feedback-countdown');
  const sectionTransition = document.getElementById('section-transition');
  const trBiomeIcon      = document.getElementById('tr-biome-icon');
  const trTitle          = document.getElementById('tr-title');
  const trStats          = document.getElementById('tr-stats');
  const trFatigue        = document.getElementById('tr-fatigue');
  const trNext           = document.getElementById('tr-next');
  const btnNextSection   = document.getElementById('btn-next-section');
  const btnPauseSection  = document.getElementById('btn-pause-section');
  const btnPauseNav      = document.getElementById('btn-pause-nav');

  // ── Zustand ─────────────────────────────────────────────────────────────
  let state          = 'loading';   // loading | playing | answering | submitting | feedback | done
  let currentItemId  = TEST_DATA.itemId;
  let currentSectionId = TEST_DATA.sectionId;
  let answeredCount  = TEST_DATA.answered;
  let totalCount     = TEST_DATA.totalItems;
  let timerStart     = null;   // ms, gesetzt wenn TTS fertig
  let audioObj       = null;   // HTMLAudioElement (server TTS)
  let browserUtter   = null;   // SpeechSynthesisUtterance (Browser TTS)
  let feedbackTimer  = null;
  let countdownTimer = null;
  let nextSectionData = null;  // wird nach completeSection gesetzt

  // ── Initialisierung ──────────────────────────────────────────────────────
  // Browser-TTS Stimmen vorab laden (Chrome lädt sie asynchron)
  if (window.speechSynthesis) {
    window.speechSynthesis.getVoices();
    window.speechSynthesis.onvoiceschanged = function() { window.speechSynthesis.getVoices(); };
  }

  updateCounter();

  // ── 2-Sekunden-Startpause ───────────────────────────────────────────────
  // Gibt dem Kind Zeit sich zu sammeln, bevor das erste Wort gespielt wird.
  (function startCountdown() {
    var secs = 2;
    ttsStatus.textContent = 'Bereit? Gleich geht\'s los … ' + secs;
    ttsIcon.style.opacity = '0.4';
    answerInput.disabled  = true;
    btnSubmit.disabled    = true;

    var cd = setInterval(function() {
      secs--;
      if (secs <= 0) {
        clearInterval(cd);
        ttsIcon.style.opacity = '';
        ttsStatus.textContent = 'Wort wird geladen…';
        loadAndPlayTts(currentItemId, 'normal');
      } else {
        ttsStatus.textContent = 'Bereit? Gleich geht\'s los … ' + secs;
      }
    }, 1000);
  })();

  // ── TTS ─────────────────────────────────────────────────────────────────

  function loadAndPlayTts(itemId, speed) {
    setState('loading');
    stopAudio();
    ttsStatus.textContent = 'Wort wird geladen…';
    ttsIcon.classList.remove('playing');

    // Nach 3s: "Ton überspringen"-Button einblenden
    var skipTimer = setTimeout(function () {
      if (state === 'loading') {
        ttsStatus.innerHTML = 'Lädt… <button type="button" id="btn-skip-tts" '
          + 'style="margin-left:.5rem;padding:.15rem .6rem;font-size:.8rem;cursor:pointer;border-radius:4px;border:1px solid #888;background:#333;color:#fff">'
          + 'Ton überspringen</button>';
        var skipBtn = document.getElementById('btn-skip-tts');
        if (skipBtn) skipBtn.addEventListener('click', function () {
          abortController.abort();
          ttsStatus.textContent = '(Ton übersprungen)';
          enableAnswering();
        });
      }
    }, 3000);

    // AbortController für 8s Client-Timeout
    var abortController = new AbortController();
    var timeoutTimer = setTimeout(function () { abortController.abort(); }, 8000);

    fetch(`/index.php?_r=%2Flearn%2Ftest%2Ftts&item_id=${itemId}&speed=${speed}`,
          { signal: abortController.signal })
      .then(r => {
        clearTimeout(skipTimer);
        clearTimeout(timeoutTimer);
        const ct = r.headers.get('Content-Type') || '';
        if (ct.includes('application/json')) {
          return r.json().then(data => ({ type: 'browser', data }));
        }
        return r.arrayBuffer().then(buf => ({ type: 'audio', buf, mime: ct }));
      })
      .then(result => {
        if (result.type === 'browser') {
          playBrowserTts(result.data.text, result.data.lang || 'de-DE', result.data.rate || 1.0);
        } else {
          playServerAudio(result.buf, result.mime);
        }
      })
      .catch(() => {
        clearTimeout(skipTimer);
        clearTimeout(timeoutTimer);
        ttsStatus.textContent = '(Ton nicht verfügbar)';
        enableAnswering();
      });
  }

  function playServerAudio(arrayBuffer, mime) {
    const blob = new Blob([arrayBuffer], { type: mime });
    const url  = URL.createObjectURL(blob);
    audioObj   = new Audio(url);
    setState('playing');
    ttsIcon.classList.add('playing');
    ttsStatus.textContent = '🔊 Hör genau zu…';

    audioObj.addEventListener('ended', () => {
      URL.revokeObjectURL(url);
      ttsIcon.classList.remove('playing');
      enableAnswering();
    });
    audioObj.addEventListener('error', () => {
      URL.revokeObjectURL(url);
      ttsIcon.classList.remove('playing');
      enableAnswering();
    });
    // 1s Pause vor Wiedergabe damit Audioausgabe bereit ist und Anfang nicht abgeschnitten wird
    setTimeout(() => {
      if (audioObj) audioObj.play().catch(() => {
        ttsIcon.classList.remove('playing');
        enableAnswering();
      });
    }, 1000);
  }

  function playBrowserTts(text, lang, rate) {
    if (!window.speechSynthesis) {
      ttsStatus.textContent = 'Browser-TTS nicht verfügbar.';
      enableAnswering();
      return;
    }
    setState('playing');
    ttsIcon.classList.add('playing');
    ttsStatus.textContent = '🔊 Hör genau zu…';

    window.speechSynthesis.cancel();
    browserUtter      = new SpeechSynthesisUtterance(text);
    browserUtter.lang = lang;
    browserUtter.rate = rate;

    // Deutsche Stimme explizit wählen (verhindert amerikanischen Akzent)
    var voices = window.speechSynthesis.getVoices();
    var deVoice = voices.find(v => v.lang === 'de-DE')
               || voices.find(v => v.lang.startsWith('de'))
               || null;
    if (deVoice) browserUtter.voice = deVoice;

    browserUtter.onend  = () => { ttsIcon.classList.remove('playing'); enableAnswering(); };
    browserUtter.onerror = () => { ttsIcon.classList.remove('playing'); enableAnswering(); };
    window.speechSynthesis.speak(browserUtter);
  }

  function stopAudio() {
    if (audioObj) { audioObj.pause(); audioObj = null; }
    if (window.speechSynthesis) window.speechSynthesis.cancel();
  }

  // ── State-Übergänge ──────────────────────────────────────────────────────

  function setState(s) {
    state = s;
  }

  function enableAnswering() {
    setState('answering');
    ttsStatus.textContent = 'Jetzt tippen!';
    timerStart = Date.now();

    answerInput.disabled = false;
    btnSubmit.disabled   = false;
    btnReplay.disabled   = false;
    btnSlow.disabled     = false;

    // Fokus auf Input
    setTimeout(() => answerInput.focus(), 80);
  }

  // ── Antwort einreichen ───────────────────────────────────────────────────

  function submitAnswer() {
    if (state !== 'answering') return;
    const input = answerInput.value.trim();
    if (input === '') {
      answerInput.focus();
      return;
    }

    setState('submitting');
    answerInput.disabled = true;
    btnSubmit.disabled   = true;
    btnReplay.disabled   = true;
    btnSlow.disabled     = true;
    ttsStatus.textContent = '…';

    const responseTime = timerStart ? (Date.now() - timerStart) : 0;

    fetch('/index.php?_r=%2Flearn%2Ftest%2Fanswer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token:      TEST_DATA.csrfToken,
        item_id:         currentItemId,
        user_input:      input,
        response_time_ms: responseTime,
      }),
    })
      .then(r => r.json())
      .then(data => {
        answeredCount++;
        updateCounter();
        showFeedback(data);
      })
      .catch(() => {
        ttsStatus.textContent = 'Netzwerkfehler. Bitte erneut versuchen.';
        setState('answering');
        answerInput.disabled = false;
        btnSubmit.disabled   = false;
        btnReplay.disabled   = false;
        btnSlow.disabled     = false;
      });
  }

  // ── Feedback anzeigen ────────────────────────────────────────────────────

  function showFeedback(data) {
    setState('feedback');
    stopAudio();
    answerInput.value = '';

    feedbackBox.className = 'feedback-box ' + (data.correct ? 'correct' : 'wrong');
    feedbackEmoji.textContent = data.correct ? '⚔️' : '🛡️';
    feedbackMain.textContent  = data.correct
      ? (TEST_DATA.flavorCorrect || 'Richtig! +XP')
      : 'Leider falsch.';

    if (!data.correct && data.correct_answer) {
      feedbackAnswer.innerHTML = 'Das Wort lautet: <strong>' +
        escHtml(data.correct_answer) + '</strong>';
    } else {
      feedbackAnswer.textContent = '';
    }

    // Regel-Erklärung bei falscher Antwort
    if (data.rule_hint) {
      feedbackRule.textContent = data.rule_hint;
    } else {
      feedbackRule.textContent = '';
    }

    feedbackOverlay.classList.add('visible');
    feedbackOverlay._lastData = data;  // für Overlay-Klick gespeichert

    // Bei Fehler 4s, sonst 2s
    let secs = data.correct ? 2 : 4;
    feedbackCountdown.textContent = secs;
    clearTimeout(feedbackTimer);
    clearInterval(countdownTimer);

    countdownTimer = setInterval(() => {
      secs--;
      feedbackCountdown.textContent = secs;
      if (secs <= 0) clearInterval(countdownTimer);
    }, 1000);

    feedbackTimer = setTimeout(() => advanceAfterFeedback(data), secs * 1000);
  }

  function advanceAfterFeedback(data) {
    feedbackOverlay.classList.remove('visible');
    if (data.section_done) {
      completeSectionRequest();
    } else if (data.next_item_id) {
      currentItemId = data.next_item_id;
      loadAndPlayTts(currentItemId, 'normal');
    }
  }

  // ── Sektion abschließen ──────────────────────────────────────────────────

  function completeSectionRequest() {
    setState('loading');
    ttsStatus.textContent = 'Sektion wird gespeichert…';

    fetch('/index.php?_r=%2Flearn%2Ftest%2Fsection-complete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token: TEST_DATA.csrfToken,
        section_id: currentSectionId,
      }),
    })
      .then(r => r.json())
      .then(data => showSectionTransition(data))
      .catch(() => {
        // Bei Fehler: Seite neu laden — Test bleibt gespeichert
        window.location.reload();
      });
  }

  function showSectionTransition(data) {
    nextSectionData = data;

    // Aktuelles Biom-Icon (aus abgeschlossenem Block)
    const blockIdx = TEST_DATA.blocks.indexOf(TEST_DATA.block);
    const biome    = TEST_DATA.biomes[blockIdx] || {};
    trBiomeIcon.textContent = biome.icon || '⭐';
    trTitle.textContent     = (biome.label || 'Block') + ' abgeschlossen!';

    // Stats: Richtig-Quote
    const total   = totalCount / TEST_DATA.blocks.length;  // grob
    const correct = Math.round(answeredCount - (data.fatigue ? data.fatigue.error_rate / 100 * total : 0));
    trStats.innerHTML = `
      <div class="stat-box">
        <div class="stat-val">${data.fatigue ? (100 - data.fatigue.error_rate) + ' %' : '—'}</div>
        <div class="stat-label">Richtig</div>
      </div>
      <div class="stat-box">
        <div class="stat-val">${data.fatigue ? data.fatigue.avg_replay || 0 : 0}×</div>
        <div class="stat-label">∅ Wiederh.</div>
      </div>`;

    // Ermüdungs-Warnung
    if (data.recommend_pause) {
      trFatigue.style.display = '';
      trFatigue.innerHTML = '⚡ Du machst das super! Eine kurze Pause kann jetzt helfen.';
    } else {
      trFatigue.style.display = 'none';
    }

    // Nächster Block
    if (!data.test_done && data.next_block) {
      const nextIdx  = TEST_DATA.blocks.indexOf(data.next_block);
      const nextBiome = TEST_DATA.biomes[nextIdx] || {};
      trNext.style.display = '';
      trNext.innerHTML = `Als nächstes: ${nextBiome.icon || ''} <strong>${nextBiome.label || 'Block ' + data.next_block}</strong>`
        + ` — ${escHtml(TEST_DATA.blockHints[data.next_block] || '')}`;
    } else if (data.test_done) {
      trNext.style.display = '';
      trNext.innerHTML = '🎉 Du hast alle Blöcke abgeschlossen!';
      btnNextSection.textContent = 'Ergebnis anzeigen ➜';
    } else {
      trNext.style.display = 'none';
    }

    sectionTransition.classList.add('visible');
  }

  // ── Test pausieren ───────────────────────────────────────────────────────

  function pauseAndGoHome() {
    stopAudio();
    fetch('/index.php?_r=%2Flearn%2Ftest%2Fpause', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf_token: TEST_DATA.csrfToken }),
    }).finally(() => {
      window.location.href = '/index.php?_r=%2Flearn';
    });
  }

  // ── Hilfsfunktionen ──────────────────────────────────────────────────────

  function updateCounter() {
    if (itemCounter) {
      itemCounter.textContent =
        'Wort ' + (answeredCount + 1) + ' von ' + totalCount;
    }
    if (progressLabel) {
      progressLabel.textContent = answeredCount + ' / ' + totalCount + ' Wörter';
    }
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Event Listener ───────────────────────────────────────────────────────

  // TTS-Buttons
  btnReplay.addEventListener('click', () => {
    if (state === 'answering') loadAndPlayTts(currentItemId, 'normal');
  });
  btnSlow.addEventListener('click', () => {
    if (state === 'answering') loadAndPlayTts(currentItemId, 'slow');
  });

  // Antwort absenden
  btnSubmit.addEventListener('click', submitAnswer);
  answerInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') submitAnswer();
  });

  // Feedback-Overlay: Klick beschleunigt Weiter
  feedbackOverlay.addEventListener('click', () => {
    if (state !== 'feedback') return;
    clearTimeout(feedbackTimer);
    clearInterval(countdownTimer);
    advanceAfterFeedback(feedbackOverlay._lastData || {});
  });

  // Sektion-Übergang: Weiter
  btnNextSection.addEventListener('click', () => {
    sectionTransition.classList.remove('visible');
    if (nextSectionData && nextSectionData.test_done) {
      window.location.href = '/index.php?_r=%2Flearn%2Ftest%2Fresults&test_id=' +
        (document.querySelector('[data-test-id]')?.dataset.testId || '');
      // Fallback: Seite neu laden, Controller erkennt finished state
      window.location.reload();
      return;
    }
    if (nextSectionData && nextSectionData.next_section_id) {
      currentSectionId = nextSectionData.next_section_id;
      TEST_DATA.block  = nextSectionData.next_block;
      // Seite neu laden — Controller zeigt erste Item der neuen Sektion
      window.location.reload();
    }
  });

  // Sektion-Übergang: Pause
  btnPauseSection.addEventListener('click', pauseAndGoHome);

  // Navbar-Pause
  if (btnPauseNav) {
    btnPauseNav.addEventListener('click', () => {
      if (confirm('Test unterbrechen und später weitermachen?')) {
        pauseAndGoHome();
      }
    });
  }

})();
