/**
 * session.js — Übungseinheit State Machine
 *
 * Zustände: idle → loading_tts → tts_playing → answering → submitting → feedback → (next | complete)
 */
(function () {
  'use strict';

  if (typeof SESSION_DATA === 'undefined') return;

  var data       = SESSION_DATA;
  var items      = data.items;   // [{id, format, order, is_done, is_correct, gap_context}]
  var csrfToken  = data.csrfToken;
  var sessionId  = data.sessionId;
  var format     = data.format;

  // ── DOM refs ──────────────────────────────────────────────────────────

  var ttsBtn        = document.getElementById('tts-btn');
  var ttsBtnIcon    = document.getElementById('tts-icon');
  var ttsBtnLabel   = document.getElementById('tts-label');
  var ttsSlowBtn    = document.getElementById('tts-slow-btn');
  var gapContext    = document.getElementById('gap-context');
  var answerInput   = document.getElementById('answer-input');
  var submitBtn     = document.getElementById('submit-btn');
  var feedbackBox   = document.getElementById('feedback-box');
  var feedbackText  = document.getElementById('feedback-text');
  var feedbackHint  = document.getElementById('feedback-hint');
  var correctShow   = document.getElementById('correct-answer-show');
  var nextBtn       = document.getElementById('next-btn');
  var exerciseArea  = document.getElementById('exercise-area');
  var completeScreen= document.getElementById('complete-screen');
  var progressFill  = document.getElementById('progress-fill');
  var progressText  = document.getElementById('progress-text');
  var progressPct   = document.getElementById('progress-pct');
  var questBanner      = document.getElementById('quest-banner');
  var completeStats    = document.getElementById('complete-stats');
  var mapBtn           = document.getElementById('map-btn');
  var aiFeedbackBox    = document.getElementById('ai-feedback-box');
  var aiFeedbackLoading= document.getElementById('ai-feedback-loading');
  var aiSummary        = document.getElementById('ai-summary');
  var aiEncourage      = document.getElementById('ai-encourage');
  var achievementArea  = document.getElementById('achievement-area');

  // ── State ──────────────────────────────────────────────────────────────

  var state = 'idle';
  var currentItem   = null;
  var attemptNumber = 1;
  var startTime     = null;
  var ttsAudio      = null;
  var ttsSpeech     = null;
  var answeredCount = 0;
  var correctCount  = 0;
  var totalItems    = items.length;

  // ── Init ──────────────────────────────────────────────────────────────

  function init() {
    // Zähle bereits beantwortete Items
    items.forEach(function (item) {
      if (item.is_done) {
        answeredCount++;
        if (item.is_correct) correctCount++;
      }
    });

    // Erstes noch nicht beantwortetes Item laden
    currentItem = findNextItem();
    if (!currentItem) {
      showCompleteScreen({ quest_completed: false, stats: null });
      return;
    }

    loadItem(currentItem);

    // Event Listeners
    ttsBtn.addEventListener('click', function () { playTts(currentItem.id, 'normal'); });
    ttsSlowBtn.addEventListener('click', function () { playTts(currentItem.id, 'slow'); });
    answerInput.addEventListener('input', onInput);
    answerInput.addEventListener('keydown', onKeydown);
    submitBtn.addEventListener('click', onSubmit);
    nextBtn.addEventListener('click', onNext);
    if (mapBtn) mapBtn.addEventListener('click', function () { window.location.href = '/learn/questlog'; });
  }

  // ── Item laden ────────────────────────────────────────────────────────

  function findNextItem() {
    for (var i = 0; i < items.length; i++) {
      if (!items[i].is_done) return items[i];
    }
    return null;
  }

  function loadItem(item) {
    state         = 'idle';
    attemptNumber = 1;
    startTime     = null;

    // Reset Input + Feedback
    answerInput.value    = '';
    answerInput.className = '';
    answerInput.disabled  = true;
    submitBtn.disabled    = true;
    feedbackBox.style.display = 'none';
    feedbackBox.className = 'feedback-box';
    feedbackText.textContent  = '';
    feedbackHint.style.display = 'none';
    correctShow.style.display  = 'none';

    // Gap-Kontext anzeigen
    if (item.format === 'gap' && item.gap_context) {
      gapContext.innerHTML = item.gap_context.replace(
        /_{2,}/g,
        '<span class="gap-blank">_____</span>'
      );
      gapContext.style.display = 'block';
    } else {
      gapContext.style.display = 'none';
    }

    // TTS-Button Label
    ttsBtnLabel.textContent = item.format === 'sentence' ? 'Satz anhören' : 'Wort anhören';

    // Dot markieren
    updateDots(item.id, 'current');

    // TTS automatisch starten
    playTts(item.id, 'normal');
  }

  // ── TTS ───────────────────────────────────────────────────────────────

  function playTts(itemId, speed) {
    stopTts();
    setTtsBtnState('loading');

    var url = '/learn/session/tts?item_id=' + itemId + '&speed=' + speed;
    fetch(url)
      .then(function (res) {
        var ct = res.headers.get('Content-Type') || '';
        if (ct.includes('application/json')) {
          return res.json().then(function (cfg) { playBrowserTts(cfg); });
        } else {
          return res.arrayBuffer().then(function (buf) { playAudioBuffer(buf, res.headers.get('Content-Type') || 'audio/mpeg'); });
        }
      })
      .catch(function () {
        setTtsBtnState('idle');
      });
  }

  function playAudioBuffer(buf, mimeType) {
    var blob = new Blob([buf], { type: mimeType });
    var url  = URL.createObjectURL(blob);
    ttsAudio = new Audio(url);
    ttsAudio.addEventListener('ended',   onTtsEnded);
    ttsAudio.addEventListener('error',   onTtsEnded);
    setTtsBtnState('playing');
    ttsAudio.play();
  }

  function playBrowserTts(cfg) {
    if (!window.speechSynthesis) { onTtsEnded(); return; }
    var utt = new SpeechSynthesisUtterance(cfg.text || '');
    utt.lang  = cfg.lang  || 'de-DE';
    utt.rate  = cfg.rate  || 1.0;
    utt.onend   = onTtsEnded;
    utt.onerror = onTtsEnded;
    ttsSpeech = utt;
    setTtsBtnState('playing');
    window.speechSynthesis.speak(utt);
  }

  function stopTts() {
    if (ttsAudio) { ttsAudio.pause(); ttsAudio = null; }
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    ttsSpeech = null;
  }

  function onTtsEnded() {
    setTtsBtnState('idle');
    // Erst nach TTS-Ende → Input freischalten + Startzeit merken
    if (state === 'idle') {
      state     = 'answering';
      startTime = Date.now();
      answerInput.disabled = false;
      answerInput.focus();
    }
  }

  function setTtsBtnState(s) {
    ttsBtn.className = 'tts-btn';
    if (s === 'loading') {
      ttsBtn.className += ' loading';
      ttsBtnIcon.textContent  = '⌛';
    } else if (s === 'playing') {
      ttsBtn.className += ' playing';
      ttsBtnIcon.textContent  = '🔊';
    } else {
      ttsBtnIcon.textContent  = '🔊';
    }
  }

  // ── Input + Submit ────────────────────────────────────────────────────

  function onInput() {
    submitBtn.disabled = (answerInput.value.trim().length === 0);
  }

  function onKeydown(e) {
    if (e.key === 'Enter' && !submitBtn.disabled) {
      e.preventDefault();
      onSubmit();
    }
  }

  function onSubmit() {
    if (state !== 'answering' && state !== 'second_try') return;
    var answer = answerInput.value.trim();
    if (!answer) return;

    state = 'submitting';
    submitBtn.disabled = true;
    answerInput.disabled = true;

    var responseTimeMs = startTime ? (Date.now() - startTime) : 0;

    fetch('/learn/session/answer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token:      csrfToken,
        item_id:         currentItem.id,
        user_input:      answer,
        response_time_ms: responseTimeMs,
        attempt_number:  attemptNumber,
      }),
    })
      .then(function (res) { return res.json(); })
      .then(function (result) {
        if (result.error) {
          state = 'answering';
          submitBtn.disabled = false;
          answerInput.disabled = false;
          return;
        }
        handleAnswerResult(result);
      })
      .catch(function () {
        state = 'answering';
        submitBtn.disabled = false;
        answerInput.disabled = false;
      });
  }

  // ── Answer Result ─────────────────────────────────────────────────────

  function handleAnswerResult(result) {
    if (result.second_try) {
      // Zweiter Versuch erlaubt
      state         = 'second_try';
      attemptNumber = 2;
      startTime     = Date.now();

      answerInput.className = 'second-try';
      answerInput.disabled  = false;
      answerInput.value     = '';
      answerInput.focus();
      submitBtn.disabled = true;

      showFeedback('second-try', result.feedback, result.hint, null);
      nextBtn.style.display = 'none';
      return;
    }

    // Endgültige Antwort
    state = 'feedback';

    if (result.is_correct) {
      answerInput.className = 'correct';
      correctCount++;
      updateDots(currentItem.id, 'answered-correct');
    } else {
      answerInput.className = 'wrong';
      updateDots(currentItem.id, 'answered-wrong');
    }

    answeredCount++;
    updateProgress();

    // Item als done markieren
    for (var i = 0; i < items.length; i++) {
      if (items[i].id === currentItem.id) {
        items[i].is_done    = true;
        items[i].is_correct = result.is_correct;
        break;
      }
    }

    showFeedback(
      result.is_correct ? 'correct' : 'wrong',
      result.feedback,
      null,
      result.correct_answer
    );
    nextBtn.style.display = '';

    if (result.is_last_item) {
      nextBtn.textContent = 'Abschließen 🎉';
    } else {
      nextBtn.textContent = 'Weiter →';
    }
  }

  function showFeedback(type, text, hint, correctAnswer) {
    feedbackBox.style.display = 'block';
    feedbackBox.className     = type;

    feedbackText.className   = 'feedback-text feedback-' + (type === 'correct' ? 'correct' : 'wrong');
    feedbackText.textContent = text || '';

    if (hint) {
      feedbackHint.textContent    = hint;
      feedbackHint.style.display  = 'block';
    } else {
      feedbackHint.style.display  = 'none';
    }

    if (correctAnswer && type !== 'correct') {
      correctShow.innerHTML      = 'Richtig wäre: <strong>' + escHtml(correctAnswer) + '</strong>';
      correctShow.style.display  = 'block';
    } else {
      correctShow.style.display  = 'none';
    }
  }

  // ── Next ──────────────────────────────────────────────────────────────

  function onNext() {
    if (state !== 'feedback') return;

    var nextItem = findNextItem();
    if (!nextItem) {
      doCompleteSession();
      return;
    }

    currentItem = nextItem;
    loadItem(currentItem);
  }

  // ── Complete Session ──────────────────────────────────────────────────

  function doCompleteSession() {
    state = 'completing';
    submitBtn.disabled = true;
    nextBtn.disabled   = true;

    fetch('/learn/session/complete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token: csrfToken,
        session_id: sessionId,
      }),
    })
      .then(function (res) { return res.json(); })
      .then(function (result) {
        showCompleteScreen(result);
      })
      .catch(function () {
        showCompleteScreen({ quest_completed: false, stats: null });
      });
  }

  function fetchSessionFeedback() {
    if (aiFeedbackLoading) aiFeedbackLoading.style.display = 'flex';

    fetch('/learn/session/feedback', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf_token: csrfToken, session_id: sessionId }),
    })
      .then(function (res) { return res.json(); })
      .then(function (result) {
        if (aiFeedbackLoading) aiFeedbackLoading.style.display = 'none';

        // Achievements anzeigen
        if (result.achievements && result.achievements.length > 0 && achievementArea) {
          result.achievements.forEach(function (ach, i) {
            setTimeout(function () {
              var card = document.createElement('div');
              card.className = 'achievement-card';
              card.innerHTML =
                '<span class="ach-icon">' + escHtml(ach.icon) + '</span>' +
                '<div class="ach-info">' +
                  '<div class="ach-title">' + escHtml(ach.title) + '</div>' +
                  '<div class="ach-desc">' + escHtml(ach.description) + '</div>' +
                '</div>' +
                '<span class="ach-badge">Neu!</span>';
              achievementArea.appendChild(card);
            }, i * 400);
          });
        }

        // KI-Feedback anzeigen
        var fb = result.feedback;
        if (fb && (fb.summary || fb.encouragement) && aiFeedbackBox) {
          if (aiSummary)   aiSummary.textContent   = fb.summary       || '';
          if (aiEncourage) aiEncourage.textContent = fb.encouragement || '';
          aiFeedbackBox.style.display = 'block';
        }
      })
      .catch(function () {
        if (aiFeedbackLoading) aiFeedbackLoading.style.display = 'none';
      });
  }

  function showCompleteScreen(result) {
    exerciseArea.style.display = 'none';
    var dotsEl = document.getElementById('item-dots');
    if (dotsEl) dotsEl.style.display = 'none';
    var progressEl = document.querySelector('.session-progress');
    if (progressEl) progressEl.style.display = 'none';

    completeScreen.style.display = 'block';

    // KI-Feedback asynchron nachladen
    fetchSessionFeedback();

    if (result.quest_completed) {
      questBanner.textContent    = '🏆 Quest abgeschlossen!';
      questBanner.style.display  = 'block';
    }
    if (result.biome_completed) {
      questBanner.textContent    = '🌟 Biom abgeschlossen! Neues Gebiet freigeschaltet!';
      questBanner.style.display  = 'block';
    }

    var s = result.stats || {};
    var total    = parseInt(s.total_items      || totalItems || 0);
    var first    = parseInt(s.correct_first_try || 0);
    var second   = parseInt(s.correct_second_try|| 0);
    var wrong    = parseInt(s.wrong_total       || 0);

    completeStats.innerHTML =
      stat(first + second, 'Richtig') +
      stat(wrong, 'Falsch') +
      stat(total, 'Gesamt');
  }

  function stat(val, lbl) {
    return '<div class="complete-stat"><div class="val">' + val + '</div><div class="lbl">' + lbl + '</div></div>';
  }

  // ── Progress ──────────────────────────────────────────────────────────

  function updateProgress() {
    var pct = totalItems > 0 ? Math.round(answeredCount / totalItems * 100) : 0;
    if (progressFill) progressFill.style.width = pct + '%';
    if (progressText) progressText.textContent = answeredCount + '/' + totalItems + ' Wörter';
    if (progressPct)  progressPct.textContent  = pct + '%';
  }

  // ── Dots ──────────────────────────────────────────────────────────────

  function updateDots(itemId, cls) {
    var dots = document.querySelectorAll('.item-dot');
    dots.forEach(function (dot) {
      if (parseInt(dot.dataset.itemId) === itemId) {
        // Remove old state classes
        dot.classList.remove('answered-correct', 'answered-wrong', 'current');
        dot.classList.add(cls);
      } else if (cls === 'current') {
        // Mark old current as nothing (keep existing answered state)
        dot.classList.remove('current');
      }
    });
  }

  // ── Utils ─────────────────────────────────────────────────────────────

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Start ─────────────────────────────────────────────────────────────
  init();

})();
