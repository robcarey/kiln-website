/* Kiln — "Forge" marketing theme behaviour.
   No build step; plain ES2020. GSAP/ScrollTrigger and Lenis are optional enhancements. */
(() => {
  'use strict';

  const root = document.documentElement;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const saveData = !!(navigator.connection && navigator.connection.saveData);
  const STORE_KEY = 'kiln-scheme';

  root.classList.add('k-js');

  /* ---------------- Theme: flame <-> sun with a circular reveal ---------------- */
  const store = {
    get() { try { return localStorage.getItem(STORE_KEY); } catch (e) { return null; } },
    set(v) { try { localStorage.setItem(STORE_KEY, v); } catch (e) { /* private mode */ } },
  };

  const currentTheme = () => (root.getAttribute('data-bs-theme') === 'light' ? 'light' : 'dark');

  const labelToggles = () => {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    document.querySelectorAll('[data-k-theme-toggle]').forEach((btn) => {
      btn.setAttribute('aria-label', `Switch to ${next} mode`);
      btn.setAttribute('title', `Switch to ${next} mode`);
    });
  };

  const applyTheme = (theme) => {
    root.setAttribute('data-bs-theme', theme);
    store.set(theme);
    labelToggles();
    syncNavTheme();
  };

  const toggleTheme = (event) => {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    if (reduceMotion.matches) { applyTheme(next); return; }

    if (document.startViewTransition) {
      const btn = event.currentTarget.getBoundingClientRect();
      const x = btn.left + btn.width / 2;
      const y = btn.top + btn.height / 2;
      const r = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
      const vt = document.startViewTransition(() => applyTheme(next));
      vt.ready.then(() => {
        root.animate(
          { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${r}px at ${x}px ${y}px)`] },
          { duration: 750, easing: 'cubic-bezier(.65,0,.35,1)', pseudoElement: '::view-transition-new(root)' }
        );
      }).catch(() => {});
      return;
    }

    root.classList.add('k-theme-anim');
    applyTheme(next);
    setTimeout(() => root.classList.remove('k-theme-anim'), 500);
  };

  document.querySelectorAll('[data-k-theme-toggle]').forEach((btn) => btn.addEventListener('click', toggleTheme));

  /* ---------------- Navbar: transparent over the dark hero, glass once scrolled ---------------- */
  const nav = document.querySelector('[data-k-nav]');
  let scrolled = false;
  function syncNavTheme() {
    if (!nav) return;
    // Over the always-dark hero the nav must read as dark; once scrolled it follows the page theme.
    if (scrolled) nav.removeAttribute('data-bs-theme');
    else nav.setAttribute('data-bs-theme', 'dark');
  }
  const onScroll = () => {
    const s = window.scrollY > 24;
    if (s !== scrolled) {
      scrolled = s;
      nav && nav.classList.toggle('is-scrolled', s);
      syncNavTheme();
    }
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  syncNavTheme();
  labelToggles();

  /* ---------------- Download buttons: pick the visitor's platform ---------------- */
  const detectOS = () => {
    const uaData = navigator.userAgentData;
    const p = ((uaData && uaData.platform) || navigator.platform || '').toLowerCase();
    const ua = navigator.userAgent.toLowerCase();
    if (/iphone|ipad|android/.test(ua)) return 'mobile';
    if (p.includes('win') || ua.includes('windows')) return 'win';
    if (p.includes('mac') || ua.includes('mac os')) return 'mac';
    return 'other';
  };
  const os = detectOS();
  root.setAttribute('data-k-os', os);

  document.querySelectorAll('[data-k-download]').forEach((wrap) => {
    const primary = wrap.querySelector('[data-k-dl-primary]');
    const label = wrap.querySelector('[data-k-dl-label]');
    const menu = wrap.querySelector('[data-k-dl-mac]');
    if (!primary) return;
    if (os === 'win') {
      primary.href = wrap.dataset.win;
      label && (label.textContent = 'Download for Windows');
    } else if (os === 'mac' && menu) {
      label && (label.textContent = 'Download for macOS');
      primary.setAttribute('href', '#');
      primary.setAttribute('aria-haspopup', 'true');
      primary.setAttribute('aria-expanded', 'false');
      primary.addEventListener('click', (e) => {
        e.preventDefault();
        const open = menu.hasAttribute('hidden');
        menu.toggleAttribute('hidden', !open);
        primary.setAttribute('aria-expanded', String(open));
      });
      document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target)) { menu.setAttribute('hidden', ''); primary.setAttribute('aria-expanded', 'false'); }
      });
    }
    // other / mobile: keep the default link to the download page
  });

  document.querySelectorAll('[data-k-dl-card]').forEach((card) => {
    if (card.dataset.kDlCard === os) card.classList.add('is-mine');
  });

  /* ---------------- Background videos ---------------- */
  const videos = [...document.querySelectorAll('video[data-k-video]')];
  const stillOnly = () => reduceMotion.matches || saveData;
  const loadVideo = (v) => {
    if (v.dataset.loaded) return;
    v.querySelectorAll('source[data-src]').forEach((s) => { s.src = s.dataset.src; });
    v.load();
    v.dataset.loaded = '1';
  };
  if (videos.length) {
    const vio = new IntersectionObserver((entries) => {
      entries.forEach(({ target: v, isIntersecting }) => {
        if (stillOnly()) { v.pause(); return; }
        if (isIntersecting) {
          loadVideo(v);
          const p = v.play();
          p && p.catch(() => {});
        } else {
          v.pause();
        }
      });
    }, { rootMargin: '200px 0px' });
    videos.forEach((v) => {
      v.muted = true;
      if (stillOnly()) { v.removeAttribute('autoplay'); v.pause(); }
      vio.observe(v);
    });
  }

  /* ---------------- Cursor-tracked glow on cards ---------------- */
  document.addEventListener('pointermove', (e) => {
    const card = e.target.closest && e.target.closest('.k-card');
    if (!card) return;
    const r = card.getBoundingClientRect();
    card.style.setProperty('--mx', `${e.clientX - r.left}px`);
    card.style.setProperty('--my', `${e.clientY - r.top}px`);
  }, { passive: true });

  /* ---------------- In-view triggers (bento micro-animations, reveals) ---------------- */
  const inview = new IntersectionObserver((entries, obs) => {
    entries.forEach(({ target, isIntersecting }) => {
      if (!isIntersecting) return;
      target.classList.add('is-inview', 'is-revealed');
      obs.unobserve(target);
    });
  }, { threshold: 0.2, rootMargin: '0px 0px -8% 0px' });

  document.querySelectorAll('[data-reveal]').forEach((el, i) => {
    if (el.dataset.reveal === 'stagger') {
      [...el.children].forEach((c, j) => { c.setAttribute('data-reveal', ''); c.style.setProperty('--rd', `${j * 0.08}s`); inview.observe(c); });
      el.removeAttribute('data-reveal');
    } else {
      inview.observe(el);
    }
  });
  document.querySelectorAll('[data-inview]').forEach((el) => inview.observe(el));

  /* Server switches in the bento: flip a site between Apache and nginx every few seconds */
  document.querySelectorAll('.kv-switch').forEach((sw, i) => {
    if (reduceMotion.matches) return;
    setTimeout(() => setInterval(() => sw.classList.toggle('is-b'), 3200), 900 * i);
  });

  /* ---------------- Counters ---------------- */
  const countObs = new IntersectionObserver((entries, obs) => {
    entries.forEach(({ target, isIntersecting }) => {
      if (!isIntersecting) return;
      obs.unobserve(target);
      const end = parseFloat(target.dataset.count);
      if (reduceMotion.matches || Number.isNaN(end)) { target.textContent = target.dataset.count; return; }
      const t0 = performance.now();
      const dur = 1400;
      const tick = (t) => {
        const k = Math.min(1, (t - t0) / dur);
        const eased = 1 - Math.pow(1 - k, 4);
        target.textContent = Math.round(end * eased);
        if (k < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
  }, { threshold: 0.6 });
  document.querySelectorAll('[data-count]').forEach((el) => countObs.observe(el));

  /* ---------------- Steps: sticky stage follows the active step ---------------- */
  const steps = [...document.querySelectorAll('[data-k-step]')];
  const panels = [...document.querySelectorAll('[data-k-stage-panel]')];
  if (steps.length) {
    const setActive = (idx) => {
      steps.forEach((s, i) => s.classList.toggle('is-active', i === idx));
      panels.forEach((p, i) => p.classList.toggle('is-active', i === idx));
    };
    setActive(0);
    const stepObs = new IntersectionObserver((entries) => {
      entries.forEach(({ target, isIntersecting }) => {
        if (isIntersecting) setActive(steps.indexOf(target));
      });
    }, { rootMargin: '-45% 0px -45% 0px' });
    steps.forEach((s) => stepObs.observe(s));
  }

  /* ---------------- Interior: table of contents from H2s ---------------- */
  const toc = document.querySelector('[data-k-toc]');
  if (toc) {
    const heads = [...document.querySelectorAll('.k-prose h2')];
    if (heads.length < 2) {
      toc.closest('.k-toc')?.remove();
      document.querySelector('.k-page__grid')?.classList.remove('has-toc');
    } else {
      const slug = (s) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
      const links = heads.map((h) => {
        if (!h.id) h.id = slug(h.textContent);
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = `#${h.id}`;
        const label = h.cloneNode(true);
        label.querySelectorAll('.k-badge').forEach((b) => b.remove());
        a.textContent = label.textContent.trim();
        li.appendChild(a);
        toc.appendChild(li);
        return a;
      });
      const tocObs = new IntersectionObserver((entries) => {
        entries.forEach(({ target, isIntersecting }) => {
          if (!isIntersecting) return;
          links.forEach((a) => a.classList.toggle('is-active', a.hash === `#${target.id}`));
        });
      }, { rootMargin: '0px 0px -70% 0px' });
      heads.forEach((h) => tocObs.observe(h));
    }
  }

  /* ---------------- Footer year ---------------- */
  document.querySelectorAll('[data-k-year]').forEach((el) => { el.textContent = new Date().getFullYear(); });

  /* ---------------- Account: copy a licence key, confirm before removing a seat ---------------- */
  document.querySelectorAll('[data-k-copy]').forEach((btn) => btn.addEventListener('click', async () => {
    const field = document.querySelector(btn.dataset.kCopy);
    if (!field) return;
    const label = btn.querySelector('span');
    try { await navigator.clipboard.writeText(field.value); } catch { field.select(); document.execCommand('copy'); }
    if (label) { label.textContent = 'Copied'; setTimeout(() => { label.textContent = 'Copy'; }, 1600); }
  }));
  document.querySelectorAll('[data-k-confirm]').forEach((btn) => btn.addEventListener('click', (e) => {
    if (!window.confirm(btn.dataset.kConfirm)) e.preventDefault();
  }));
  document.querySelectorAll('.k-code').forEach((el) => el.addEventListener('input', () => {
    el.value = el.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 4);
  }));

  /* ---------------- Smooth scroll + scroll-scrubbed effects ---------------- */
  if (reduceMotion.matches) return;

  let lenis = null;
  if (window.Lenis) {
    lenis = new window.Lenis({ duration: 1.1, smoothWheel: true, anchors: { offset: -80 } });
    window.lenis = lenis;
  }

  if (window.gsap && window.ScrollTrigger) {
    const { gsap, ScrollTrigger } = window;
    gsap.registerPlugin(ScrollTrigger);
    if (lenis) {
      lenis.on('scroll', ScrollTrigger.update);
      gsap.ticker.add((time) => lenis.raf(time * 1000));
      gsap.ticker.lagSmoothing(0);
    }

    // Hero app window straightens up as you scroll into it
    const app = document.querySelector('[data-k-tilt]');
    if (app) {
      gsap.fromTo(app, { rotateX: 16, scale: 0.96 }, {
        rotateX: 0, scale: 1, ease: 'none',
        scrollTrigger: { trigger: app, start: 'top 95%', end: 'top 30%', scrub: 0.6 },
      });
    }

    // Hero copy drifts and fades a touch on the way out
    const heroInner = document.querySelector('.k-hero__inner');
    if (heroInner) {
      gsap.to(heroInner, {
        yPercent: -12, opacity: 0.35, ease: 'none',
        scrollTrigger: { trigger: '.k-hero', start: 'top top', end: '45% top', scrub: true },
      });
    }

    // Giant footer word rises in
    const word = document.querySelector('.k-footer__word');
    if (word) {
      gsap.fromTo(word, { yPercent: 40 }, {
        yPercent: 0, ease: 'none',
        scrollTrigger: { trigger: word, start: 'top bottom', end: 'bottom bottom', scrub: true },
      });
    }
  } else if (lenis) {
    const raf = (t) => { lenis.raf(t); requestAnimationFrame(raf); };
    requestAnimationFrame(raf);
  }
})();
