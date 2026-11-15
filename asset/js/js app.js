/*
 * CampusMind shared UI: session handling, role guards, header/footer, icons,
 * toasts, modals, form helpers and the reaction/report widgets used on several pages.
 */
(function (global) {
  'use strict';
  const CM = global.CM;
  const api = CM.api;

  /* ---------- icons (stroke icons, 24px grid) ---------- */
  const ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    clipboard: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/>',
    book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
    message: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    forum: '<path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
    lock: '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
    leaf: '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 19 2c1 2 2 4.2 2 8 0 5.5-4.8 10-10 10z"/><path d="M2 21c0-3 1.9-5.4 5.1-6"/>',
    phone: '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
    mail: '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
    map: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    menu: '<path d="M3 12h18M3 6h18M3 18h18"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    'chevron-down': '<path d="m6 9 6 6 6-6"/>',
    'chevron-left': '<path d="m15 18-6-6 6-6"/>',
    'chevron-right': '<path d="m9 18 6-6-6-6"/>',
    'arrow-right': '<path d="M5 12h14M12 5l7 7-7 7"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    'check-circle': '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    'x-circle': '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
    alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    flag: '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    trash: '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    video: '<path d="m22 8-6 4 6 4V8z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
    chart: '<path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/>',
    inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
    pin: '<path d="M12 17v5M9 10.8V5h6v5.8l3 3.2v2H6v-2z"/><path d="M8 3h8"/>',
    'eye-off': '<path d="M17.9 17.9A10 10 0 0 1 12 20c-7 0-10-8-10-8a18 18 0 0 1 5.1-5.9M9.9 4.2A9 9 0 0 1 12 4c7 0 10 8 10 8a18 18 0 0 1-2.2 3.2M14.1 14.1a3 3 0 1 1-4.2-4.2M2 2l20 20"/>',
    sparkle: '<path d="M12 3l1.9 5.8L20 10.7l-6.1 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9z"/>',
    smile: '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
    award: '<circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/>',
    edit: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    send: '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    sliders: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    target: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>'
  };
  function icon(name, cls) {
    return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || ICONS.info) + '</svg>';
  }
  const LOGO = '<svg class="brand-mark" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M30 42v-6h4a3 3 0 0 0 3-3v-5l3.5-1.5c.7-.3.9-1.2.4-1.8L37 20c-.6-8-7-14-15-14C13.7 6 7 12.7 7 21c0 4.7 2.2 8.9 5.6 11.6V42"/>' +
    '<path d="M22 31c-4.5-2-6-6.5-4.5-11.5 4.8-.2 9.2 2.3 9.8 7.5"/><path d="M22 31c.5-4.6 2.6-8.6 6.5-10.5 1.3 4.8-.7 9.3-6.5 10.5z"/></svg>';

  /* ---------- formatting ---------- */
  function esc(s) {
    return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function parseDate(dateStr) { return new Date(dateStr + 'T12:00:00'); }
  function fmtDate(dateStr) { const d = parseDate(dateStr); return DOW[d.getDay()] + ', ' + d.getDate() + ' ' + MON[d.getMonth()]; }
  function fmtDateTime(iso) { const d = new Date(iso); return d.getDate() + ' ' + MON[d.getMonth()] + ' ' + d.getFullYear() + ', ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); }
  function timeAgo(iso) {
    const s = Math.round((Date.now() - new Date(iso)) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    if (s < 86400 * 7) return Math.floor(s / 86400) + 'd ago';
    const d = new Date(iso); return d.getDate() + ' ' + MON[d.getMonth()];
  }
  function initials(name) { return (name || '?').replace(/^(Dr|Mr|Ms|Mrs)\.?\s+/i, '').split(/\s+/).map(p => p[0]).slice(0, 2).join('').toUpperCase(); }
  function avatar(user, cls) {
    if (!user) return '<span class="avatar ' + (cls || '') + '">' + icon('user') + '</span>';
    if (user.photo) return '<img class="avatar ' + (cls || '') + '" src="' + esc(user.photo) + '" alt="" onerror="this.replaceWith(Object.assign(document.createElement(\'span\'),{className:\'avatar ' + (cls || '') + '\',textContent:\'' + initials(user.name) + '\'}))">';
    return '<span class="avatar ' + (cls || '') + '">' + esc(initials(user.name)) + '</span>';
  }
  function statusBadge(status) {
    const map = { confirmed: '', completed: 'info', cancelled: 'grey', 'no-show': 'warn', open: 'warn', new: 'warn', reviewed: 'info', assigned: '', closed: 'grey', published: '', pending: 'warn', rejected: 'danger', hidden: 'grey', resolved: '', dismissed: 'grey', urgent: 'danger', soon: 'warn', routine: 'grey', visible: '' };
    return '<span class="badge ' + (map[status] || '') + '">' + esc(status) + '</span>';
  }
  const qs = name => new URLSearchParams(location.search).get(name);

  /* ---------- session ---------- */
  // The login session is a secure PHP session cookie; the server tells us who is signed in.
  const session = {
    user() { return CM.db.me; }
  };

  // contact details are edited by the administrator (Admin → System)
  const setting = key => CM.db.settings[key] || '';
  const telHref = num => 'tel:' + String(num).replace(/[^+\d]/g, '');
  const multiline = text => esc(text).replace(/\n/g, '<br>');

  // Only same-site page links are accepted for ?next= so the login page cannot be used as an open redirect.
  function safeNext(next) {
    return next && /^[a-z-]+\.html(\?[\w=&%-]*)?(#[\w-]*)?$/.test(next) ? next : null;
  }

  /* ---------- header & footer ---------- */
  const SERVICES = [
    ['book.html', 'calendar', 'Book a Session'],
    ['intake.html', 'clipboard', 'Confidential Intake'],
    ['stories.html', 'book', 'Peer Stories'],
    ['forum.html', 'forum', 'Community Forum'],
    ['messages.html', 'message', 'Message a Counselor']
  ];

  function roleLinks(user) {
    const links = [[api.homeFor(user), 'grid', 'My Dashboard']];
    if (user.role === 'student') links.push(['book.html', 'calendar', 'Book a Session'], ['intake.html', 'clipboard', 'Intake Form']);
    if (user.role === 'admin') links.push(['counselor.html', 'calendar', 'Counselor Workspace'], ['moderation.html', 'shield', 'Moderation']);
    links.push(['messages.html', 'message', 'Messages']);
    return links;
  }

  function renderHeader(page, user) {
    const el = document.getElementById('app-header');
    if (!el) return;
    const nav = [['index.html', 'home', 'Home'], ['about.html', 'about', 'About Us']];
    const navLink = ([href, key, label]) => '<a href="' + href + '" class="' + (page === key ? 'active' : '') + '">' + label + '</a>';
    let actions = '';
    if (user) {
      const notes = api.notificationsFor(user.id);
      const unread = notes.filter(n => !n.read).length;
      actions =
        '<div class="dd" id="notif-dd"><button class="icon-btn" aria-label="Notifications" data-dd>' + icon('bell') + (unread ? '<span class="dot">' + unread + '</span>' : '') + '</button>' +
        '<div class="dd-menu right"><div class="dd-head"><b>Notifications</b></div><div class="notif-list">' +
        (notes.length ? notes.slice(0, 12).map(n => '<a href="' + esc(safeNext(n.link) || '#') + '" class="' + (n.read ? '' : 'unread') + '">' + esc(n.text) + '<time>' + timeAgo(n.createdAt) + '</time></a>').join('') : '<p class="muted small" style="padding:10px">You are all caught up.</p>') +
        '</div></div></div>' +
        '<div class="dd"><button class="icon-btn" aria-label="Account menu" data-dd style="overflow:hidden;padding:0">' + avatar(user) + '</button>' +
        '<div class="dd-menu right"><div class="dd-head"><b>' + esc(user.name) + '</b><div class="small muted">' + esc(user.email) + '</div><span class="badge" style="margin-top:6px">' + esc(user.role) + '</span></div>' +
        roleLinks(user).map(([h, i, l]) => '<a href="' + h + '">' + icon(i) + l + '</a>').join('') +
        '<a href="#" data-logout>' + icon('logout') + 'Log out</a></div></div>' +
        (user.role === 'student' ? '<a class="btn btn-primary btn-book" href="book.html">Book a Session</a>' : '');
    } else {
      actions = '<a class="btn btn-ghost" href="login.html">Log in</a><a class="btn btn-primary btn-book" href="book.html">Book a Session</a>';
    }

    el.innerHTML =
      '<a class="skip-link" href="#main">Skip to content</a>' +
      '<div class="topbar"><div class="wrap"><span class="tb-item">' + icon('sparkle') + ' ' + esc(setting('topbarMessage')) + '</span>' +
      '<span class="tb-items"><span class="tb-item hide-sm">' + icon('map') + ' ' + esc(setting('addressShort')) + '</span>' +
      '<a class="tb-item" href="' + esc(telHref(setting('phone'))) + '">' + icon('phone') + ' ' + esc(setting('phone')) + '</a></span></div></div>' +
      '<header class="site-header"><div class="wrap">' +
      '<a class="brand" href="index.html" aria-label="CampusMind home">' + LOGO + '<span><span class="brand-name">CampusMind</span><span class="brand-tag">Counseling &amp; Peer Support</span></span></a>' +
      '<nav class="nav" id="main-nav" aria-label="Main">' +
      '<button class="icon-btn nav-close menu-toggle" aria-label="Close menu" data-nav-close>' + icon('x') + '</button>' +
      nav.map(navLink).join('') +
      '<div class="dd"><button data-dd class="' + (['book', 'intake', 'messages'].includes(page) ? 'active' : '') + '">Services ' + icon('chevron-down') + '</button>' +
      '<div class="dd-menu">' + SERVICES.map(([h, i, l]) => '<a href="' + h + '">' + icon(i) + l + '</a>').join('') + '</div></div>' +
      navLink(['stories.html', 'stories', 'Stories']) + navLink(['forum.html', 'forum', 'Forum']) + navLink(['about.html#contact', 'contact', 'Contact']) +
      '</nav>' +
      '<div class="header-actions">' + actions + '<button class="icon-btn menu-toggle" aria-label="Open menu" data-nav-open>' + icon('menu') + '</button></div>' +
      '</div></header>';

    // fix active underline for nav items rendered by navLink with page keys
    el.querySelectorAll('.nav > a').forEach(a => {
      const href = a.getAttribute('href');
      const key = href === 'index.html' ? 'home' : href.replace('.html', '').replace('#contact', '');
      a.classList.toggle('active', key === page || (page === 'thread' && key === 'forum'));
    });

    if (el.dataset.bound) return; // handlers below survive re-renders of the header
    el.dataset.bound = '1';
    el.addEventListener('click', e => {
      const dd = e.target.closest('[data-dd]');
      if (dd) {
        e.preventDefault();
        const box = dd.parentElement;
        const opening = !box.classList.contains('open');
        document.querySelectorAll('.dd.open').forEach(d => d.classList.remove('open'));
        box.classList.toggle('open', opening);
        if (opening && box.id === 'notif-dd' && session.user()) {
          if (dd.querySelector('.dot')) api.markNotificationsRead().catch(() => {});
          const dot = dd.querySelector('.dot'); if (dot) dot.remove();
        }
        return;
      }
      if (e.target.closest('[data-logout]')) {
        e.preventDefault();
        api.logout().catch(() => {}).finally(() => { location.href = 'index.html'; });
      }
      if (e.target.closest('[data-nav-open]')) document.getElementById('main-nav').classList.add('open');
      if (e.target.closest('[data-nav-close]')) document.getElementById('main-nav').classList.remove('open');
    });
    document.addEventListener('click', e => { if (!e.target.closest('.dd')) document.querySelectorAll('.dd.open').forEach(d => d.classList.remove('open')); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.dd.open').forEach(d => d.classList.remove('open')); });
  }

  function renderFooter() {
    const el = document.getElementById('app-footer');
    if (!el) return;
    const emergency = setting('emergency').split(',').map(n => n.trim()).filter(Boolean)
      .map(n => '<a href="' + esc(telHref(n)) + '">' + esc(n) + '</a>');
    el.innerHTML =
      '<div class="crisis" role="region" aria-label="Crisis support"><div class="wrap">' + icon('alert') +
      '<span><b>In crisis or feeling unsafe right now?</b> Call emergency services on ' + (emergency.length > 1 ? emergency.slice(0, -1).join(', ') + ' or ' + emergency[emergency.length - 1] : emergency.join('')) +
      ', or walk into the MUST counseling centre. You do not need an appointment in an emergency.</span></div></div>' +
      '<footer class="site-footer"><div class="wrap"><div class="footer-grid">' +
      '<div><a class="brand" href="index.html">' + LOGO + '<span><span class="brand-name">CampusMind</span><span class="brand-tag">Counseling &amp; Peer Support</span></span></a>' +
      '<p>An integrated university counseling and peer support platform for Mbarara University of Science and Technology students.</p></div>' +
      '<div><h4>Quick Links</h4><ul><li><a href="index.html">Home</a></li><li><a href="about.html">About Us</a></li><li><a href="stories.html">Peer Stories</a></li><li><a href="forum.html">Community Forum</a></li><li><a href="login.html">Log in / Register</a></li></ul></div>' +
      '<div><h4>Services</h4><ul>' + SERVICES.map(([h, , l]) => '<li><a href="' + h + '">' + l + '</a></li>').join('') + '</ul></div>' +
      '<div id="footer-contact"><h4>Contact Us</h4>' +
      '<div class="contact-line">' + icon('map') + '<span>' + multiline(setting('address')) + '</span></div>' +
      '<div class="contact-line">' + icon('phone') + '<a href="' + esc(telHref(setting('phone'))) + '">' + esc(setting('phone')) + '</a></div>' +
      '<div class="contact-line">' + icon('mail') + '<a href="mailto:' + esc(setting('email')) + '">' + esc(setting('email')) + '</a></div>' +
      '<div class="contact-line">' + icon('clock') + '<span>' + esc(setting('hoursWeekdays')) + '<br>' + esc(setting('hoursSaturday')) + '</span></div></div>' +
      '</div><div class="footer-bottom"><span>© ' + new Date().getFullYear() + ' CampusMind · MUST Software Engineering Project. All rights reserved.</span>' +
      '<span><a href="about.html#privacy">Privacy &amp; Confidentiality</a>' + (CM.db.app.live ? '' : ' · <a href="tests.html">System Tests</a>') + '</span></div></div></footer>' +
      '<div class="toasts" id="toasts" aria-live="polite"></div>';
  }

  /* ---------- page bootstrap with role-based access ---------- */
  let currentPage = '';
  // re-draws header and footer from the latest snapshot, e.g. after contact details change
  function renderChrome() {
    renderHeader(currentPage, session.user());
    renderFooter();
  }

  async function page(opts) {
    const o = opts || {};
    try {
      await api.load();
    } catch (err) {
      const main = document.getElementById('main');
      if (main) main.innerHTML = '<div class="wrap"><div class="denied">' + icon('alert') + '<h2>Server not running</h2><p class="muted">' + esc(err.message) +
        '</p><p>Double-click <b>start-server.bat</b> in the CampusMind folder, then open <a href="http://localhost:8000">http://localhost:8000</a>.</p></div></div>';
      return null;
    }
    const user = session.user();
    currentPage = o.page || document.body.dataset.page;
    renderHeader(currentPage, user);
    renderFooter();
    hydrateIcons(document);
    if (o.roles) {
      const main = document.getElementById('main');
      if (!user) {
        location.replace('login.html?next=' + encodeURIComponent(location.pathname.split('/').pop() + location.search + location.hash));
        if (main) main.innerHTML = '';
        return null;
      }
      if (!o.roles.includes(user.role)) {
        if (main) main.innerHTML = '<div class="wrap"><div class="denied">' + icon('lock') + '<h2>Access restricted</h2><p class="muted">This area is for ' + o.roles.join(' / ') + ' accounts. You are signed in as a <b>' + esc(user.role) + '</b>.</p><a class="btn btn-primary" href="' + api.homeFor(user) + '">Go to my dashboard</a></div></div>';
        return null;
      }
    }
    return user || null;
  }

  /* ---------- toasts & modals ---------- */
  function toast(msg, type) {
    const box = document.getElementById('toasts');
    if (!box) return alert(msg);
    const t = document.createElement('div');
    t.className = 'toast ' + (type || '');
    t.innerHTML = icon(type === 'error' ? 'alert' : 'check-circle') + '<span>' + esc(msg) + '</span>';
    box.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 300); }, 3800);
  }

  function modal(o) {
    const back = document.createElement('div');
    back.className = 'modal-backdrop';
    back.innerHTML = '<div class="modal" role="dialog" aria-modal="true" aria-labelledby="mdl-t" style="' + (o.wide ? 'max-width:720px' : '') + '"><div class="modal-head"><h3 id="mdl-t">' + esc(o.title) + '</h3><button class="modal-close" aria-label="Close">' + icon('x') + '</button></div><div class="modal-body">' + o.body + '</div></div>';
    const prev = document.activeElement;
    const close = () => { back.remove(); document.removeEventListener('keydown', onKey); if (prev && prev.focus) prev.focus(); };
    const onKey = e => { if (e.key === 'Escape') close(); };
    back.addEventListener('click', e => { if (e.target === back || e.target.closest('.modal-close') || e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(back);
    const first = back.querySelector('.modal-body input, .modal-body textarea, .modal-body select, .modal-body button');
    if (first) first.focus();
    if (o.onMount) o.onMount(back.querySelector('.modal'), close);
    return close;
  }

  function confirmBox(message, okLabel, danger) {
    return new Promise(resolve => {
      let answered = false;
      modal({
        title: 'Please confirm',
        body: '<p>' + esc(message) + '</p><div class="form-actions"><button class="btn btn-outline" data-close>Keep it</button><button class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" data-ok>' + esc(okLabel || 'Confirm') + '</button></div>',
        onMount(m, close) {
          m.querySelector('[data-ok]').addEventListener('click', () => { answered = true; close(); resolve(true); });
          const obs = new MutationObserver(() => { if (!document.body.contains(m)) { obs.disconnect(); if (!answered) resolve(false); } });
          obs.observe(document.body, { childList: true });
        }
      });
    });
  }

  /* ---------- forms ---------- */
  function formData(form) {
    const out = {};
    Array.from(form.elements).forEach(el => {
      if (!el.name || el.disabled) return;
      if (el.type === 'checkbox') {
        if (form.querySelectorAll('[name="' + el.name + '"]').length > 1) { out[el.name] = out[el.name] || []; if (el.checked) out[el.name].push(el.value); }
        else out[el.name] = el.checked;
      } else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
      else out[el.name] = el.value;
    });
    return out;
  }
  function clearErrors(form) {
    form.querySelectorAll('.field.invalid').forEach(f => f.classList.remove('invalid'));
    const a = form.querySelector('.form-alert'); if (a) a.remove();
  }
  function showErrors(form, err) {
    clearErrors(form);
    const fields = (err && err.fields) || {};
    Object.keys(fields).forEach(name => {
      const f = form.querySelector('[data-field="' + name + '"]');
      if (!f) return;
      f.classList.add('invalid');
      let e = f.querySelector('.err');
      if (!e) { e = document.createElement('div'); e.className = 'err'; f.appendChild(e); }
      e.textContent = fields[name];
    });
    const alertBox = document.createElement('div');
    alertBox.className = 'alert danger form-alert';
    alertBox.setAttribute('role', 'alert');
    alertBox.innerHTML = icon('alert') + '<span>' + esc(err.message || 'Something went wrong.') + '</span>';
    form.prepend(alertBox);
    const firstBad = form.querySelector('.field.invalid input, .field.invalid textarea, .field.invalid select');
    (firstBad || alertBox).scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (firstBad) firstBad.focus({ preventScroll: true });
  }

  // Runs an async submit handler with the submit button disabled, so a form can't be sent twice.
  function onSubmit(form, handler) {
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = e.submitter || form.querySelector('button:not([type=button]):not([type=reset])');
      if (btn) btn.disabled = true;
      try { await handler(form, e); } finally { if (btn) btn.disabled = false; }
    });
  }

  /* ---------- tabs ---------- */
  function tabs(root, onChange) {
    const buttons = root.querySelectorAll('.tabs [data-tab]');
    function show(name) {
      buttons.forEach(b => b.classList.toggle('active', b.dataset.tab === name));
      root.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + name));
      if (onChange) onChange(name);
    }
    buttons.forEach(b => b.addEventListener('click', () => { show(b.dataset.tab); history.replaceState(null, '', '#' + b.dataset.tab); }));
    const initial = location.hash.slice(1);
    show(Array.from(buttons).some(b => b.dataset.tab === initial) ? initial : buttons[0].dataset.tab);
    return show;
  }

  /* ---------- reactions & reports ---------- */
  const REACTIONS = [['support', '💚', 'Support'], ['relate', '🤝', 'I relate'], ['thanks', '🙏', 'Thank you']];
  function reactionsHTML(type, id, user, withReport) {
    const c = api.reactionCounts(type, id, user && user.id);
    return REACTIONS.map(([k, e, l]) => '<button class="react ' + (c.mine.includes(k) ? 'on' : '') + '" data-react="' + k + '" data-type="' + type + '" data-id="' + id + '" aria-pressed="' + c.mine.includes(k) + '" title="' + l + '">' + e + ' <span>' + (c[k] || 0) + '</span></button>').join('') +
      (withReport !== false ? '<button class="react report" data-report="' + type + '" data-id="' + id + '">' + icon('flag') + ' Report</button>' : '');
  }
  function openReport(user, type, id, done) {
    if (!user) { location.href = 'login.html?next=' + encodeURIComponent(location.pathname.split('/').pop() + location.search); return; }
    modal({
      title: 'Report content',
      body: '<p class="muted small">Reports are anonymous to other users and reviewed by trained moderators.</p><form novalidate>' +
        '<div class="field" data-field="reason"><label for="rp-r">Reason</label><select class="input" id="rp-r" name="reason"><option value="">Choose a reason…</option><option>Harassment or bullying</option><option>Shares someone\'s private information</option><option>Self-harm concern – someone may be at risk</option><option>Spam or off-topic</option><option>Misinformation about mental health</option><option>Other</option></select></div>' +
        '<div class="field"><label for="rp-d">Details (optional)</label><textarea class="input" id="rp-d" name="details" rows="3" maxlength="500"></textarea></div>' +
        '<div class="form-actions"><button type="button" class="btn btn-outline" data-close>Cancel</button><button class="btn btn-primary">Submit report</button></div></form>',
      onMount(m, close) {
        onSubmit(m.querySelector('form'), async form => {
          const d = formData(form);
          try { await api.reportContent(user, type, id, d.reason, d.details); close(); toast('Thank you. A moderator will review this shortly.'); if (done) done(); }
          catch (err) { showErrors(form, err.fields ? err : { message: err.message, fields: { reason: err.message } }); }
        });
      }
    });
  }
  function bindSocial(root, user, rerender) {
    root.addEventListener('click', async e => {
      const r = e.target.closest('[data-react]');
      if (r) {
        if (!user) { toast('Log in to react to posts.', 'error'); return; }
        if (r.disabled) return;
        r.disabled = true;
        try { await api.toggleReaction(user, r.dataset.type, r.dataset.id, r.dataset.react); }
        catch (err) { toast(err.message, 'error'); }
        finally { r.disabled = false; }
        const c = api.reactionCounts(r.dataset.type, r.dataset.id, user.id);
        r.classList.toggle('on', c.mine.includes(r.dataset.react));
        r.setAttribute('aria-pressed', c.mine.includes(r.dataset.react));
        r.querySelector('span').textContent = c[r.dataset.react] || 0;
        return;
      }
      const rp = e.target.closest('[data-report]');
      if (rp) openReport(user, rp.dataset.report, rp.dataset.id, rerender);
    });
  }

  /* ---------- small chart helper ---------- */
  function barChart(points) {
    const max = Math.max(1, ...points.map(p => p.value));
    return '<div class="bars" role="img" aria-label="Bar chart">' + points.map(p =>
      '<div class="bar" title="' + esc(p.label + ': ' + p.value) + '"><em>' + (p.value || '') + '</em><i style="height:' + Math.max(2, p.value / max * 100) + '%"></i><small>' + esc(p.short || p.label) + '</small></div>').join('') + '</div>';
  }
  function hbars(rows) {
    const max = Math.max(1, ...rows.map(r => r.value));
    return rows.map(r => '<div class="hbar"><div class="lbl"><span>' + esc(r.label) + '</span><b>' + r.value + '</b></div><div class="track"><div class="fill" style="width:' + (r.value / max * 100) + '%;' + (r.color ? 'background:' + r.color : '') + '"></div></div></div>').join('');
  }

  // static markup uses <span data-icon="name"></span> placeholders
  function hydrateIcons(root) {
    root.querySelectorAll('[data-icon]').forEach(el => { el.outerHTML = icon(el.dataset.icon, el.className); });
  }

  function emptyState(iconName, text, action) {
    return '<div class="empty">' + icon(iconName) + '<p>' + esc(text) + '</p>' + (action || '') + '</div>';
  }

  Object.assign(CM, {
    icon, LOGO, esc, fmtDate, fmtDateTime, timeAgo, initials, avatar, statusBadge, qs, parseDate, DOW, MON,
    session, safeNext, page, toast, modal, confirm: confirmBox, formData, showErrors, clearErrors, tabs,
    reactionsHTML, bindSocial, openReport, barChart, hbars, emptyState, hydrateIcons, onSubmit, telHref, renderChrome
  });
})(window);
