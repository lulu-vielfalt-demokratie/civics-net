/**
 * @file
 * PlatformSync Video-Editor – Block JavaScript
 *
 * Liest die Tracks-API-URL aus drupalSettings.platformsyncVideoEditor.tracksApiUrl
 * Alle IDs und Klassen tragen das Präfix pve- um Theme-Konflikte zu vermeiden.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.platformsyncVideoEditor = {
    attach(context) {
      // once() stellt sicher, dass der Code nur einmal pro Element läuft
      once('pve-init', '#ps-video-editor', context).forEach(() => {
        new PlatformSyncVideoEditor(
          drupalSettings.platformsyncVideoEditor?.tracksApiUrl ?? null
        );
      });
    },
  };

  class PlatformSyncVideoEditor {

    constructor(tracksApiUrl) {
      this.tracksApiUrl = tracksApiUrl;
      this.vidEl        = null;
      this.duration     = 0;
      this.inPt         = 0;
      this.outPt        = 0;
      this.tlDrag       = null;
      this.tracks       = [];
      this.faces        = [];
      this.manualRegions = [];
      this.metaStripped = false;
      this.pixelSize    = 12;
      this.manualStart_ = null;
      this.syndikalLoaded = false;

      this.FALLBACK_TRACKS = [
        { title: 'Solidarisch',    artist: 'Syndikal AI', genre: 'Ambient',      duration_fmt: '3:12', file: null },
        { title: 'Freiheit 130 BPM', artist: 'Syndikal AI', genre: 'Techno',   duration_fmt: '4:01', file: null },
        { title: 'Zusammen',       artist: 'Syndikal AI', genre: 'Piano',       duration_fmt: '2:44', file: null },
        { title: 'Aufbruch',       artist: 'Syndikal AI', genre: 'Electronic',  duration_fmt: '3:55', file: null },
        { title: 'Wir sind hier',  artist: 'Syndikal AI', genre: 'Folk',        duration_fmt: '2:58', file: null },
        { title: 'Lautlos',        artist: 'Syndikal AI', genre: 'Dark Ambient', duration_fmt: '5:20', file: null },
      ];

      this.bindEvents();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    $ = (id) => document.getElementById(id);

    fmt(s) {
      const m = Math.floor(s / 60);
      return m + ':' + String(Math.floor(s % 60)).padStart(2, '0');
    }

    show(id) { this.$(id)?.classList.remove('pve-hidden'); }
    hide(id) { this.$(id)?.classList.add('pve-hidden'); }

    // ── Navigation ───────────────────────────────────────────────────────────

    goStep(n) {
      [1, 2, 3, 4].forEach(i => {
        const el = this.$('pve-step' + i);
        if (!el) return;
        el.className = 'pve-step' + (i < n ? ' pve-step--done' : i === n ? ' pve-step--active' : '');
      });
      ['load', 'anon', 'audio', 'export'].forEach((name, idx) => {
        const card = this.$('pve-card-' + name);
        if (card) card.classList.toggle('pve-hidden', idx + 1 !== n);
      });
      if (n === 2 && this.vidEl) this.populateMeta();
      if (n === 3) this.loadSyndikalLibrary();
      if (n === 4) this.buildChecklist();
    }

    // ── Events ───────────────────────────────────────────────────────────────

    bindEvents() {
      // Step 1
      const dropzone = this.$('pve-dropzone');
      if (dropzone) {
        dropzone.addEventListener('click', () => this.$('pve-file-input').click());
        dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('over'); });
        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('over'));
        dropzone.addEventListener('drop', (e) => { e.preventDefault(); dropzone.classList.remove('over'); if (e.dataTransfer.files[0]) this.loadVideo(e.dataTransfer.files[0]); });
      }
      this.$('pve-file-input')?.addEventListener('change', (e) => this.loadVideo(e.target.files[0]));

      this.$('pve-btn-play')?.addEventListener('click', () => this.vidEl?.play());
      this.$('pve-btn-pause')?.addEventListener('click', () => this.vidEl?.pause());
      this.$('pve-btn-segment')?.addEventListener('click', () => this.playSegment());
      this.$('pve-btn-to-anon')?.addEventListener('click', () => this.goStep(2));

      // Timeline
      const tl = this.$('pve-timeline');
      if (tl) {
        tl.addEventListener('mousedown', (e) => this.tlDown(e));
        tl.addEventListener('mousemove', (e) => this.tlMove(e));
        tl.addEventListener('mouseup', () => this.tlUp());
      }

      // Step 2
      this.$('pve-btn-detect')?.addEventListener('click', () => this.detectFaces());
      this.$('pve-btn-meta')?.addEventListener('click', () => this.stripMeta());
      this.$('pve-pixel-size')?.addEventListener('input', (e) => this.pixelSizeChanged(e.target.value));
      this.$('pve-btn-back-load')?.addEventListener('click', () => this.goStep(1));
      this.$('pve-btn-to-audio')?.addEventListener('click', () => this.goStep(3));

      // Overlay canvas – manuelles Aufziehen
      const oc = this.$('pve-overlay-canvas');
      if (oc) {
        oc.addEventListener('mousedown', (e) => this.manualStart(e));
        oc.addEventListener('mousemove', (e) => this.manualMove(e));
        oc.addEventListener('mouseup', (e) => this.manualEnd(e));
      }

      // Step 3
      this.$('pve-btn-add-audio')?.addEventListener('click', () => this.$('pve-audio-input').click());
      this.$('pve-audio-input')?.addEventListener('change', (e) => this.addAudioTrack(e.target.files[0]));
      this.$('pve-tab-btn-tracks')?.addEventListener('click', () => this.switchTab('tracks'));
      this.$('pve-tab-btn-syndikal')?.addEventListener('click', () => this.switchTab('syndikal'));
      this.$('pve-btn-back-anon')?.addEventListener('click', () => this.goStep(2));
      this.$('pve-btn-to-export')?.addEventListener('click', () => this.goStep(4));

      // Step 4
      this.$('pve-btn-export')?.addEventListener('click', () => this.doExport());
      this.$('pve-btn-back-audio')?.addEventListener('click', () => this.goStep(3));
    }

    // ── Video laden ───────────────────────────────────────────────────────────

    loadVideo(file) {
      this.vidEl = this.$('pve-vid');
      this.vidEl.src = URL.createObjectURL(file);
      this._videoFile = file;
      window._pveInstance = this;
      this.vidEl.onloadedmetadata = () => {
        this.duration = this.vidEl.duration;
        this.outPt    = this.duration;
        this.hide('pve-dropzone');
        this.show('pve-video-ui');
        this.$('pve-video-ui').classList.remove('pve-hidden');
        this.updateTL();
        this.vidEl.ontimeupdate = () => {
          const pct = (this.vidEl.currentTime / this.duration) * 100;
          if (this.$('pve-tl-head')) this.$('pve-tl-head').style.left = pct + '%';
        };
        this.extractFirstFrame();
      };
    }

    // ── Timeline ──────────────────────────────────────────────────────────────

    updateTL() {
      const ip = (this.inPt / this.duration) * 100;
      const op = (this.outPt / this.duration) * 100;
      this.$('pve-tl-in').style.left  = ip + '%';
      this.$('pve-tl-out').style.left = op + '%';
      this.$('pve-tl-bar').style.left  = ip + '%';
      this.$('pve-tl-bar').style.width = (op - ip) + '%';
      this.$('pve-v-in').textContent  = this.inPt.toFixed(1) + 's';
      this.$('pve-v-out').textContent = this.outPt.toFixed(1) + 's';
      this.$('pve-v-dur').textContent = (this.outPt - this.inPt).toFixed(1) + 's';
      this.$('pve-lbl-s').textContent = this.fmt(this.inPt);
      this.$('pve-lbl-e').textContent = this.fmt(this.outPt);
    }

    getPct(ev) {
      const r = this.$('pve-timeline').getBoundingClientRect();
      return Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
    }

    tlDown(ev) {
      const p = this.getPct(ev);
      const ip = this.inPt / this.duration, op = this.outPt / this.duration;
      if (Math.abs(p - ip) < .03) this.tlDrag = 'in';
      else if (Math.abs(p - op) < .03) this.tlDrag = 'out';
      else if (this.vidEl) this.vidEl.currentTime = p * this.duration;
    }

    tlMove(ev) {
      if (!this.tlDrag || !this.duration) return;
      const p = this.getPct(ev);
      if (this.tlDrag === 'in') this.inPt  = Math.min(p * this.duration, this.outPt - .5);
      else                       this.outPt = Math.max(p * this.duration, this.inPt  + .5);
      this.updateTL();
    }

    tlUp() { this.tlDrag = null; }

    playSegment() {
      if (!this.vidEl) return;
      this.vidEl.currentTime = this.inPt;
      this.vidEl.play();
      const c = setInterval(() => {
        if (this.vidEl.currentTime >= this.outPt) { this.vidEl.pause(); clearInterval(c); }
      }, 100);
    }

    // ── Canvas / Anonymisierung ───────────────────────────────────────────────

    extractFirstFrame() {
      const c  = this.$('pve-preview-canvas');
      const oc = this.$('pve-overlay-canvas');
      const draw = () => {
        c.width  = this.vidEl.videoWidth  || 640;
        c.height = this.vidEl.videoHeight || 360;
        oc.width  = c.width;
        oc.height = c.height;
        c.getContext('2d').drawImage(this.vidEl, 0, 0);
        this.show('pve-canvas-wrap');
        this.$('pve-canvas-wrap').classList.remove('pve-hidden');
        this.$('pve-pixel-row').classList.remove('pve-hidden');
        this.$('pve-manual-hint').classList.remove('pve-hidden');
      };
      if (this.vidEl.readyState >= 2) draw();
      else this.vidEl.addEventListener('loadeddata', draw, { once: true });
    }

    detectFaces() {
      const st  = this.$('pve-detect-status');
      const btn = this.$('pve-btn-detect');
      btn.disabled = true;
      st.className = 'pve-detect-status pve-detect-status--running';
      st.textContent = Drupal.t('Lade face-api.js Modell… (Demo-Modus)');

      // TODO: Echte Implementierung mit face-api.js
      // const faceapi = await import('https://cdn.jsdelivr.net/npm/face-api.js/dist/face-api.min.js');
      // await faceapi.nets.tinyFaceDetector.loadFromUri('/modules/custom/platformsync_video_editor/models');
      // const detections = await faceapi.detectAllFaces(this.vidEl, new faceapi.TinyFaceDetectorOptions());
      // this.faces = detections.map(d => ({x:d.box.x, y:d.box.y, w:d.box.width, h:d.box.height}));

      setTimeout(() => {
        const c = this.$('pve-preview-canvas');
        this.faces = [
          { x: Math.round(c.width * .18), y: Math.round(c.height * .08), w: Math.round(c.width * .15), h: Math.round(c.height * .25) },
          { x: Math.round(c.width * .58), y: Math.round(c.height * .1),  w: Math.round(c.width * .13), h: Math.round(c.height * .22) },
        ];
        st.className = 'pve-detect-status pve-detect-status--done';
        st.textContent = Drupal.t('@count Gesicht(er) erkannt', { '@count': this.faces.length });
        this.$('pve-face-count').textContent = this.faces.length + ' ' + Drupal.t('verpixelt');
        btn.disabled = false;
        this.applyPixelation();
      }, 1600);
    }

    applyPixelation() {
      const c   = this.$('pve-preview-canvas');
      const oc  = this.$('pve-overlay-canvas');
      const ctx = c.getContext('2d');
      ctx.drawImage(this.vidEl, 0, 0);
      [...this.faces, ...this.manualRegions].forEach(f => this.pixelateRegion(ctx, f.x, f.y, f.w, f.h, this.pixelSize));
      const octx = oc.getContext('2d');
      octx.clearRect(0, 0, oc.width, oc.height);
      [...this.faces, ...this.manualRegions].forEach(f => {
        octx.strokeStyle = 'rgba(61,142,240,.6)';
        octx.lineWidth   = 1.5;
        octx.strokeRect(f.x, f.y, f.w, f.h);
      });
    }

    pixelateRegion(ctx, x, y, w, h, ps) {
      const d = ctx.getImageData(x, y, w, h);
      for (let py = 0; py < h; py += ps) {
        for (let px = 0; px < w; px += ps) {
          const off = (py * w + px) * 4;
          const r = d.data[off], g = d.data[off+1], b = d.data[off+2];
          for (let dy = 0; dy < ps && py+dy < h; dy++) {
            for (let dx = 0; dx < ps && px+dx < w; dx++) {
              const o2 = ((py+dy)*w+(px+dx))*4;
              d.data[o2]=r; d.data[o2+1]=g; d.data[o2+2]=b;
            }
          }
        }
      }
      ctx.putImageData(d, x, y);
    }

    pixelSizeChanged(v) {
      this.pixelSize = parseInt(v);
      this.$('pve-pixel-val').textContent = v + ' px';
      if (this.faces.length || this.manualRegions.length) this.applyPixelation();
    }

    getCanvasPos(ev) {
      const r  = this.$('pve-overlay-canvas').getBoundingClientRect();
      const oc = this.$('pve-overlay-canvas');
      return {
        x: Math.round((ev.clientX - r.left) * (oc.width  / r.width)),
        y: Math.round((ev.clientY - r.top)  * (oc.height / r.height)),
      };
    }

    manualStart(ev) { this.manualStart_ = this.getCanvasPos(ev); }

    manualMove(ev) {
      if (!this.manualStart_) return;
      const p  = this.getCanvasPos(ev);
      const oc = this.$('pve-overlay-canvas');
      const octx = oc.getContext('2d');
      octx.clearRect(0, 0, oc.width, oc.height);
      [...this.faces, ...this.manualRegions].forEach(f => {
        octx.strokeStyle = 'rgba(61,142,240,.6)'; octx.lineWidth = 1.5;
        octx.strokeRect(f.x, f.y, f.w, f.h);
      });
      octx.strokeStyle = 'rgba(226,83,64,.8)'; octx.lineWidth = 1.5; octx.setLineDash([4,3]);
      octx.strokeRect(this.manualStart_.x, this.manualStart_.y, p.x - this.manualStart_.x, p.y - this.manualStart_.y);
      octx.setLineDash([]);
    }

    manualEnd(ev) {
      if (!this.manualStart_) return;
      const p = this.getCanvasPos(ev);
      const x = Math.min(this.manualStart_.x, p.x), y = Math.min(this.manualStart_.y, p.y);
      const w = Math.abs(p.x - this.manualStart_.x), h = Math.abs(p.y - this.manualStart_.y);
      if (w > 8 && h > 8) { this.manualRegions.push({x,y,w,h}); this.applyPixelation(); }
      this.manualStart_ = null;
    }

    // ── Metadaten ─────────────────────────────────────────────────────────────

    populateMeta() {
      // TODO: Echte Metadaten via ffmpeg.wasm aus this.vidEl auslesen
      const fields = [
        { key: Drupal.t('GPS-Koordinaten'), val: '53.9119° N, 11.4801° O', sensitive: true },
        { key: Drupal.t('Aufnahmedatum'),   val: '2026-06-04 09:12:38',    sensitive: true },
        { key: Drupal.t('Gerät'),           val: 'Samsung Galaxy S24',      sensitive: true },
        { key: Drupal.t('Software'),        val: 'Camera 13.1.04',          sensitive: false },
        { key: Drupal.t('Codec'),           val: 'H.264 / AAC',             sensitive: false },
        { key: Drupal.t('Auflösung'),       val: '1920×1080',               sensitive: false },
        { key: Drupal.t('Framerate'),       val: '30 fps',                  sensitive: false },
      ];
      this.$('pve-meta-table').innerHTML = fields.map(f =>
        `<tr><td>${f.key}</td><td class="${f.sensitive ? 'removed' : ''}">${
          f.sensitive
            ? `<s>${f.val}</s> <small>(${Drupal.t('wird entfernt')})</small>`
            : f.val
        }</td></tr>`
      ).join('');
      this._metaFields = fields;
    }

    stripMeta() {
      this.metaStripped = true;
      this.$('pve-meta-table').innerHTML = (this._metaFields || []).map(f =>
        `<tr><td>${f.key}</td><td class="${f.sensitive ? 'cleared' : ''}">${
          f.sensitive ? '✓ ' + Drupal.t('entfernt') : f.val
        }</td></tr>`
      ).join('');
      const btn = this.$('pve-btn-meta');
      btn.textContent = '✓ ' + Drupal.t('Erledigt');
      btn.disabled    = true;
    }

    // ── Tonspuren ─────────────────────────────────────────────────────────────

    addAudioTrack(file) {
      this.tracks.push({ id: 'tr' + Date.now(), name: file.name, url: URL.createObjectURL(file), vol: 80 });
      this.renderTracks();
    }

    addSyndikalTrack(t) {
      this.tracks.push({
        id: 'sy' + Date.now(),
        name: (t.title || Drupal.t('Track')) + (t.artist ? ' – ' + t.artist : ''),
        syndikal: true, vol: 70, genre: t.genre, dur: t.duration_fmt,
      });
      this.renderTracks();
      this.switchTab('tracks');
    }

    renderTracks() {
      const l = this.$('pve-track-list');
      if (!this.tracks.length) {
        l.innerHTML = `<div class="pve-empty">${Drupal.t('Noch keine Tonspuren.')}</div>`;
        return;
      }
      l.innerHTML = this.tracks.map(t => `
        <div class="pve-track">
          <span>${t.syndikal ? '◉' : '♪'}</span>
          <div>
            <div class="pve-track-name" title="${t.name}">${t.name}</div>
            ${t.genre ? `<div class="pve-track-meta">${t.genre}${t.dur ? ' · ' + t.dur : ''}</div>` : ''}
          </div>
          <div class="pve-track-vol">
            <label>${Drupal.t('Vol')}</label>
            <input type="range" min="0" max="100" step="1" value="${t.vol}"
              data-track-id="${t.id}">
            <span id="pve-vol-${t.id}">${t.vol}%</span>
          </div>
          <button class="pve-track-rm" data-remove-id="${t.id}" aria-label="${Drupal.t('Entfernen')}">✕</button>
        </div>
      `).join('');

      // Events für dynamische Elemente
      l.querySelectorAll('input[data-track-id]').forEach(el => {
        el.addEventListener('input', (e) => {
          const id  = e.target.dataset.trackId;
          const vol = parseInt(e.target.value);
          const t   = this.tracks.find(x => x.id === id);
          if (t) t.vol = vol;
          const sp = this.$('pve-vol-' + id);
          if (sp) sp.textContent = vol + '%';
        });
      });
      l.querySelectorAll('button[data-remove-id]').forEach(el => {
        el.addEventListener('click', (e) => {
          const id = e.currentTarget.dataset.removeId;
          this.tracks = this.tracks.filter(x => x.id !== id);
          this.renderTracks();
        });
      });
    }

    switchTab(tab) {
      const showTracks   = tab === 'tracks';
      const trackTab     = this.$('pve-tab-tracks');
      const syndikalTab  = this.$('pve-tab-syndikal');
      if (trackTab)    trackTab.classList.toggle('pve-hidden', !showTracks);
      if (syndikalTab) syndikalTab.classList.toggle('pve-hidden', showTracks);
      this.$('pve-tab-btn-tracks')?.classList.toggle('pve-tab--active', showTracks);
      this.$('pve-tab-btn-syndikal')?.classList.toggle('pve-tab--active', !showTracks);
    }

    // ── Syndikal API ──────────────────────────────────────────────────────────

    async loadSyndikalLibrary() {
      if (this.syndikalLoaded) return;
      const status = this.$('pve-syndikal-status');
      const grid   = this.$('pve-syndikal-grid');

      try {
        const res  = await fetch(this.tracksApiUrl, { signal: AbortSignal.timeout(5000) });
        const data = await res.json();
        const list = data.tracks || [];
        if (!list.length) throw new Error('empty');
        if (status) status.style.display = 'none';
        this.renderSyndikalGrid(grid, list);
      } catch {
        if (status) {
          status.textContent = Drupal.t('API nicht erreichbar – Demo-Bibliothek wird angezeigt');
        }
        this.renderSyndikalGrid(grid, this.FALLBACK_TRACKS);
      }
      this.syndikalLoaded = true;
    }

    renderSyndikalGrid(grid, list) {
      if (!grid) return;
      grid.innerHTML = list.map(t => {
        const meta = [t.genre, t.duration_fmt].filter(Boolean).join(' · ') || '–';
        return `<div class="pve-syndikal-item" data-track='${JSON.stringify(t)}'>
          <span>▶</span>
          <div>
            <div class="pve-s-title">${t.title || Drupal.t('(kein Titel)')}${t.artist ? `<br><small>${t.artist}</small>` : ''}</div>
            <div class="pve-s-meta">${meta}</div>
          </div>
        </div>`;
      }).join('');

      grid.querySelectorAll('.pve-syndikal-item').forEach(el => {
        el.addEventListener('click', () => {
          const t = JSON.parse(el.dataset.track);
          this.addSyndikalTrack(t);
        });
      });
    }

    // ── Export ────────────────────────────────────────────────────────────────

    buildChecklist() {
      const items = [
        { label: Drupal.t('Video geladen'),        ok: !!this.vidEl,                                        neutral: !this.vidEl },
        { label: Drupal.t('Getrimmt'),             ok: this.inPt > 0 || this.outPt < this.duration,         neutral: this.inPt === 0 && this.outPt === this.duration },
        { label: Drupal.t('Gesichter verpixelt'),  ok: this.faces.length > 0 || this.manualRegions.length > 0, neutral: false },
        { label: Drupal.t('Metadaten entfernt'),   ok: this.metaStripped,                                   neutral: false },
        { label: Drupal.t('Tonspuren (@n)', { '@n': this.tracks.length }), ok: this.tracks.length > 0,      neutral: this.tracks.length === 0 },
      ];
      this.$('pve-checklist').innerHTML = items.map(i =>
        `<div class="pve-check-item ${i.neutral ? 'no' : i.ok ? 'ok' : 'no'}">
          <span>${i.neutral ? '–' : i.ok ? '✓' : '✗'}</span>
          <span>${i.label}</span>
        </div>`
      ).join('');
    }

    doExport() {
      // Echter Export via PVEExport (ffmpeg-export.js)
      if (window.PVEExport && this._videoFile) {
        window.PVEExport({
          videoFile: this._videoFile,
          inPt: this.inPt,
          outPt: this.outPt,
          audioTracks: this.tracks.filter(t => t.url).map(t => ({ url: t.url, vol: t.vol })),
          audioMode: this.audioMode || 'keep',
          origVol: this.origVol ?? 100,
          stripMeta: this.metaStripped,
          format: document.getElementById('pve-export-format')?.value || 'mp4',
          onProgress: (msg, pct) => { requestAnimationFrame(() => {
            const m = document.getElementById('pve-export-msg');
            const b = document.getElementById('pve-prog-bar');
            if (m) m.textContent = msg;
            if (b) b.style.width = pct + '%'; });
          },
          onDone: (blob, filename) => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename;
            a.textContent = '⬇ ' + filename + ' herunterladen (' + (blob.size/1024/1024).toFixed(1) + ' MB)';
            a.className = 'pve-btn pve-btn--primary';
            const r = document.getElementById('pve-export-result');
            if (r) { r.innerHTML = ''; r.appendChild(a); r.classList.remove('pve-hidden'); }
            a.click();
          },
          onError: (err) => { const m = document.getElementById('pve-export-msg'); if (m) { m.textContent = '✗ ' + err.message; m.style.color = 'var(--pve-red)'; } },
        });
        return;
      }
      this.$('pve-export-progress').classList.remove('pve-hidden');
      const bar  = this.$('pve-prog-bar');
      const msg  = this.$('pve-export-msg');
      const steps = [
        [15,  Drupal.t('Metadaten werden entfernt…')],
        [35,  Drupal.t('Gesichter werden verpixelt…')],
        [60,  Drupal.t('Tonspuren werden gemischt…')],
        [82,  Drupal.t('Video wird enkodiert…')],
        [100, '✓ ' + Drupal.t('Export abgeschlossen')],
      ];
      let i = 0;
      const run = () => {
        if (i >= steps.length) {
          msg.classList.add('pve-export-msg--done');
          this.$('pve-export-result').classList.remove('pve-hidden');
          return;
        }
        if (bar) bar.style.width = steps[i][0] + '%';
        if (msg) msg.textContent = steps[i][1];
        i++;
        setTimeout(run, 750);
      };
      run();
    }
  }

})(Drupal, drupalSettings, once);
