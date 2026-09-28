
/* openhuus: Countdown, Navigation, Vormerkliste, Galerie-Großansicht */
(function (Drupal, drupalSettings) {
  'use strict';
  var opening = (drupalSettings.openhuus && drupalSettings.openhuus.opening) || '2026-10-12';
  function pad(n) { return String(n).padStart(2, '0'); }
  function updateCd() {
    var box = document.getElementById('oh-countdown');
    if (!box) { return false; }
    var t = new Date(opening + 'T09:00:00') - new Date();
    if (t <= 0) { box.innerHTML = '<div class="oh-cd__open">' + Drupal.t('openhuus ist geöffnet!') + '</div>'; return false; }
    document.getElementById('cd-d').textContent = pad(Math.floor(t / 86400000));
    document.getElementById('cd-h').textContent = pad(Math.floor(t % 86400000 / 3600000));
    document.getElementById('cd-m').textContent = pad(Math.floor(t % 3600000 / 60000));
    document.getElementById('cd-s').textContent = pad(Math.floor(t % 60000 / 1000));
    return true;
  }
  if (updateCd()) { setInterval(updateCd, 1000); }

  var nav = document.getElementById('oh-nav');
  if (nav) {
    window.addEventListener('scroll', function () { nav.classList.toggle('oh-nav--scrolled', window.scrollY > 60); });
  }

  window.ohSubmitVormerk = function () {
    var n = document.getElementById('oh-vn-name').value.trim();
    var e = document.getElementById('oh-vn-email').value.trim();
    if (!n || !e) { alert(Drupal.t('Bitte Name und E-Mail eintragen.')); return; }
    document.getElementById('oh-vormerk-form').style.display = 'none';
    document.getElementById('oh-vormerk-success').hidden = false;
  };

  var links = Array.prototype.slice.call(document.querySelectorAll('[data-oh-lightbox]'));
  if (links.length) {
    var dlg = document.createElement('dialog');
    dlg.className = 'oh-lightbox';
    dlg.innerHTML = '<button type="button" class="oh-lightbox__close" aria-label="' + Drupal.t('Schließen') + '">×</button>'
      + '<button type="button" class="oh-lightbox__prev" aria-label="' + Drupal.t('Vorheriges Bild') + '">‹</button>'
      + '<img alt="">'
      + '<button type="button" class="oh-lightbox__next" aria-label="' + Drupal.t('Nächstes Bild') + '">›</button>';
    document.body.appendChild(dlg);
    var img = dlg.querySelector('img'), idx = 0;
    var show = function (i) {
      idx = (i + links.length) % links.length;
      img.src = links[idx].href;
      img.alt = links[idx].querySelector('img').alt;
    };
    links.forEach(function (a, i) {
      a.addEventListener('click', function (ev) { ev.preventDefault(); show(i); dlg.showModal(); });
    });
    dlg.querySelector('.oh-lightbox__close').addEventListener('click', function () { dlg.close(); });
    dlg.querySelector('.oh-lightbox__prev').addEventListener('click', function () { show(idx - 1); });
    dlg.querySelector('.oh-lightbox__next').addEventListener('click', function () { show(idx + 1); });
    dlg.addEventListener('click', function (ev) { if (ev.target === dlg) { dlg.close(); } });
    dlg.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowLeft') { show(idx - 1); }
      if (ev.key === 'ArrowRight') { show(idx + 1); }
    });
  }
})(Drupal, drupalSettings);
