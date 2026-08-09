/**
 * platformsync_quiz - Player JS (Smartphone)
 *
 * Verbindet sich per SSE und wartet auf Zustandsaenderungen.
 * Spieler:innen geben Nickname ein und waehlen Antworten.
 */
(function () {
  'use strict';

  const app = document.getElementById('quiz-player-app');
  if (!app) return;

  const SESSION_ID = app.dataset.session;
  const STREAM_URL = app.dataset.stream;
  const API_BASE   = app.dataset.api;

  // Persistenter Token pro Browser
  const STORAGE_KEY = 'pq_token_' + SESSION_ID;
  let playerToken = localStorage.getItem(STORAGE_KEY);
  if (!playerToken) {
    playerToken = 'p_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    localStorage.setItem(STORAGE_KEY, playerToken);
  }

  let nickname    = localStorage.getItem('pq_nickname') || '';
  let hasAnswered = false;
  let lastStatus  = null;

  // -------------------------------------------------------------------------
  // RENDER
  // -------------------------------------------------------------------------

  function render(state) {
    // Nickname-Eingabe beim ersten Mal
    if (!nickname && state.status !== 'finished') {
      renderNickname(state);
      return;
    }

    // Status gewechselt -> hasAnswered zuruecksetzen
    if (state.status === 'question' && state.status !== lastStatus) {
      hasAnswered = false;
    }
    lastStatus = state.status;

    switch (state.status) {
      case 'waiting':   renderWaiting(state); break;
      case 'question':  hasAnswered ? renderWaiting(state, true) : renderQuestion(state); break;
      case 'revealed':  renderRevealed(state); break;
      case 'finished':  renderFinished(state); break;
    }
  }

  function renderNickname(state) {
    app.innerHTML = `
      <div class="player-screen player-nickname">
        <div class="player-title">Wie hei&szlig;t du?</div>
        <input id="pq-nickname" class="player-input" type="text"
               placeholder="Dein Name" maxlength="40" autocomplete="off">
        <button class="player-btn player-btn-primary" onclick="window._quizPlayer.joinGame()">
          Mitmachen &rarr;
        </button>
      </div>
    `;
    setTimeout(() => {
      const input = document.getElementById('pq-nickname');
      if (input) input.focus();
    }, 100);
  }

  function renderWaiting(state, answered = false) {
    app.innerHTML = `
      <div class="player-screen player-waiting">
        <div class="player-logo">&#9670;</div>
        <div class="player-title">${answered ? 'Antwort abgeschickt!' : 'Gleich geht\'s los&hellip;'}</div>
        <div class="player-sub">${answered ? 'Warte auf die Aufl&ouml;sung.' : 'Warte auf den Host.'}</div>
        <div class="player-nickname-display">&#128100; ${escHtml(nickname)}</div>
      </div>
    `;
  }

  function renderQuestion(state) {
    app.innerHTML = `
      <div class="player-screen player-question">
        <div class="player-progress">Frage ${state.current_question + 1}</div>
        <div class="player-question-text">${state.question || ""}</div>
        <div class="player-prompt">W&auml;hle deine Antwort:</div>
        ${(state.answers || ['A','B','C','D']).map((ans, i) =>
          `<button class="player-answer-btn player-answer-${['a','b','c','d'][i]}" onclick="window._quizPlayer.answer(${i})">
            <span class="answer-label">${['A','B','C','D'][i]}</span>
            <span class="answer-text">${escHtml(ans)}</span>
          </button>`
        ).join('')}
      </div>
    `;
  }

  function renderRevealed(state) {
    const correct = state.correct_index;
    const labels  = ['A', 'B', 'C'];
    app.innerHTML = `
      <div class="player-screen player-revealed">
        <div class="player-title">Richtige Antwort:</div>
        <div class="player-correct-badge">${correct !== undefined ? labels[correct] : '?'}</div>
        <div class="player-sub">Warte auf die n&auml;chste Frage&hellip;</div>
      </div>
    `;
  }

  function renderFinished(state) {
    app.innerHTML = `
      <div class="player-screen player-finished">
        <div class="player-title">Quiz beendet!</div>
        <div class="player-logo">&#127881;</div>
        <div class="player-sub">Danke f&uuml;r deine Teilnahme,<br><strong>${escHtml(nickname)}</strong>!</div>
        <div class="player-cta">Demokratie lebt von Menschen, die mitmachen.</div>
      </div>
    `;
  }

  // -------------------------------------------------------------------------
  // SSE
  // -------------------------------------------------------------------------

  function connectSSE() {
    const es = new EventSource(STREAM_URL);
    es.onmessage = function (e) {
      try {
        const data = JSON.parse(e.data);
        if (data.type === 'state') render(data);
      } catch (err) {}
    };
    es.onerror = function () {
      setTimeout(connectSSE, 3000);
    };
  }

  // -------------------------------------------------------------------------
  // API
  // -------------------------------------------------------------------------

  async function submitAnswer(answerIndex) {
    if (hasAnswered) return;
    hasAnswered = true;

    await fetch(API_BASE + '/answer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        player_token: playerToken,
        nickname:     nickname,
        answer_index: answerIndex,
      }),
    });
  }

  // -------------------------------------------------------------------------
  // GLOBALE STEUERUNG
  // -------------------------------------------------------------------------

  window._quizPlayer = {
    joinGame: function () {
      const input = document.getElementById('pq-nickname');
      const val   = (input ? input.value : '').trim();
      if (!val) return;
      nickname = val;
      localStorage.setItem('pq_nickname', nickname);
      renderWaiting({ status: 'waiting' });
    },
    answer: function (i) {
      submitAnswer(i);
      renderWaiting({ status: 'question' }, true);
    },
  };

  // -------------------------------------------------------------------------
  // HILFSFUNKTIONEN
  // -------------------------------------------------------------------------

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // -------------------------------------------------------------------------
  // INIT
  // -------------------------------------------------------------------------

  connectSSE();

})();
