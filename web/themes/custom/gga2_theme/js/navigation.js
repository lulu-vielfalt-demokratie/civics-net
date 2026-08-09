(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.querySelector('.nav-toggle');
    var nav = document.querySelector('.main-nav');

    if (toggle && nav) {
      toggle.addEventListener('click', function() {
        var expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', !expanded);
        nav.classList.toggle('is-open');
      });

      // Schließen bei Klick auf Link
      nav.querySelectorAll('a').forEach(function(link) {
        link.addEventListener('click', function() {
          toggle.setAttribute('aria-expanded', 'false');
          nav.classList.remove('is-open');
        });
      });
    }
  });
})();

// Externe Links + Dateien in neuem Tab öffnen
document.addEventListener('DOMContentLoaded', function() {
  var links = document.querySelectorAll('a[href]');
  links.forEach(function(link) {
    var href = link.getAttribute('href');
    if (!href) return;

    // Dateien immer in neuem Tab
    var fileExtensions = ['.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.zip', '.rar'];
    var isFile = fileExtensions.some(function(ext) {
      return href.toLowerCase().endsWith(ext);
    });

    if (isFile) {
      link.setAttribute('target', '_blank');
      link.setAttribute('rel', 'noopener noreferrer');

      // PDF eingebettet anzeigen
      if (href.toLowerCase().endsWith('.pdf')) {
        link.classList.add('pdf-link');
        link.addEventListener('click', function(e) {
          // Nur einbetten wenn kein Shift/Ctrl gedrückt (normaler Klick)
          if (!e.shiftKey && !e.ctrlKey && !e.metaKey) {
            e.preventDefault();
            var overlay = document.createElement('div');
            overlay.className = 'pdf-overlay';
            overlay.innerHTML =
              '<div class="pdf-overlay-inner">' +
              '<div class="pdf-overlay-header">' +
              '<span>' + (link.textContent || 'PDF') + '</span>' +
              '<a href="' + href + '" target="_blank" class="pdf-download">Download</a>' +
              '<button class="pdf-close" onclick="this.closest(\'.pdf-overlay\').remove()">✕</button>' +
              '</div>' +
              '<iframe src="' + href + '" class="pdf-frame"></iframe>' +
              '</div>';
            document.body.appendChild(overlay);
          }
        });
      }
      return;
    }

    // Externe Links in neuem Tab
    if (href.startsWith('http') || href.startsWith('https')) {
      if (!href.includes('gerechtgehtanders.com') && !href.includes('gga2.civicos.de')) {
        link.setAttribute('target', '_blank');
        link.setAttribute('rel', 'noopener noreferrer');
      }
    }
  });
});
