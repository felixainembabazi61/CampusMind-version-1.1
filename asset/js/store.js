/*
 * CampusMind client data layer.
 * All data lives in the database behind the PHP API (api/index.php). The server
 * returns a "snapshot" of what the signed-in user may see after every call; pages
 * read from that snapshot synchronously and send every change to the server.
 */
(function (global) {
  'use strict';

  const API_URL = 'api/index.php';
  const TABLES = ['users', 'slots', 'bookings', 'intakes', 'stories', 'forumCategories', 'forumPosts', 'forumComments',
    'reactions', 'reports', 'conversations', 'messages', 'notifications', 'contactMessages'];

  class ValidationError extends Error {
    constructor(message, fields) { super(message); this.name = 'ValidationError'; this.fields = fields || {}; }
  }
  class ServerUnavailable extends Error {}

  function pad(n) { return String(n).padStart(2, '0'); }
  function toDateStr(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function addDays(days, from) {
    const d = from ? new Date(from) : new Date();
    d.setHours(12, 0, 0, 0);
    d.setDate(d.getDate() + days);
    return toDateStr(d);
  }
  function slotStart(slot) { return new Date(slot.date + 'T' + slot.start + ':00'); }

  /* ---------- read-only snapshot of server data ---------- */
  class Store {
    constructor() { this.me = null; this.app = {}; this.stats = {}; this.settings = {}; this.data = {}; TABLES.forEach(t => { this.data[t] = []; }); }
    apply(snap) {
      if (!snap) return;
      this.me = snap.me;
      this.app = snap.app || {};
      this.stats = snap.stats || {};
      this.settings = snap.settings || {};
      TABLES.forEach(t => { this.data[t] = (snap.data && snap.data[t]) || []; });
    }
    all(table) { return this.data[table].slice(); }
    find(table, id) { return this.data[table].find(r => r.id === id) || null; }
    where(table, fn) { return this.data[table].filter(fn); }
  }
  const db = new Store();

  async function call(action, payload) {
    let res;
    try {
      res = await fetch(API_URL + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'CampusMind' },
        body: JSON.stringify(payload || {})
      });
    } catch (e) {
      throw new ServerUnavailable('Cannot reach the CampusMind server.');
    }
    let json;
    try { json = await res.json(); } catch (e) {
      throw new ServerUnavailable('The server did not return valid data. Make sure the site is opened through the PHP server (start-server.bat).');
    }
    if (json.snapshot) db.apply(json.snapshot);
    if (!json.ok) {
      if (json.fields && Object.keys(json.fields).length || res.status === 422) throw new ValidationError(json.error, json.fields);
      throw new Error(json.error || 'Something went wrong.');
    }
    return json.result;
  }

  /* ---------- API: reads come from the snapshot, writes go to the server ---------- */
  const api = {
    ROLES: ['student', 'counselor', 'moderator', 'admin'],
    load: () => call('bootstrap'),
    user: id => db.find('users', id),
    displayName: (userId, anonymous) => anonymous || !userId ? 'Anonymous student' : ((db.find('users', userId) || {}).name || 'Former member'),
    homeFor: user => ({ student: 'dashboard.html', counselor: 'counselor.html', moderator: 'moderation.html', admin: 'admin.html' }[user.role] || 'index.html'),
    stats: () => db.stats,

    /* Auth/LoginController */
    login: (email, password) => call('login', { email, password }),
    register: input => call('register', input),
    logout: () => call('logout'),
    changePassword: (user, current, next) => call('changePassword', { current, next }),

    /* availability + BookingController */
    counselors: () => db.where('users', u => u.role === 'counselor' && u.active),
    openSlots(filter) {
      const f = filter || {};
      const now = new Date();
      return db.where('slots', s => s.status === 'open' && slotStart(s) > now &&
        (!f.counselorId || s.counselorId === f.counselorId) && (!f.date || s.date === f.date) && (!f.mode || s.mode === f.mode))
        .sort((a, b) => slotStart(a) - slotStart(b));
    },
    addSlots: (user, input) => call('addSlots', input),
    removeSlot: (user, slotId) => call('removeSlot', { slotId }),
    bookSlot: (user, slotId, reason) => call('bookSlot', { slotId, reason }),
    cancelBooking: (user, bookingId) => call('cancelBooking', { bookingId }),
    setBookingStatus: (user, bookingId, status, notes) => call('setBookingStatus', notes === undefined ? { bookingId, status } : { bookingId, status, notes }),
    bookingsFor: () => db.all('bookings').map(b => Object.assign({}, b, { slot: db.find('slots', b.slotId) || {} }))
      .sort((a, b) => slotStart(a.slot) - slotStart(b.slot)),

    /* IntakeController */
    submitIntake: (user, input) => call('submitIntake', input),
    updateIntake: (user, id, patch) => call('updateIntake', { id, patch }),

    /* StoryController + ReactionController */
    submitStory: (user, input) => call('submitStory', input),
    publishedStories: () => db.where('stories', s => s.status === 'published').sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
    moderateStory: (user, id, status, note) => call('moderateStory', { id, status, note: note || '' }),
    reactionCounts(targetType, targetId, userId) {
      const out = { support: 0, relate: 0, thanks: 0, mine: [] };
      db.where('reactions', r => r.targetType === targetType && r.targetId === targetId).forEach(r => {
        out[r.type] = (out[r.type] || 0) + 1;
        if (userId && r.userId === userId) out.mine.push(r.type);
      });
      return out;
    },
    toggleReaction: (user, targetType, targetId, type) => call('toggleReaction', { targetType, targetId, type }),

    /* ForumController, PostController, CommentController */
    categories: () => db.all('forumCategories'),
    addCategory: (user, input) => call('addCategory', input),
    visiblePosts: categoryId => db.where('forumPosts', p => p.status === 'visible' && (!categoryId || p.categoryId === categoryId))
      .sort((a, b) => (b.pinned - a.pinned) || b.createdAt.localeCompare(a.createdAt)),
    createPost: (user, input) => call('createPost', input),
    commentsFor: postId => db.where('forumComments', c => c.postId === postId && c.status === 'visible').sort((a, b) => a.createdAt.localeCompare(b.createdAt)),
    addComment: (user, postId, body, anonymous) => call('addComment', { postId, body, anonymous: !!anonymous }),
    setPostFlags: (user, postId, flags) => call('setPostFlags', { postId, flags }),
    deleteOwn: (user, type, id) => call('deleteOwn', { type, id }),
    restoreContent: (user, type, id) => call('restoreContent', { type, id }),

    /* ReportController */
    reportContent: (user, targetType, targetId, reason, details) => call('reportContent', { targetType, targetId, reason, details: details || '' }),
    reportTarget: r => db.find({ story: 'stories', post: 'forumPosts', comment: 'forumComments' }[r.targetType], r.targetId),
    resolveReport: (user, reportId, action) => call('resolveReport', { reportId, action }),
    escalateReport: (user, reportId) => call('escalateReport', { reportId }),

    /* ConversationController + MessageController (the server re-checks these rules) */
    canMessage(user, other) {
      if (!user || !other || user.id === other.id || !other.active) return false;
      if (user.role === 'admin' || other.role === 'admin' || user.role === 'counselor') return true;
      if (user.role === 'student') return other.role === 'counselor';
      return other.role !== 'student';
    },
    contactsFor: user => db.where('users', u => api.canMessage(user, u)),
    conversationsFor(user) {
      return db.all('conversations').map(c => {
        const msgs = db.where('messages', m => m.conversationId === c.id);
        const last = msgs[msgs.length - 1];
        return Object.assign({}, c, { last, otherId: c.participants.find(p => p !== user.id), unread: msgs.filter(m => !m.readBy.includes(user.id)).length });
      }).sort((a, b) => ((b.last || b).createdAt).localeCompare((a.last || a).createdAt));
    },
    openConversation: (user, otherId) => call('openConversation', { otherId }),
    sendMessage: (user, conversationId, body) => call('sendMessage', { conversationId, body }),
    markRead: (user, conversationId) => call('markRead', { conversationId }),
    notificationsFor: () => db.all('notifications'),
    markNotificationsRead: () => call('markNotificationsRead'),

    /* AdminController */
    setUserRole: (user, userId, role) => call('setUserRole', { userId, role }),
    setUserActive: (user, userId, active) => call('setUserActive', { userId, active }),
    createStaff: (user, input) => call('createStaff', input),
    updateSettings: (user, input) => call('updateSettings', input),
    exportData: () => call('exportData'),
    resetData: () => call('resetData'),
    contact: input => call('contact', input)
  };

  global.CM = global.CM || {};
  Object.assign(global.CM, { db, api, call, addDays, slotStart, toDateStr, ValidationError, ServerUnavailable });
})(window);
