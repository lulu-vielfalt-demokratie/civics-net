/**
 * @file
 * openhuus Buchungskalender
 * Alle Parameter kommen aus der API — kein hardcodierter Wert im JS.
 */
(function (Drupal) {
  'use strict';

  const BASE = '/api/gaestehaus';
  const MONTHS_DE = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

  let cfg = {};
  let availWd = {};
  let availWe = {};
  let openingDate = null;

  let calYear = new Date().getFullYear();
  let calMonth = new Date().getMonth() + 1;
  let weYear = new Date().getFullYear();
  let weMonth = new Date().getMonth() + 1;

  let curTariff = 'tagesplatz';
  let selRoom = null;
  let selStart = null;
  let selEnd = null;
  let selStep = 0;
  let selSat = null;

  // ── Helpers ──────────────────────────────────────────────
  const pad = n => String(n).padStart(2, '0');
  const addDays = (ds, n) => new Date(new Date(ds).getTime() + n * 86400000).toISOString().slice(0, 10);
  const fmtDate = ds => ds ? new Date(ds + 'T12:00:00').toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';
  const val = id => (document.getElementById(id)?.value?.trim() || '');
  const apiFetch = url => fetch(url, { headers: { Accept: 'application/json' } }).then(r => r.json());
  const apiPost = (url, body) => fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify(body)
  }).then(r => r.json());

  function getPriceForDate(ds, roomId) {
    const m = parseInt(ds.slice(5, 7));
    for (const s of Object.values(cfg.seasons || {})) {
      if (s.months?.includes(m)) return s.room_prices?.[roomId] || 0;
    }
    return 0;
  }

  function showWarn(id, msg) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.hidden = false;
    setTimeout(() => { el.hidden = true; }, 4000);
  }

  // ── Init ─────────────────────────────────────────────────
  async function init() {
    const app = document.getElementById('gh-app');
    if (!app) return;

    cfg = await apiFetch(`${BASE}/config`);
    openingDate = cfg.opening_date || null;

    // Kalender auf Eröffnungsmonat setzen wenn noch nicht erreicht
    if (openingDate) {
      const od = new Date(openingDate);
      const now = new Date();
      if (od > now) {
        calYear = od.getFullYear();
        calMonth = od.getMonth() + 1;
        weYear = od.getFullYear();
        weMonth = od.getMonth() + 1;
      }
    }

    buildRoomGrid();
    updatePriceHints();
    await Promise.all([loadAvailWd(), loadAvailWe()]);
    bindEvents();
  }

  // ── Verfügbarkeit laden ───────────────────────────────────
  async function loadAvailWd() {
    const resp = await apiFetch(`${BASE}/availability?year=${calYear}&month=${calMonth}`);
    availWd = resp.days || {};
    if (resp.opening_date) openingDate = resp.opening_date;
    renderCalWd();
  }

  async function loadAvailWe() {
    const resp = await apiFetch(`${BASE}/availability?year=${weYear}&month=${weMonth}`);
    availWe = resp.days || {};
    if (resp.opening_date) openingDate = resp.opening_date;
    renderCalWe();
  }

  // ── Kalender rendern ──────────────────────────────────────
  function renderCalendar({ year, month, days, elId, mode, onNav, onDay }) {
    const el = document.getElementById(elId);
    if (!el) return;

    const first = new Date(year, month - 1, 1);
    const total = new Date(year, month, 0).getDate();
    let dow = first.getDay();
    dow = dow === 0 ? 6 : dow - 1; // Mo=0

    let html = `<div class="gh-cal">
      <div class="gh-cal__nav">
        <button type="button" class="gh-cal__prev" aria-label="Vorheriger Monat">‹</button>
        <strong class="gh-cal__title">${MONTHS_DE[month - 1]} ${year}</strong>
        <button type="button" class="gh-cal__next" aria-label="Nächster Monat">›</button>
      </div>
      <div class="gh-cal__grid gh-cal__grid--head">
        <span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span>
        <span class="gh-we">Sa</span><span class="gh-we">So</span>
      </div>
      <div class="gh-cal__grid">`;

    for (let i = 0; i < dow; i++) html += `<div class="gh-cal__day gh-cal__day--empty"></div>`;

    for (let day = 1; day <= total; day++) {
      const ds = `${year}-${pad(month)}-${pad(day)}`;
      const d = days[ds] || { status: 'past', bookable: false, mode };
      const status = d.status;
      const bookable = d.bookable;

      let cls = 'gh-cal__day';
      if (status === 'not_open') cls += ' gh-cal__day--not-open';
      else if (status === 'past') cls += ' gh-cal__day--past';
      else if (status === 'blocked' || status === 'we_blocked') cls += ' gh-cal__day--blocked';
      else if (status === 'weekend') cls += ' gh-cal__day--weekend-avail';
      else cls += ' gh-cal__day--available';
      if (!bookable) cls += ' gh-cal__day--disabled';

      // Selektion
      if (mode === 'weekday') {
        if (ds === selStart || ds === selEnd) cls += ' gh-cal__day--selected';
        else if (selStart && selEnd && ds > selStart && ds < selEnd) cls += ' gh-cal__day--inrange';
      } else {
        if (selSat && (ds === selSat || ds === addDays(selSat, 1))) cls += ' gh-cal__day--selected';
      }

      const title = status === 'not_open' && openingDate ? `title="Buchbar ab ${fmtDate(openingDate)}"` : '';
      const price = (status !== 'not_open' && bookable)
        ? (mode === 'weekday' ? (d.tagesplatz_price || '') : (d.price || ''))
        : '';

      html += `<div class="${cls}" ${bookable ? `data-date="${ds}"` : ''} ${title}
        role="${bookable ? 'button' : 'presentation'}" tabindex="${bookable ? 0 : -1}">
        <span class="gh-cal__day-num">${day}</span>
        ${price ? `<span class="gh-cal__day-price">${price}€</span>` : ''}
      </div>`;
    }

    html += `</div></div>`;
    el.innerHTML = html;

    el.querySelector('.gh-cal__prev')?.addEventListener('click', () => onNav('prev'));
    el.querySelector('.gh-cal__next')?.addEventListener('click', () => onNav('next'));
    el.querySelectorAll('.gh-cal__day[data-date]').forEach(cell => {
      cell.addEventListener('click', () => onDay(cell.dataset.date));
      cell.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') onDay(cell.dataset.date); });
    });
  }

  // ── Werktags-Kalender ─────────────────────────────────────
  function renderCalWd() {
    renderCalendar({
      year: calYear, month: calMonth, days: availWd,
      elId: 'gh-cal-wd', mode: 'weekday',
      onNav: dir => {
        if (dir === 'prev') { calMonth--; if (calMonth < 1) { calMonth = 12; calYear--; } }
        else { calMonth++; if (calMonth > 12) { calMonth = 1; calYear++; } }
        loadAvailWd();
      },
      onDay: ds => {
        const d = availWd[ds];
        if (!d || !d.bookable) return;
        if (curTariff === 'woche') {
          if (new Date(ds).getDay() !== 1) { showWarn('gh-warn-wd', 'Wochenpauschale beginnt immer am Montag.'); return; }
          selStart = ds; selEnd = addDays(ds, 5); selStep = 0;
        } else {
          if (selStep === 0 || (selStep === 1 && ds <= selStart)) { selStart = ds; selEnd = null; selStep = 1; }
          else { selEnd = ds; selStep = 0; }
        }
        renderCalWd();
        updateWdForm();
      }
    });
  }

  // ── Wochenend-Kalender ────────────────────────────────────
  function renderCalWe() {
    renderCalendar({
      year: weYear, month: weMonth, days: availWe,
      elId: 'gh-cal-we', mode: 'weekend',
      onNav: dir => {
        if (dir === 'prev') { weMonth--; if (weMonth < 1) { weMonth = 12; weYear--; } }
        else { weMonth++; if (weMonth > 12) { weMonth = 1; weYear++; } }
        loadAvailWe();
      },
      onDay: ds => {
        const d = availWe[ds];
        if (!d || !d.bookable) return;
        if (new Date(ds).getDay() !== 6) return;
        selSat = ds;
        renderCalWe();
        updateWeForm();
      }
    });
  }

  // ── Formulare ─────────────────────────────────────────────
  function buildRoomGrid() {
    const grid = document.getElementById('gh-room-grid');
    if (!grid) return;
    grid.innerHTML = '';
    Object.entries(cfg.rooms || {}).forEach(([id, r]) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'gh-room-btn';
      btn.dataset.roomId = id;
      btn.innerHTML = `<span class="gh-room-btn__num">${id}</span><span class="gh-room-btn__meta">max ${r.max_persons}P</span>`;
      btn.addEventListener('click', () => {
        if (btn.classList.contains('gh-room-btn--taken')) return;
        selRoom = parseInt(id);
        selStart = null; selEnd = null; selStep = 0;
        document.querySelectorAll('.gh-room-btn').forEach(b => b.classList.remove('gh-room-btn--active'));
        btn.classList.add('gh-room-btn--active');
        renderCalWd();
        updateWdForm();
      });
      grid.appendChild(btn);
    });
  }

  function updatePriceHints() {
    const m = new Date().getMonth() + 1;
    const season = Object.values(cfg.seasons || {}).find(s => s.months?.includes(m));
    if (!season) return;
    const tp = document.querySelector('[data-price-key="tagesplatz"]');
    if (tp) tp.textContent = `ab ${season.tagesplatz_price} € / Tag`;
    const minRoom = Math.min(...Object.values(season.room_prices || { 0: 60 }));
    const zd = document.querySelector('[data-price-key="zimmer_desk"]');
    if (zd) zd.textContent = `ab ${minRoom} € / Nacht`;
  }

  function bindEvents() {
    document.querySelectorAll('.gh-tab').forEach(tab => {
      tab.addEventListener('click', () => setMode(tab.dataset.mode));
    });
    document.querySelectorAll('.gh-tariff-card').forEach(card => {
      card.addEventListener('click', () => setTariff(card.dataset.tariff));
    });
    document.getElementById('gh-wd-submit')?.addEventListener('click', submitWeekday);
    document.getElementById('gh-we-submit')?.addEventListener('click', submitWeekend);
    document.getElementById('gh-success-reset')?.addEventListener('click', resetAll);
  }

  function setMode(m) {
    document.querySelectorAll('.gh-tab').forEach(t => t.classList.toggle('gh-tab--active', t.dataset.mode === m));
    document.querySelectorAll('.gh-panel').forEach(p => {
      const active = p.dataset.panel === m;
      p.classList.toggle('gh-panel--active', active);
      p.hidden = !active;
    });
  }

  function setTariff(t) {
    curTariff = t;
    document.querySelectorAll('.gh-tariff-card').forEach(c => c.classList.toggle('gh-tariff-card--active', c.dataset.tariff === t));
    const chooser = document.getElementById('gh-room-chooser');
    if (chooser) chooser.hidden = t === 'tagesplatz';
    if (t === 'tagesplatz') selRoom = null;
    selStart = null; selEnd = null; selStep = 0;
    renderCalWd(); updateWdForm();
  }

  function updateWdForm() {
    const formWrap = document.querySelector('#gh-form-wd .gh-booking-form');
    const hint = document.querySelector('#gh-form-wd .gh-empty-hint');
    if (!formWrap || !hint) return;
    const needEnd = curTariff !== 'woche';
    if (!selStart || (needEnd && !selEnd)) { hint.hidden = false; formWrap.hidden = true; return; }
    hint.hidden = true; formWrap.hidden = false;

    const end = selEnd || selStart;
    const nights = Math.round((new Date(end) - new Date(selStart)) / 86400000);
    let total = 0;
    let cur = selStart;
    while (cur < end) {
      total += curTariff === 'tagesplatz' ? (availWd[cur]?.tagesplatz_price || 0) : getPriceForDate(cur, selRoom);
      cur = addDays(cur, 1);
    }
    let discountHtml = '';
    if (curTariff === 'woche' && nights === (cfg.coworking_tariffs?.woche?.nights || 5) && selRoom) {
      const disc = getPriceForDate(selStart, selRoom);
      total -= disc;
      discountHtml = `<div class="gh-summary__row"><span>Rabatt</span><span class="gh-discount">−1 Nacht</span></div>`;
    }
    const labels = { tagesplatz: 'Tagesplatz', zimmer_desk: 'Zimmer + Desk', woche: 'Wochenpauschale' };
    const unit = curTariff === 'tagesplatz' ? `${nights} Tag${nights > 1 ? 'e' : ''}` : `${nights} Nacht${nights !== 1 ? 'e' : ''}`;
    document.getElementById('gh-summary-wd').innerHTML =
      `<div class="gh-summary__row"><span>${labels[curTariff]}</span><span>${unit}</span></div>` +
      `<div class="gh-summary__row"><span>Von</span><span>${fmtDate(selStart)}</span></div>` +
      (selEnd ? `<div class="gh-summary__row"><span>Bis</span><span>${fmtDate(selEnd)}</span></div>` : '') +
      discountHtml +
      `<div class="gh-summary__row gh-summary__row--total"><span>Gesamtpreis</span><span>${total} €</span></div>`;
  }

  function updateWeForm() {
    const formWrap = document.querySelector('#gh-form-we .gh-booking-form');
    const hint = document.querySelector('#gh-form-we .gh-empty-hint');
    if (!selSat) { if (hint) hint.hidden = false; if (formWrap) formWrap.hidden = true; return; }
    if (hint) hint.hidden = true; if (formWrap) formWrap.hidden = false;
    const price = availWe[selSat]?.price || 0;
    document.getElementById('gh-summary-we').innerHTML =
      `<div class="gh-summary__row"><span>Samstag</span><span>${fmtDate(selSat)}</span></div>` +
      `<div class="gh-summary__row"><span>Sonntag</span><span>${fmtDate(addDays(selSat, 1))}</span></div>` +
      `<div class="gh-summary__row"><span>Inkl.</span><span>Alle Zimmer</span></div>` +
      `<div class="gh-summary__row gh-summary__row--total"><span>Gesamtpreis</span><span>${price} €</span></div>`;
  }

  async function submitWeekday() {
    const fname = val('gh-wd-fname'), lname = val('gh-wd-lname'), email = val('gh-wd-email');
    if (!fname || !lname || !email) { showWarn('gh-warn-wd', 'Bitte Name und E-Mail ausfüllen.'); return; }
    const btn = document.getElementById('gh-wd-submit');
    btn.disabled = true;
    const body = {
      type: curTariff,
      guest_name: `${fname} ${lname}`,
      guest_email: email,
      guest_phone: val('gh-wd-phone'),
      persons: val('gh-wd-persons'),
      notes: val('gh-wd-notes'),
      date_start: selStart,
      date_end: selEnd || selStart,
    };
    if (curTariff !== 'tagesplatz') body.room = selRoom;
    const r = await apiPost(`${BASE}/book`, body);
    if (r.ok) showSuccess(r.id);
    else { showWarn('gh-warn-wd', r.error || 'Fehler beim Buchen.'); btn.disabled = false; }
  }

  async function submitWeekend() {
    const fname = val('gh-we-fname'), lname = val('gh-we-lname'), email = val('gh-we-email');
    if (!fname || !lname || !email) { showWarn('gh-warn-wd', 'Bitte Name und E-Mail ausfüllen.'); return; }
    const btn = document.getElementById('gh-we-submit');
    btn.disabled = true;
    const r = await apiPost(`${BASE}/book`, {
      type: 'weekend',
      saturday: selSat,
      guest_name: `${fname} ${lname}`,
      guest_email: email,
      guest_phone: val('gh-we-phone'),
      persons: val('gh-we-persons'),
      notes: [val('gh-we-anlass'), val('gh-we-notes')].filter(Boolean).join(' — '),
    });
    if (r.ok) showSuccess(r.id);
    else { showWarn('gh-warn-wd', r.error || 'Fehler'); btn.disabled = false; }
  }

  function showSuccess(id) {
    document.querySelectorAll('.gh-panel').forEach(p => { p.hidden = true; p.classList.remove('gh-panel--active'); });
    const s = document.getElementById('gh-success');
    if (s) { s.hidden = false; document.getElementById('gh-success-id').textContent = 'Buchungs-ID: ' + id; }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function resetAll() {
    selStart = null; selEnd = null; selStep = 0; selSat = null; selRoom = null;
    const s = document.getElementById('gh-success');
    if (s) s.hidden = true;
    setMode('weekday');
    renderCalWd(); renderCalWe();
  }

  // ── Drupal-Integration ────────────────────────────────────
  Drupal.behaviors.gaestehausBooking = {
    attach(context) {
      const app = context.querySelector ? context.querySelector('#gh-app') : document.getElementById('gh-app');
      if (app && !app.dataset.ghInit) {
        app.dataset.ghInit = '1';
        init();
      }
    }
  };

})(Drupal);

