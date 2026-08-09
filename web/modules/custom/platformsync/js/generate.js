/**
 * @file
 * PlatformSync — frontend JavaScript for the Drupal generate page.
 */
(function (Drupal, drupalSettings) {
  'use strict';
  const PLATFORM_LIMITS = {
    bluesky: 300, mastodon: 500, threads: 500, instagram: 2200,
    telegram: 4096, whatsapp: 4096, signal: 4096, twitter: 280, linkedin: 3000,
  };
  Drupal.behaviors.platformSyncApp = {
    attach(context) {
      const app = context.querySelector('#platformsync-app');
      if (!app || app.dataset.cpAttached) return;
      app.dataset.cpAttached = '1';
      const textArea   = app.querySelector('#platformsync-text');
      const toneSelect = app.querySelector('#platformsync-tone');
      const submitBtn  = app.querySelector('#platformsync-submit');
      const spinner    = app.querySelector('#platformsync-spinner');
      const errorEl    = app.querySelector('#platformsync-error');
      const resultsEl  = app.querySelector('#platformsync-results');
      const creditsEl  = app.querySelector('#platformsync-credits-remaining strong');
      const settings   = drupalSettings.platformSyncApp || {};

      // Sicherstellen dass Spinner initial versteckt ist
      spinner.style.display = 'none';
      errorEl.style.display = 'none';

      submitBtn.addEventListener('click', async () => {
        const text      = textArea.value.trim();
        const platforms = [...app.querySelectorAll('.platformsync-platform-cb:checked')].map(cb => cb.value);
        const tone      = toneSelect.value;
        if (!text)             { showError(Drupal.t('Please enter a source text.')); return; }
        if (!platforms.length) { showError(Drupal.t('Please select at least one platform.')); return; }
        hideError();
        resultsEl.innerHTML = '';
        setLoading(true);
        try {
          const resp = await fetch(settings.generateUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': settings.csrfToken,
            },
            body: JSON.stringify({ text, platforms, tone }),
          });
          const data = await resp.json();
          if (!resp.ok || data.error) {
            showError(data.error || Drupal.t('Generation failed.'));
            return;
          }
          renderResults(data.posts, platforms);
          if (creditsEl && data.remaining !== undefined) {
            creditsEl.textContent = data.remaining;
          }
        } catch (err) {
          showError(Drupal.t('Network error: @msg', { '@msg': err.message }));
        } finally {
          setLoading(false);
        }
      });

      function renderResults(posts, platforms) {
        platforms.forEach(pid => {
          const text  = posts[pid];
          if (!text) return;
          const limit = PLATFORM_LIMITS[pid] || 0;
          const len   = text.length;
          const pct   = limit ? len / limit : 0;
          const countClass = pct > 1 ? 'over' : pct > 0.85 ? 'warn' : '';
          const card = document.createElement('div');
          card.className = 'platformsync-result-card';
          card.innerHTML = `
            <div class="platformsync-result-card__header">
              <span class="platformsync-result-card__platform">${escHtml(pid)}</span>
              <span class="platformsync-result-card__count ${countClass}">
                ${len}${limit && limit < 4097 ? ' / ' + limit : ''}
              </span>
            </div>
            <div class="platformsync-result-card__body">
              <pre class="platformsync-result-card__text" id="cpr-${escHtml(pid)}">${escHtml(text)}</pre>
              <button class="button platformsync-copy-btn" data-target="cpr-${escHtml(pid)}">
                ${Drupal.t('Copy')}
              </button>
            </div>
          `;
          resultsEl.appendChild(card);
        });
        resultsEl.querySelectorAll('.platformsync-copy-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            const pre = document.getElementById(btn.dataset.target);
            navigator.clipboard.writeText(pre.textContent).then(() => {
              btn.textContent = Drupal.t('Copied!');
              setTimeout(() => { btn.textContent = Drupal.t('Copy'); }, 2000);
            });
          });
        });
      }

      function setLoading(state) {
        submitBtn.disabled    = state;
        spinner.style.display = state ? 'inline-block' : 'none';
      }

      function showError(msg) {
        errorEl.textContent   = msg;
        errorEl.style.display = 'block';
      }

      function hideError() {
        errorEl.style.display = 'none';
      }

      function escHtml(s) {
        return String(s)
          .replace(/&/g, '&amp;').replace(/</g, '&lt;')
          .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      }
    },
  };
}(Drupal, drupalSettings));
