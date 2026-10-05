/* AH5 Office — app shell, router, ledger strip
   Designed & Developed by Anwar Hossain — https://anwar.com.bd */

import { api, ApiError } from './api.js';
import { el, byCurrency, loading, fill } from './ui.js';
import * as core from './pages/core.js';
import * as work from './pages/work.js';
import * as admin from './pages/admin.js';

const app = document.getElementById('app');

const state = {
  user: api.store.user,
  /** Owner sees everything; staff only what has been ticked for them. */
  can(permission) {
    const u = this.user;
    if (!u) return false;
    if (u.role === 'admin') return true;
    return Array.isArray(u.permissions) && u.permissions.includes(permission);
  }
};

const ROUTES = [
  { path: 'dashboard',      render: core.dashboard },
  { path: 'customers',      render: core.customers },
  { path: 'customers/:id',  render: core.customerDetail },
  { path: 'services',       render: core.services },
  { path: 'accounts',       render: admin.accounts },
  { path: 'suppliers',      render: core.suppliers },
  { path: 'suppliers/:id',  render: core.supplierDetail },
  { path: 'who-does-what',  render: core.whoDoesWhat },
  { path: 'give-work',      render: core.giveWork },
  { path: 'work',           render: work.workList },
  { path: 'jobs',           render: work.jobs },
  { path: 'jobs/:id',       render: work.jobDetail },
  { path: 'quotations',     render: work.quotations },
  { path: 'quotations/:id', render: work.quotationDetail },
  { path: 'invoices',       render: work.invoices },
  { path: 'invoices/:id',   render: work.invoiceDetail },
  { path: 'payments',       render: work.payments },
  { path: 'supplier-bills', render: work.supplierBills },
  { path: 'documents',      render: admin.documents },
  { path: 'expenses',       render: admin.expenses },
  { path: 'expense-report', render: admin.expenseReport },
  { path: 'reports',        render: admin.reports },
  { path: 'messages',       render: admin.messages },
  { path: 'website',        render: admin.website },
  { path: 'enquiries',      render: admin.enquiries },
  { path: 'settings',       render: admin.settings },
  { path: 'staff',          render: admin.staff }
];

const NAV = [
  { group: 'Overview', items: [
    { href: '#/dashboard', label: 'Dashboard' },
    { href: '#/reports',   label: 'Reports', perm: 'reports.view' },
    { href: '#/expense-report', label: 'Expense report', perm: 'reports.view' }
  ]},
  { group: 'Catalogue', items: [
    { href: '#/services',   label: 'Services',   perm: 'services.view' },
    { href: '#/who-does-what', label: 'Who does what', perm: 'suppliers.view' },
  ] },
  { group: 'Work', items: [
    { href: '#/work',       label: 'Work list',  badge: 'pending_work', perm: 'jobs.view' },
    { href: '#/give-work', label: 'Give work', perm: 'jobs.edit' },
    { href: '#/jobs',       label: 'Jobs',       badge: 'pending_jobs', perm: 'jobs.view' },
    { href: '#/quotations', label: 'Quotations', perm: 'quotations.view' },
    { href: '#/invoices',   label: 'Invoices',   badge: 'overdue_invoices', perm: 'invoices.view' },
    { href: '#/payments',   label: 'Payments',   perm: 'payments.view' }
  ]},
  { group: 'People', items: [
    { href: '#/customers',      label: 'Customers', perm: 'customers.view' },
    { href: '#/suppliers',      label: 'Suppliers', perm: 'suppliers.view' },
    { href: '#/supplier-bills', label: 'Supplier bills', perm: 'supplier_bills.view' }
  ]},
  { group: 'Records', items: [
    { href: '#/documents', label: 'Documents', badge: 'expiring_docs_30', perm: 'documents.view' },
    { href: '#/expenses',  label: 'Expenses', perm: 'expenses.view' },
    { href: '#/accounts',   label: 'Accounts',   perm: 'reports.view' },
    { href: '#/messages',  label: 'Messages', perm: 'messages.view' },
    { href: '#/enquiries', label: 'Enquiries', perm: 'customers.view', badge: 'enquiries' },
    { href: '#/staff',     label: 'Staff',    perm: 'users.manage' },
    { href: '#/website',   label: 'Website',   perm: 'settings.manage' },
    { href: '#/settings',  label: 'Settings', perm: 'settings.manage' }
  ]}
];

/* ---------------------------------------------------------------- login */

