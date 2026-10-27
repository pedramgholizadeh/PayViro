/* ============================================================
   Webiro Gateway Docs — client behaviour
   (theme, sidebar, search filter, copy buttons, tabs, progress)
   ============================================================ */
(function () {
  'use strict';

  /* ---------- theme ---------- */
  var root = document.documentElement;
  var saved = null;
  try { saved = localStorage.getItem('wg-theme'); } catch (e) { /* private mode */ }
  if (saved === 'light' || saved === 'dark') {
    root.setAttribute('data-theme', saved);
  } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
    root.setAttribute('data-theme', 'light');
  }

  document.addEventListener('DOMContentLoaded', function () {
    initThemeToggle();
    initSidebar();
    initSearch();
    initCopyButtons();
    initTabs();
    initProgress();
    initReveal();
  });

  /* ---------- theme toggle ---------- */
  function initThemeToggle() {
    document.querySelectorAll('[data-toggle-theme]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('wg-theme', next); } catch (e) { /* ignore */ }
        btn.innerHTML = next === 'light' ? ICONS.moon : ICONS.sun;
      });
      btn.innerHTML = root.getAttribute('data-theme') === 'light' ? ICONS.moon : ICONS.sun;
    });
  }

  /* ---------- mobile sidebar ---------- */
  function initSidebar() {
    var sidebar = document.querySelector('.sidebar');
    var backdrop = document.querySelector('.sidebar-backdrop');
    var openBtn = document.querySelector('[data-open-sidebar]');
    if (!sidebar || !backdrop || !openBtn) return;

    function close() {
      sidebar.classList.remove('open');
      backdrop.classList.remove('show');
      openBtn.setAttribute('aria-expanded', 'false');
    }
    openBtn.addEventListener('click', function () {
      var open = !sidebar.classList.contains('open');
      sidebar.classList.toggle('open', open);
      backdrop.classList.toggle('show', open);
      openBtn.setAttribute('aria-expanded', String(open));
    });
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });
    sidebar.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () { if (window.innerWidth <= 960) close(); });
    });
  }

  /* ---------- search: filter sidebar items ---------- */
  function initSearch() {
    var box = document.querySelector('.search');
    var input = box && box.querySelector('input');
    if (!box || !input) return;

    var items = Array.prototype.slice.call(document.querySelectorAll('.nav-group a.item'));
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      var any = false;
      items.forEach(function (a) {
        var hit = q === '' || a.textContent.toLowerCase().indexOf(q) !== -1;
        a.style.display = hit ? '' : 'none';
        if (hit) any = true;
      });
      box.classList.toggle('filtered', q !== '' && !any);
      document.querySelectorAll('.nav-group').forEach(function (g) {
        var visible = g.querySelector('a.item:not([style*="none"])');
        g.style.display = visible ? '' : 'none';
      });
    });

    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        input.focus();
        input.select();
      }
    });
  }

  /* ---------- copy buttons ---------- */
  function initCopyButtons() {
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var wrap = btn.closest('.code-wrap');
        var code = wrap && wrap.querySelector('pre code');
        if (!code) return;
        var text = code.textContent || '';

        function done() {
          btn.classList.add('copied');
          var label = btn.querySelector('.copy-label');
          if (label) label.textContent = 'کپی شد';
          setTimeout(function () {
            btn.classList.remove('copied');
            if (label) label.textContent = 'کپی';
          }, 1600);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
        } else {
          fallback(text, done);
        }
      });
    });

    function fallback(text, done) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
      document.body.removeChild(ta);
    }
  }

  /* ---------- tabs ---------- */
  function initTabs() {
    document.querySelectorAll('.tabs').forEach(function (tabs) {
      var buttons = tabs.querySelectorAll('.tab-bar button');
      var panels = tabs.querySelectorAll('.tab-panel');
      buttons.forEach(function (btn, i) {
        btn.addEventListener('click', function () {
          buttons.forEach(function (b) { b.classList.remove('active'); });
          panels.forEach(function (p) { p.classList.remove('active'); });
          btn.classList.add('active');
          if (panels[i]) panels[i].classList.add('active');
        });
      });
    });
  }

  /* ---------- scroll progress ---------- */
  function initProgress() {
    var bar = document.getElementById('progress');
    if (!bar) return;
    var ticking = false;
    window.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        var doc = document.documentElement;
        var max = doc.scrollHeight - doc.clientHeight;
        bar.style.width = (max > 0 ? (doc.scrollTop / max) * 100 : 0) + '%';
        ticking = false;
      });
    }, { passive: true });
  }

  /* ---------- reveal ---------- */
  function initReveal() {
    var els = document.querySelectorAll('.reveal');
    if (!('IntersectionObserver' in window) || els.length === 0) {
      els.forEach(function (el) { el.classList.add('in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
    els.forEach(function (el) { io.observe(el); });
  }

  /* ---------- icons ---------- */
  var ICONS = {
    sun: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4"/></svg>',
    moon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>'
  };
})();
