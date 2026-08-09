/**
 * platformsync_quiz - Host-Screen JS
 *
 * Verbindet sich per SSE mit dem Stream-Endpunkt.
 * Zeigt Fragen, live Antwort-Balken und Aufloesung.
 * Steuert die Session per API-Aufrufe.
 */
(function () {
  'use strict';

  const app = document.getElementById('quiz-host-app');
  if (!app) return;

  const SESSION_ID  = app.dataset.session;
  const STREAM_URL  = app.dataset.stream;
  const API_BASE    = app.dataset.api;
  const PLAYER_URL  = app.dataset.playerUrl;
  const QUESTIONS   = JSON.parse(app.dataset.questions || '[]');

  let currentState = null;
  let eventSource  = null;

  // -------------------------------------------------------------------------
  // RENDER
  // -------------------------------------------------------------------------

  function render(state) {
    currentState = state;
    app.innerHTML = '';

    switch (state.status) {
      case 'waiting':   renderWaiting(state); break;
      case 'question':  renderQuestion(state); break;
      case 'revealed':  renderRevealed(state); break;
      case 'finished':  renderFinished(state); break;
      default:          renderWaiting(state);
    }
  }

  function renderWaiting(state) {
    const q = document.createElement('div');
    q.className = 'host-waiting';
    q.innerHTML = `
      <div class="host-title">${escHtml(getQuizTitle())}</div>
      <div class="host-subtitle">Warte auf Teilnehmende&hellip;</div>
      <div class="host-player-count">${state.player_count || 0} Teilnehmende verbunden</div>
      <div class="host-qr-area">
        <div class="host-player-url">${PLAYER_URL}</div>
        <div class="host-code">Code: <strong>${SESSION_ID}</strong></div>
      </div>
      <button class="host-btn host-btn-primary" onclick="window._quizHost.startFirst()">
        Quiz starten &rarr;
      </button>
    `;
    app.appendChild(q);
  }

  function renderQuestion(state) {
    const q = QUESTIONS[state.current_question];
    if (!q) return;

    const counts = state.answer_counts || {0: 0, 1: 0, 2: 0};
    const total  = counts[0] + counts[1] + counts[2];

    const el = document.createElement('div');
    el.className = 'host-question';
    el.innerHTML = `
      <div class="host-progress">Frage ${state.current_question + 1} / ${QUESTIONS.length}</div>
      <div class="host-q-category" style="background:${catColor(q.category)}">${escHtml(q.category || '')}</div>
      <div class="host-q-text">${escHtml(q.question)}</div>
      <div class="host-answers">
        ${['A','B','C'].map((letter, i) => `
          <div class="host-answer">
            <div class="host-answer-label"><span class="host-letter">${letter}</span>${escHtml(q.answers[i])}</div>
            <div class="host-bar-wrap">
              <div class="host-bar" style="width:${barPct(counts[i], total)}%"></div>
              <span class="host-bar-count">${counts[i]}</span>
            </div>
          </div>
        `).join('')}
      </div>
      <div class="host-footer">
        <span class="host-answer-total">${total} von ${state.player_count} haben geantwortet</span>
        <button class="host-btn host-btn-primary" onclick="window._quizHost.reveal()">
          Aufl&ouml;sung zeigen
        </button>
      </div>
    `;
    app.appendChild(el);
  }

  function renderRevealed(state) {
    const q = QUESTIONS[state.current_question];
    if (!q) return;

    const counts  = state.answer_counts || {0: 0, 1: 0, 2: 0};
    const total   = counts[0] + counts[1] + counts[2];
    const correct = state.correct_index;
    const isLast  = state.current_question >= QUESTIONS.length - 1;

    const el = document.createElement('div');
    el.className = 'host-question host-revealed';
    el.innerHTML = `
      <div class="host-progress">Frage ${state.current_question + 1} / ${QUESTIONS.length} &ndash; Aufl&ouml;sung</div>
      <div class="host-q-text">${escHtml(q.question)}</div>
      <div class="host-answers">
        ${['A','B','C'].map((letter, i) => `
          <div class="host-answer ${i === correct ? 'host-answer-correct' : 'host-answer-wrong'}">
            <div class="host-answer-label"><span class="host-letter">${letter}</span>${escHtml(q.answers[i])}</div>
            <div class="host-bar-wrap">
              <div class="host-bar" style="width:${barPct(counts[i], total)}%; background:${i === correct ? '#16a085' : '#c0392b'}"></div>
              <span class="host-bar-count">${counts[i]}</span>
            </div>
          </div>
        `).join('')}
      </div>
      <div class="host-explanation">${escHtml(q.explanation || '')}</div>
      <div class="host-footer">
        <button class="host-btn host-btn-primary" onclick="window._quizHost.advance()">
          ${isLast ? 'Ergebnis anzeigen' : 'N&auml;chste Frage &rarr;'}
        </button>
      </div>
    `;
    app.appendChild(el);
  }

  function renderFinished(state) {
    const el = document.createElement('div');
    el.className = 'host-finished';
    el.innerHTML = `
      <div class="host-title">Quiz beendet!</div>
      <div class="host-subtitle">${state.player_count} Teilnehmende</div>
      <div class="host-thanks">Danke f&uuml;r eure Teilnahme.<br>Demokratie lebt von Menschen, die mitmachen.</div>
    `;
    app.appendChild(el);
    if (eventSource) eventSource.close();
  }

  // -------------------------------------------------------------------------
  // SSE
  // -------------------------------------------------------------------------

  function connectSSE() {
    eventSource = new EventSource(STREAM_URL);
    eventSource.onmessage = function (e) {
      try {
        const data = JSON.parse(e.data);
        if (data.type === 'state') render(data);
      } catch (err) {}
    };
    eventSource.onerror = function () {
      setTimeout(connectSSE, 3000); // Reconnect
    };
  }

  // -------------------------------------------------------------------------
  // API
  // -------------------------------------------------------------------------

  async function apiPost(path) {
    await fetch(API_BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json' } });
  }

  // Globale Steuerungsfunktionen (werden von Inline-onclick aufgerufen)
  window._quizHost = {
    startFirst: () => apiPost('/advance'),
    reveal:     () => apiPost('/reveal'),
    advance:    () => apiPost('/advance'),
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

  function barPct(count, total) {
    if (!total) return 0;
    return Math.round((count / total) * 100);
  }

  function getQuizTitle() {
    return document.title.replace(' – Host', '') || 'Demokratie-Quiz';
  }

  const CAT_COLORS = {
    'Grundlagen': '#c0392b',
    'Demokratietheorie': '#8e44ad',
    'Zivilgesellschaft': '#16a085',
    'Bedrohungen': '#e67e22',
    'Partizipation': '#2980b9',
    'Medien & Öffentlichkeit': '#d35400',
    'Lokale Demokratie': '#27ae60',
    'Verfassung': '#2c3e50',
    'Soziale Gerechtigkeit': '#c0392b',
    'Populismus': '#8e44ad',
  };

  function catColor(cat) {
    return CAT_COLORS[cat] || '#2c3e50';
  }

  // -------------------------------------------------------------------------
  // INIT
  // -------------------------------------------------------------------------

  connectSSE();

})();