function loginScreen(message) {
  const email = el('input', { type: 'email', name: 'email', required: true, autocomplete: 'username' });
  const pass  = el('input', { type: 'password', name: 'password', required: true, autocomplete: 'current-password' });
  const err   = el('span', { class: 'field-error' }, message || '');

  const submit = async ev => {
    ev.preventDefault();
    err.textContent = '';
    btn.disabled = true;
    btn.textContent = 'Signing in…';
    try {
      state.user = await api.login(email.value.trim(), pass.value);
      start();
    } catch (e) {
      err.textContent = e.message;
      btn.disabled = false;
      btn.textContent = 'Sign in';
      pass.focus();
    }
  };

  const btn = el('button', { class: 'btn primary', type: 'submit', style: 'width:100%;justify-content:center' }, 'Sign in');
  const form = el('form', { onsubmit: submit },
    el('label', { class: 'field' }, el('span', {}, 'Email'), email),
    el('label', { class: 'field' }, el('span', {}, 'Password'), pass),
    err,
    btn
  );

  fill(app, 
    el('div', { class: 'login-wrap' },
      el('div', { class: 'login-card' },
        // a logo speaks for itself; the name only appears when there is none
        brand.logo
          ? el('img', { src: brand.logo, alt: brand.name, class: 'login-logo' })
          : el('div', {},
              el('span', { class: 'eyebrow' }, brand.company || ''),
              el('h1', {}, brand.name)),
        el('span', { class: 'eyebrow' }, 'Sign in to your books'),
        form,
        el('p', { style: 'margin:22px 0 0;text-align:center;font-size:11px;color:var(--faint)' },
          'Designed & Developed by ',
          el('a', { href: 'https://anwar.com.bd', target: '_blank', rel: 'noopener' }, 'Anwar Hossain'))
      ))
  );
  email.focus();
}

/* ------------------------------------------------------------- identity */

/**
 * Your own name and mark, as set in Settings. Read once before the panel
 * draws, so nothing is hardcoded. The login screen needs it too, which is
 * why it comes from an endpoint that does not require a session.
 */
export const brand = { name: 'AH5 Office', company: '', logo: null, mark: 'AH5' };

async function loadBrand() {
  try {
    const res = await fetch(api.base + '/site/brand');
    const data = await res.json();
    if (data && data.success && data.data) {
      brand.name    = data.data.app_name || brand.name;
      brand.company = data.data.company_name || '';
      brand.logo    = data.data.logo || null;
      brand.mark    = (brand.name.match(/[A-Za-z0-9]+/g) || ['AH5'])[0].slice(0, 3).toUpperCase();
      document.title = brand.name + (brand.company ? ' — ' + brand.company : '');
      if (data.data.favicon) {
        let link = document.querySelector('link[rel=icon]');
        if (!link) {
          link = document.createElement('link');
          link.rel = 'icon';
          document.head.append(link);
        }
        link.href = data.data.favicon;
      }
    }
  } catch {
    // the panel still works under its default name
  }
}

/* ---------------------------------------------------------------- shell */

function buildShell() {
  const nav = el('nav', {});
  NAV.forEach(g => {
    const items = g.items.filter(i => !i.perm || state.can(i.perm));
    if (!items.length) return;              // hide an empty group entirely
    nav.append(el('div', { class: 'rail-group' }, g.group));
    items.forEach(i => nav.append(
      el('a', { href: i.href, dataset: { href: i.href, badge: i.badge || '' } }, i.label)
    ));
  });

  const rail = el('aside', { class: 'rail' },
    el('div', { class: 'rail-brand' },
      brand.logo
        ? el('img', { class: 'rail-logo', src: brand.logo, alt: brand.name })
        : el('div', { class: 'rail-mark' }, brand.mark),
      el('div', {},
        brand.logo ? null : el('strong', {}, brand.name),
        el('span', {}, state.user ? state.user.name : (brand.company || '')))),
    nav,
    el('div', { class: 'rail-foot' },
      el('a', { href: '#', onclick: async e => {
        e.preventDefault();
        await api.logout();
        loginScreen('You are signed out.');
      } }, 'Sign out'),
      el('div', { style: 'margin-top:8px' },
        el('a', { href: 'https://anwar.com.bd', target: '_blank', rel: 'noopener' }, 'Anwar Hossain')))
  );

  const stub  = el('div', { class: 'stub' }, loading('…'));
  const title = el('h2', {}, 'Dashboard');
  const crumb = el('span', { class: 'eyebrow' }, 'Overview');
  const strip = el('div', { class: 'strip' },
    el('div', { class: 'strip-left' }, crumb, title),
    stub
  );

  const outlet = el('div', {});
  const main = el('main', { class: 'main' }, strip, outlet);

  fill(app, el('div', { class: 'shell' }, rail, main));
  return { outlet, stub, nav, title, crumb };
}

let shell = null;

