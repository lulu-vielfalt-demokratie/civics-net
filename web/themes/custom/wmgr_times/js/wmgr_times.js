(function ($, Drupal, once) {
  'use strict';

  // Sticky Navigation
  Drupal.behaviors.wmgrNav = {
    attach: function (context) {
      once('wmgr-nav', '#nav-toggle', context).forEach(function (btn) {
        $(btn).on('click', function () {
          $('#main-nav').toggleClass('in');
        });
      });
    }
  };

  // Smooth Scrolling für Anker-Links
  Drupal.behaviors.wmgrScroll = {
    attach: function (context) {
      once('wmgr-scroll', 'a.scroll-link', context).forEach(function (link) {
        $(link).on('click', function (e) {
          var target = $(this).attr('href');
          if ($(target).length) {
            e.preventDefault();
            $('html, body').animate({
              scrollTop: $(target).offset().top - 60
            }, 800);
          }
        });
      });
    }
  };

  // Aktionen-Grid Isotope Filter
  Drupal.behaviors.wmgrIsotope = {
    attach: function (context) {
      once('wmgr-isotope', '#portfolio', context).forEach(function (el) {
        if (typeof $.fn.isotope !== 'undefined') {
          var $grid = $(el).find('.items').isotope({
            itemSelector: '.item',
            layoutMode: 'fitRows'
          });
          $(el).find('.filters a').on('click', function () {
            $(el).find('.filters a').removeClass('active');
            $(this).addClass('active');
            $grid.isotope({ filter: $(this).data('filter') });
            return false;
          });
        }
      });
    }
  };

  // Navbar aktiver Link bei Scroll
  Drupal.behaviors.wmgrScrollSpy = {
    attach: function (context) {
      once('wmgr-scrollspy', 'body', context).forEach(function () {
        $(window).on('scroll', function () {
          var scrollPos = $(window).scrollTop() + 70;
          $('#mainNav li a').each(function () {
            var target = $(this).attr('href');
            if (target && target.startsWith('#') && $(target).length) {
              if ($(target).offset().top <= scrollPos &&
                  $(target).offset().top + $(target).outerHeight() > scrollPos) {
                $('#mainNav li').removeClass('active');
                $(this).parent().addClass('active');
              }
            }
          });
        });
      });
    }
  };

})(jQuery, Drupal, once);
