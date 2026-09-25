/* Studio Pro Subscription Box — shared JS: API client + auth + UI helpers */
(function () {
  'use strict';

  const TOKEN_KEY = 'sp_token';
  const USER_KEY = 'sp_user';
  const CURRENCY = 'RM';

  /* API base relative to page location: /subscriber/x.html → ../api */
  const segs = location.pathname.split('/').filter(Boolean);
  const API_BASE = (segs.length > 1 ? '../api' : 'api');

  const $ = (s, el) => (el || document).querySelector(s);
  const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function fmtMoney(n) { return CURRENCY + ' ' + Number(n || 0).toFixed(2); }
  function fmtDate(d) { return d ? String(d).slice(0, 10) : '—'; }

  function getToken() { return localStorage.getItem(TOKEN_KEY) || ''; }
  function getUser() {
    try { return JSON.parse(localStorage.getItem(USER_KEY) || 'null'); } catch { return null; }
  }
  function setAuth(token, user) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  }
  function clearAuth() { localStorage.removeItem(TOKEN_KEY); localStorage.removeItem(USER_KEY); }

  async function api(path, opts = {}) {
    const headers = { 'Content-Type': 'application/json' };
    const t = getToken();
    if (t) headers['Authorization'] = 'Bearer ' + t;
    let res;
    try {
      res = await fetch(API_BASE + '/' + path, { method: opts.method || 'GET', headers, body: opts.body ? JSON.stringify(opts.body) : undefined });
    } catch (e) {
      throw new Error('Cannot reach API at ' + API_BASE + ' — is the site uploaded & configured?');
    }
    let json = {};
    try { json = await res.json(); } catch { /* non-JSON */ }
    if (res.status === 401) { clearAuth(); location.href = (API_BASE === 'api' ? '' : '../') + 'index.html'; throw new Error('Session expired'); }
    if (!json.ok && json.error) throw new Error(json.error);
    return json.data;
  }

  function requireRole(...roles) {
    const u = getUser();
    if (!u) { location.href = (API_BASE === 'api' ? '' : '../') + 'index.html'; return null; }
    if (roles.length && !roles.includes(u.role)) {
      toast('No access for role: ' + u.role, 'err');
      location.href = (API_BASE === 'api' ? '' : '../') + 'index.html';
      return null;
    }
    return u;
  }

  function toast(msg, type = '') {
    let wrap = $('.toast-wrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.className = 'toast-wrap'; document.body.appendChild(wrap); }
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'err' ? 'err' : type === 'ok' ? 'ok' : '');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  function riskBadge(r) { return '<span class="badge risk-' + esc(r) + '">' + esc((r || '').toUpperCase()) + '</span>'; }
  function statusBadge(s) { return '<span class="badge ' + (s === 'active' ? 'ok' : s === 'paused' ? 'warn' : s === 'canceled' ? 'danger' : 'muted') + '">' + esc(s) + '</span>'; }

  /* Sidebar shell for authed pages */
  function shell(role, active) {
    const nav = {
      subscriber: [['subs', '🎁 My Subscriptions'], ['custom', '🔄 Customize My Box'], ['addons', '🛒 Add-On Shop'], ['profile', '👤 Profile']],
      merchant: [['kpis', '📊 Overview'], ['churn', '⚠️ Churn Alerts'], ['forecast', '📦 Inventory Forecast'], ['dunning', '💳 Payment Recovery'], ['subs', '🧑 Subscribers']],
      ops: [['packing', '📋 Packing Lists'], ['shipping', '🚚 Shipping Labels'], ['address', '📍 Address Tool']],
    }[role] || [];
    const u = getUser();
    document.body.innerHTML = '<div class="app"><aside class="sidebar">' +
      '<div class="brand"><div class="logo">📦</div><div>Studio Pro Platform</div></div>' +
      nav.map(([id, label]) => '<button class="nav-item' + (id === active ? ' active' : '') + '" data-nav="' + id + '">' + label + '</button>').join('') +
      '<div class="spacer"></div>' +
      '<div class="user-chip"><b>' + esc(u?.name || '') + '</b>' + esc(u?.email || '') +
      '<div style="margin-top:6px"><button class="btn btn-sm btn-ghost" id="logout">Log out</button></div></div>' +
      '</aside><main class="main"><div id="view"></div></main></div>';
    $$('.nav-item').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.nav === active) return;
      switchViews(window.SP.roles[role][b.dataset.nav]);
      $$('.nav-item').forEach(x => x.classList.toggle('active', x === b));
    }));
    $('#logout').addEventListener('click', async () => { await api('auth.php?action=logout', { method: 'POST' }).catch(() => {}); clearAuth(); location.href = (API_BASE === 'api' ? '' : '../') + 'index.html'; });
  }

  function switchViews(fn) { $('#view').innerHTML = ''; fn(); }

  window.SP = { API_BASE, $, $$, esc, fmtMoney, fmtDate, api, toast, riskBadge, statusBadge, requireRole, shell, switchViews, getUser, clearAuth, roles: {} };
})();