/** The ledger strip: live balances, refreshed after every change. */
async function refreshStrip() {
  if (!shell) return;
  fill(shell.stub, loading('…'));

  try {
    const res = await api.get('/reports/dashboard');
    const d = res.data;

    const item = (label, value, tone, note) => el('div', { class: 'stub-item' },
      el('span', { class: 'eyebrow' }, label),
      el('span', { class: 'amount ' + (tone || '') }, value),
      note ? el('small', {}, note) : null);

    fill(shell.stub, ...[
      item('Owed to me', byCurrency(d.receivable), 'out',
        d.counts.overdue_invoices + ' past due'),
      state.can('supplier_bills.view')
        ? item('I owe', byCurrency(d.payable), 'out', 'supplier bills') : null,
      item('Advance held', byCurrency(d.advance_held), 'in', 'not yet applied'),
      item('Open jobs', String(d.counts.pending_jobs), '',
        d.counts.expiring_docs_30 + ' documents expiring')
    ].filter(Boolean));

    // badges on the rail
    shell.nav.querySelectorAll('a[data-badge]').forEach(a => {
      const key = a.dataset.badge;
      a.querySelector('.dot')?.remove();
      const n = key ? Number(d.counts[key] || 0) : 0;
      if (n > 0) a.append(el('span', { class: 'dot' }, String(n)));
    });
  } catch (e) {
    fill(shell.stub, el('span', { style: 'color:var(--muted);font-size:13px' },
      'Balances unavailable — ' + e.message));
  }
}

/* --------------------------------------------------------------- router */

function parseHash() {
  const raw = (location.hash || '#/dashboard').slice(2);
  const [pathPart, queryPart] = raw.split('?');
  const segments = pathPart.split('/').filter(Boolean);
  return { segments, query: new URLSearchParams(queryPart || '') };
}

function matchRoute(segments) {
  for (const r of ROUTES) {
    const parts = r.path.split('/');
    if (parts.length !== segments.length) continue;
    const params = [];
    let ok = true;
    for (let i = 0; i < parts.length; i++) {
      if (parts[i].startsWith(':')) { params.push(segments[i]); continue; }
      if (parts[i] !== segments[i]) { ok = false; break; }
    }
    if (ok) return { route: r, params };
  }
  return null;
}

let routeToken = 0;

async function route() {
  if (!shell) return;
  const { segments, query } = parseHash();
  const hit = matchRoute(segments.length ? segments : ['dashboard']);

  // highlight the rail and name the page in the header
  const top = '#/' + (segments[0] || 'dashboard');
  let pageLabel = 'Dashboard', groupLabel = 'Overview';
  NAV.forEach(g => g.items.forEach(i => {
    if (i.href === top) { pageLabel = i.label; groupLabel = g.group; }
  }));
  if (segments.length > 1) pageLabel += ' · detail';
  shell.title.textContent = pageLabel;
  shell.crumb.textContent = groupLabel;
  shell.nav.querySelectorAll('a').forEach(a =>
    a.classList.toggle('active', a.dataset.href === top));

  if (!hit) {
    fill(shell.outlet, el('div', { class: 'page' },
      el('div', { class: 'empty' },
        el('strong', {}, 'Page not found'),
        el('div', {}, 'That address does not exist. '),
        el('a', { class: 'btn', href: '#/dashboard', style: 'margin-top:12px' }, 'Back to dashboard'))));
    return;
  }

  const token = ++routeToken;
  fill(shell.outlet, loading());

  const ctx = {
    can: p => state.can(p),
    params: hit.params,
    query,
    go: (hash, forceReload) => {
      if (forceReload && location.hash === hash) route();
      else location.hash = hash;
    }
  };

  try {
    const node = await hit.route.render(ctx);
    if (token !== routeToken) return;      // a newer navigation won
    fill(shell.outlet, node);
    window.scrollTo(0, 0);
  } catch (e) {
    if (token !== routeToken) return;
    if (e instanceof ApiError && e.status === 401) return;   // sign-out handles it
    fill(shell.outlet, el('div', { class: 'page' },
      el('div', { class: 'empty' },
        el('strong', {}, 'This page could not load'),
        el('div', {}, e.detail || e.message),
        el('button', { class: 'btn', style: 'margin-top:12px', onclick: () => route() }, 'Try again'))));
  }
}

/* ----------------------------------------------------------------- boot */

function start() {
  shell = buildShell();
  refreshStrip();
  route();
}

window.addEventListener('hashchange', route);
window.addEventListener('ah5:signed-out', () => {
  shell = null;
  loginScreen('Your session expired. Sign in again.');
});

// refresh the strip after any write, so the balances never look stale
const origPost = api.post.bind(api);
api.post = async (...args) => {
  const res = await origPost(...args);
  if (!String(args[0]).startsWith('/messages')) refreshStrip();
  return res;
};

(async () => {
  await loadBrand();          // the name and mark before anything is drawn
  if (!api.store.access) { loginScreen(); return; }
  try {
    const me = await api.get('/auth/me');
    state.user = me.data;
    api.store.save(null, me.data);
    start();
  } catch {
    api.store.clear();
    loginScreen();
  }
})();
