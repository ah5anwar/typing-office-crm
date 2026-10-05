/* AH5 Office — dashboard, customers, services, suppliers
   Designed & Developed by Anwar Hossain — https://anwar.com.bd */

import { api } from '../api.js';
import * as work from './work.js';
import {
  el, table, card, tile, money, date, tag, modal, formFields, readFields,
  toast, fail, byCurrency, daysPhrase, loading, pager, first, join, fileManager, fill
} from '../ui.js';


/* ============================================================= dashboard */

export async function dashboard(ctx) {
  const page = el('div', { class: 'page' }, loading('Reading the books…'));

  const [dash, dues] = await Promise.all([
    api.get('/reports/dashboard'),
    ctx.can('payments.view') ? api.get('/payments/due-list') : { data: { rows: [] } }
  ]);

  const d = dash.data;
  const c = d.counts;
  const t = d.attention || {};

  const movedToday = Number(d.cash_today || 0);
  const accountList = d.accounts || [];

  /* one list of what actually needs doing, newest worry first */
  const todo = [
    ...(t.overdue_invoices || []).map(r => ({
      kind: 'money', tone: 'out',
      what: 'Chase ' + first(r.company_name, r.customer_name),
      detail: r.invoice_no + ' · ' + money(r.due_amount, r.currency),
      when: r.days_over + ' days over',
      href: '#/invoices/' + r.id
    })),
    ...(t.expiring || []).map(r => ({
      kind: 'paper', tone: Number(r.days_left) < 0 ? 'out' : 'hold',
      what: r.title,
      detail: first(r.company_name, r.customer_name),
      when: daysPhrase(r.days_left),
      href: '#/documents'
    })),
    ...(t.work_due || []).map(r => ({
      kind: 'work', tone: Number(r.days_to_due) < 0 ? 'out' : 'hold',
      what: r.description,
      detail: first(r.company_name, r.customer_name) + ' · ' + r.job_no,
      when: daysPhrase(r.days_to_due),
      href: '#/work'
    }))
  ];

  const todoRow = item => el('a', {
    href: item.href,
    style: 'display:flex;align-items:center;gap:12px;padding:11px 16px;'
         + 'border-bottom:1px solid var(--rule-soft);text-decoration:none;color:inherit'
  },
    el('span', { class: 'tag ' + item.tone },
      item.kind === 'money' ? 'money' : item.kind === 'paper' ? 'expiry' : 'work'),
    el('div', { style: 'flex:1;min-width:0' },
      el('strong', {}, item.what),
      el('span', { class: 'sub' }, item.detail)),
    el('span', { class: 'num', style: 'color:var(--' + (item.tone === 'out' ? 'out' : 'muted') + ')' },
      item.when)
  );

  fill(page, 
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, date(new Date())),
        el('h1', {}, 'Today'),
        el('p', {}, todo.length
          ? todo.length + ' thing(s) want your attention'
          : 'Nothing overdue. The books are clean.')),
      el('div', { class: 'page-actions' },
        ctx.can('payments.entry') ? el('a', { class: 'btn', href: '#/payments' }, 'Take a payment') : null,
        ctx.can('invoices.edit') ? el('a', { class: 'btn primary', href: '#/invoices' }, 'New invoice') : null)
    ),

    /* the money line: what is owed, what you owe, what is already in hand */
    el('div', { class: 'grid c4', style: 'margin-bottom:18px' },
      tile('Owed to me', byCurrency(d.receivable),
        c.overdue_invoices + ' invoice(s) past due', 'lead'),
      ctx.can('supplier_bills.view')
        ? tile('I owe suppliers', byCurrency(d.payable), 'unpaid bills', 'hold') : null,
      tile('Advance in hand', byCurrency(d.advance_held), 'not applied to any invoice', 'in'),
      tile('Money in hand', money(d.in_hand || 0),
        accountList.length + ' account(s)', Number(d.in_hand) >= 0 ? 'in' : 'out')
    ),

    /* where that money actually sits */
    accountList.length
      ? card('Where the money is',
          el('div', {},
            ...accountList.map(a => el('div', {
              style: 'display:flex;justify-content:space-between;gap:12px;padding:9px 0;'
                   + 'border-bottom:1px solid var(--rule-soft)'
            },
              el('span', {}, a.name,
                el('span', { class: 'sub' }, a.type === 'bank' ? 'bank account'
                  : a.type === 'mobile' ? 'mobile wallet' : a.type)),
              el('span', { class: 'num',
                style: 'font-weight:600;color:' + (Number(a.balance) >= 0 ? 'var(--in)' : 'var(--out)') },
                money(a.balance)))),
            el('div', { style: 'display:flex;justify-content:space-between;margin-top:12px' },
              el('span', { style: 'color:var(--muted);font-size:13px' },
                movedToday ? money(movedToday) + ' moved today' : 'nothing moved today'),
              el('a', { class: 'btn sm', href: '#/accounts' }, 'Open accounts'))))
      : null,

    /* what to do, and the counts behind it */
    el('div', { class: 'grid', style: 'grid-template-columns:1.35fr 1fr;gap:16px;align-items:start' },
      card('Needs attention',
        todo.length
          ? el('div', {}, ...todo.slice(0, 12).map(todoRow))
          : el('div', { class: 'empty' },
              el('strong', {}, 'All clear'),
              el('div', {}, 'No overdue money, no expiring papers, no work past its date.')),
        null, true),

      el('div', {},
        el('div', { class: 'grid c2', style: 'margin-bottom:14px' },
          tile('Work to do', String(c.pending_jobs), 'open jobs',
            Number(c.pending_jobs) ? 'hold' : ''),
          tile('Expiring soon', String(c.expiring_docs_30), 'in the next 30 days',
            Number(c.expiring_docs_30) ? 'out' : ''),
          tile('Customers', String(c.customers), 'on the books'),
          tile('Services', String(c.services), 'in the catalogue')),

        card('This month',
          el('div', {},
            ...(d.this_month.by_currency.length
              ? d.this_month.by_currency.map(m => el('div', {
                  style: 'display:flex;justify-content:space-between;gap:10px;'
                       + 'padding:8px 0;border-bottom:1px solid var(--rule-soft)'
                },
                  el('span', { style: 'color:var(--muted)' }, m.currency),
                  el('span', { class: 'num' },
                    el('span', { style: 'color:var(--in)' }, money(m.total_income)),
                    ' − ',
                    el('span', { style: 'color:var(--out)' }, money(m.total_expense)),
                    ' = ',
                    el('strong', { style: 'color:' + (Number(m.net) >= 0 ? 'var(--in)' : 'var(--out)') },
                      money(m.net)))))
              : [el('p', { style: 'color:var(--muted);margin:0' }, 'No money has moved this month yet.')]),
            ctx.can('reports.view')
              ? el('a', { class: 'btn sm', style: 'margin-top:12px', href: '#/reports' }, 'Full report')
              : null))
      )
    ),

    ctx.can('payments.view') && (dues.data.rows || []).length
      ? card('Who owes me money',
          table([
            { key: 'name', label: 'Customer', render: r => el('div', {},
                el('strong', {}, first(r.company_name, r.name)),
                el('span', { class: 'sub' }, r.phone || '—')) },
            { key: 'due', label: 'Due', num: true, render: r =>
                el('strong', { style: 'color:var(--out)' }, money(r.due, r.currency)) },
            { key: 'days_overdue', label: 'Oldest', render: r => Number(r.days_overdue) > 0
                ? el('span', { class: 'tag out' }, r.days_overdue + 'd over') : tag('sent') }
          ], (dues.data.rows || []).slice(0, 8), {
            onRowClick: r => ctx.go('#/customers/' + r.customer_id)
          }),
          el('a', { class: 'btn sm', href: '#/payments' }, 'Open due list'),
          true)
      : null
  );

  return page;
}

/* ============================================================= customers */

export async function customers(ctx) {
  const page = el('div', { class: 'page' });
  const listHost = el('div', {});
  let query = '', onlyDue = false, pageNo = 1;

  const load = async () => {
    fill(listHost, loading());
    try {
      const res = await api.get('/customers',
        { q: query, has_due: onlyDue ? 1 : '', page: pageNo, per_page: 25 });
      fill(listHost, card(null,
        table([
          { key: 'name', label: 'Customer', render: r => el('div',
              { style: 'display:flex;align-items:center;gap:10px' }, avatar(r), el('div', {},
              el('strong', {}, first(r.company_name, r.name)),
              el('span', { class: 'sub' }, join(r.name, r.city)))) },
          { key: 'code', label: 'Code', render: r => el('span', { class: 'doc-no' }, r.code || '—') },
          { key: 'phone', label: 'Phone', render: r => r.phone || '—' },
          { key: 'total_due', label: 'Owes me', num: true, render: r =>
              Number(r.total_due) > 0
                ? el('strong', { style: 'color:var(--out)' }, money(r.total_due, r.default_currency))
                : el('span', { style: 'color:var(--faint)' }, '—') },
          { key: 'advance_held', label: 'Advance held', num: true, render: r =>
              Number(r.advance_held) > 0
                ? el('span', { style: 'color:var(--in)' }, money(r.advance_held, r.default_currency))
                : el('span', { style: 'color:var(--faint)' }, '—') },
          { key: 'pending_work', label: 'Work left', num: true, render: r =>
              Number(r.pending_work) ? el('span', { class: 'tag hold' }, r.pending_work) : '—' },
          { key: 'expiring_docs', label: 'Expiring', num: true, render: r =>
              Number(r.expiring_docs) > 0
                ? el('span', { class: 'tag out' }, r.expiring_docs)
                : (r.next_expiry ? el('span', { class: 'sub' }, date(r.next_expiry)) : '—') },
          { key: 'file_count', label: 'Files', render: r =>
              Number(r.file_count) ? el('span', { class: 'clip' }, '📎 ' + r.file_count) : '—' }
        ], res.data, {
          onRowClick: r => ctx.go('#/customers/' + r.id),
          empty: { title: 'No customers yet', hint: 'Add a customer to start recording work and invoices.' }
        }), null, true),
        pager(res.meta, p => { pageNo = p; load(); }));
    } catch (e) { fail(e); fill(listHost, el('div', { class: 'empty' }, 'Could not load customers.')); }
  };

  const search = el('input', { type: 'search', placeholder: 'Search name, company, phone…',
    oninput: e => { query = e.target.value; pageNo = 1; clearTimeout(search._t); search._t = setTimeout(load, 250); } });

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Directory'), el('h1', {}, 'Customers')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn primary', onclick: () => customerForm(null, load) }, 'Add customer'))
    ),
    el('div', { class: 'filters', style: 'margin-bottom:14px' },
      search,
      el('label', { style: 'display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted)' },
        el('input', { type: 'checkbox', style: 'width:auto',
          onchange: e => { onlyDue = e.target.checked; pageNo = 1; load(); } }),
        'Only with money due')
    ),
    listHost
  );

  load();
  return page;
}

function customerForm(existing, onDone) {
  const fields = formFields([
    { name: 'name', label: 'Contact name', required: true, span: 2 },
    { name: 'company_name', label: 'Company', span: 2 },
    { name: 'type', label: 'Type', type: 'select', options: [
        { value: 'individual', label: 'Individual' }, { value: 'company', label: 'Company' }] },
    { name: 'phone', label: 'Phone' },
    { name: 'whatsapp', label: 'WhatsApp', hint: 'Used for reminders' },
    { name: 'email', label: 'Email', type: 'email',
      hint: 'Invoices and statements can go here too' },
    { name: 'telegram_chat_id', label: 'Telegram chat ID', hint: 'After they press /start on your bot' },
    { name: 'address', label: 'Address', span: 2 },
    { name: 'city', label: 'City' },
    { name: 'country', label: 'Country' },
    { name: 'trade_license', label: 'Trade licence' },
    { name: 'tax_number', label: 'TRN / BIN' },
    { name: 'notes', label: 'Notes', type: 'textarea', span: 2 }
  ], existing || { type: 'individual', default_currency: 'BDT' });

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      if (existing) await api.patch('/customers/' + existing.id, body);
      else await api.post('/customers', body);
      toast(existing ? 'Customer updated' : 'Customer added');
      m.close(); onDone && onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Add customer');

  const m = modal({
    title: existing ? 'Edit ' + existing.name : 'Add customer',
    body: el('div', {}, fields,
      existing ? photoPicker('customer', existing) : null,
      existing ? null : el('p', { style: 'color:var(--faint);font-size:12.5px;margin:10px 0 0' },
        'Save them first, then add a picture.')), wide: true,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

/* ------------------------------------------------------- customer detail */

/**
 * One customer, everything about them. Open this before you ring someone:
 * what they owe, what is still to do, which of their papers expire when,
 * and every file you hold for them.
 */
export async function customerDetail(ctx) {
  const id = ctx.params[0];
  const res = await api.get('/customers/' + id + '/overview');
  const d = res.data;
  const c = d.customer;
  const reload = () => ctx.go('#/customers/' + id, true);

  const contact = [c.phone, c.whatsapp && c.whatsapp !== c.phone ? 'wa ' + c.whatsapp : null,
                   c.email, c.city].filter(Boolean).join('  ·  ');

  const moneyTiles = d.money.length
    ? d.money.flatMap(m => [
        tile('Owes me (' + m.currency + ')', money(m.due),
          Number(m.due) > 0 && m.oldest_due ? 'oldest due ' + date(m.oldest_due) : 'nothing outstanding',
          Number(m.due) > 0 ? 'out' : 'in'),
        tile('Billed (' + m.currency + ')', money(m.invoiced), m.invoices + ' invoice(s)'),
        tile('Paid (' + m.currency + ')', money(m.paid), 'received so far', 'in'),
        ...(ctx.can('costs.view')
          ? [tile('Profit (' + m.currency + ')', money(Number(m.invoiced) - Number(m.cost)),
              'billed − my cost')]
          : [])
      ])
    : [tile('Owes me', '0.00', 'no invoices yet')];

  const advanceLine = (d.advance || []).filter(a => Number(a.amount) > 0);

  return el('div', { class: 'page' },
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, join(c.code, c.type === 'company' ? 'company' : 'individual')),
        el('h1', {}, first(c.company_name, c.name)),
        el('p', {}, join(c.company_name ? c.name : null, contact))),
      el('div', { class: 'page-actions' },
        ctx.can('messages.send') && c.whatsapp
          ? el('button', { class: 'btn', onclick: async () => {
              try {
                const r = await api.post('/messages/due-reminder',
                  { customer_id: Number(id), channel: 'whatsapp' });
                toast(r.data.result.success ? 'Reminder sent' : r.data.result.error,
                  r.data.result.success ? 'ok' : 'err');
              } catch (e) { fail(e); }
            } }, 'Send reminder') : null,
        el('button', { class: 'btn', onclick: () => viewStatement('customer', c, id) }, 'Statement'),
        ctx.can('payments.view')
          ? el('button', { class: 'btn', onclick: () => paymentStatement(c, id) }, 'Payments')
          : null,
        ctx.can('messages.send')
          ? el('button', { class: 'btn', onclick: () => statementForm('customer', c, id) }, 'Send statement')
          : null,
        ctx.can('jobs.view') ? el('a', { class: 'btn', href: '#/work?party=' + id }, 'Their work') : null,
        ctx.can('invoices.view') ? el('a', { class: 'btn', href: '#/invoices?customer=' + id }, 'Invoices') : null,
        ctx.can('customers.edit')
          ? el('button', { class: 'btn primary', onclick: () => customerForm(c, reload) }, 'Edit') : null)
    ),

    el('div', { class: 'grid c4', style: 'margin-bottom:14px' }, ...moneyTiles),

    advanceLine.length
      ? card(null, el('p', { style: 'margin:0' },
          'You are holding ',
          el('strong', { style: 'color:var(--in)' }, byCurrency(advanceLine)),
          ' of their money that is not applied to any invoice yet.'))
      : null,

    el('div', { class: 'grid', style: 'grid-template-columns:1fr 1fr;gap:16px;align-items:start' },
      card('Still to do (' + d.counts.open_work + ')',
        table([
          { key: 'description', label: 'Task', render: r => el('div', {},
              el('strong', {}, r.description),
              el('span', { class: 'sub' }, join(r.job_no, r.given_to ? 'with ' + r.given_to : 'with me'))) },
          { key: 'due_date', label: 'By', render: r => r.due_date
              ? el('div', {}, date(r.due_date),
                  el('span', { class: 'sub', style: Number(r.days_to_due) < 0 ? 'color:var(--out)' : '' },
                    daysPhrase(r.days_to_due)))
              : '—' },
          { key: 'line_total', label: 'Value', num: true, render: r => money(r.line_total, r.currency) }
        ], d.work, { empty: { title: 'Nothing pending', hint: 'Every task for this customer is done.' } }),
        null, true),

      card('Papers (' + d.counts.documents + ')',
        table([
          { key: 'title', label: 'Document', render: r => el('div', {},
              el('strong', {}, r.title),
              el('span', { class: 'sub' }, join(r.doc_type_name, r.doc_number))) },
          { key: 'expiry_date', label: 'Expires', render: r => el('div', {},
              date(r.expiry_date),
              el('span', {
                class: 'sub',
                style: Number(r.days_left) < 30 ? 'color:var(--out)' : ''
              }, daysPhrase(r.days_left))) },
          { key: 'file_count', label: '', render: r => Number(r.file_count)
              ? el('span', { class: 'clip' }, '📎 ' + r.file_count) : '—' }
        ], d.documents, {
          onRowClick: () => ctx.go('#/documents'),
          empty: { title: 'No papers on file', hint: 'Add a passport, licence or visa to be reminded before it expires.' }
        }),
        ctx.can('documents.view') ? el('a', { class: 'btn sm', href: '#/documents' }, 'Manage') : null,
        true)
    ),

    card('Their files (' + d.counts.files + ')',
      fileList(d.files)),

    card('Account',
      table([
        { key: 'entry_date', label: 'Date', render: r => date(r.entry_date) },
        { key: 'ref_no', label: 'Reference', render: r => el('span', { class: 'doc-no' }, r.ref_no) },
        { key: 'type', label: 'Type', render: r => el('div', {},
            tag(r.type),
            r.account_label ? el('span', { class: 'sub' }, r.account_label) : null) },
        { key: 'debit', label: 'Billed', num: true, render: r =>
            Number(r.debit) ? money(r.debit, r.currency) : '—' },
        { key: 'credit', label: 'Received', num: true, render: r =>
            Number(r.credit) ? el('span', { style: 'color:var(--in)' }, money(r.credit, r.currency)) : '—' }
      ], d.ledger, { empty: { title: 'No entries yet', hint: 'Invoices and payments will appear here.' } }),
      null, true),

    (d.messages || []).length
      ? card('Last messages',
          table([
            { key: 'created_at', label: 'When', render: r => date(r.created_at) },
            { key: 'channel', label: 'Channel', render: r => tag(r.channel) },
            { key: 'body', label: 'Message', render: r => String(r.body ?? '').slice(0, 70) + '…' },
            { key: 'status', label: '', render: r => tag(r.status) }
          ], d.messages), null, true)
      : null
  );
}

/**
 * Files gathered under the document they belong to, because that is how you
 * look for them: "Rahim's passport", not "scan_04.pdf".
 */
function fileList(files) {
  if (!files.length) {
    return el('div', { class: 'empty' },
      el('strong', {}, 'No files yet'),
      el('div', {}, 'Passport scans, licences and payment slips collect here.'));
  }

  const groups = new Map();
  for (const f of files) {
    const title = f.belongs_to || 'Loose files';
    if (!groups.has(title)) groups.set(title, []);
    groups.get(title).push(f);
  }

  const row = f => el('div', { class: 'file-row' },
    el('div', { class: 'file-kind' }, String(f.file_name).split('.').pop().slice(0, 4)),
    el('div', { class: 'name' },
      first(f.label, f.file_name),
      el('span', { class: 'sub' }, join(f.label ? f.file_name : null, f.size_label, date(f.created_at)))),
    f.can_view
      ? el('a', { class: 'btn sm', href: f.view_url, target: '_blank', rel: 'noopener' }, 'View')
      : null,
    el('a', { class: 'btn sm', href: f.download_url }, 'Download')
  );

  return el('div', {},
    ...[...groups.entries()].map(([title, rows]) => el('div', { style: 'margin-bottom:16px' },
      el('div', {
        style: 'display:flex;justify-content:space-between;align-items:baseline;'
             + 'gap:10px;margin-bottom:6px'
      },
        el('strong', { style: 'font-size:13.5px' }, title),
        el('span', { class: 'sub' }, rows.length + ' file' + (rows.length === 1 ? '' : 's'))),
      el('div', { class: 'files' }, ...rows.map(row))))
  );
}

/* ============================================================== services */

export async function services(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let cur = 'BDT';

  const load = async () => {
    fill(host, loading());
    const [sRes, cRes, setRes] = await Promise.all([
      api.get('/services', { per_page: 200 }),
      api.get('/service-categories'),
      api.get('/settings').catch(() => ({ data: {} }))
    ]);
    const cats = cRes.data;
    cur = setRes.data.base_currency || 'BDT';   // the one office currency
    page._cur = cur;

    fill(host, card(null,
      table([
        { key: 'name', label: 'Service', render: r => el('div', {},
            el('strong', {}, r.name),
            el('span', { class: 'sub' }, r.category_name)) },
        { key: 'unit', label: 'Unit' },
        ...(ctx.can('costs.view') ? [
          { key: 'cost_primary', label: 'My cost', num: true, render: r => money(r.cost_primary, cur) }
        ] : []),
        { key: 'sell_primary', label: 'Customer price', num: true, render: r => money(r.sell_primary, cur) },
        ...(ctx.can('costs.view') ? [
          { key: 'profit_primary', label: 'Margin', num: true, render: r =>
              el('span', { style: 'color:' + (Number(r.profit_primary) >= 0 ? 'var(--in)' : 'var(--out)') },
                money(r.profit_primary)) }
        ] : []),
        ...(ctx.can('suppliers.view') ? [
          { key: 'supplier_count', label: 'Suppliers', num: true }
        ] : []),
        { key: 'act', label: '', render: r => el('button',
            { class: 'btn sm', onclick: () => serviceForm(r, cats, load, ctx.can('costs.view'), page._cur) }, 'Edit') }
      ], sRes.data, {
        empty: { title: 'No services yet', hint: 'Add what you sell, with your cost and the customer price.' }
      }), null, true));

    page._cats = cats;
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Catalogue'), el('h1', {}, 'Services'),
        el('p', {}, 'Two prices per service: what it costs you, and what you charge.')),
      el('div', { class: 'page-actions' },
        ctx.can('services.edit')
          ? el('button', { class: 'btn', onclick: () => categoryForm(load) }, 'Add category') : null,
        ctx.can('services.edit')
          ? el('button', { class: 'btn primary',
              onclick: () => serviceForm(null, page._cats || [], load, ctx.can('costs.view'), page._cur) }, 'Add service') : null)
    ),
    host
  );

  await load();
  return page;
}

function serviceForm(existing, cats, onDone, showCosts = true, cur = 'BDT') {
  const fields = formFields([
    { name: 'name', label: 'Service name', required: true, span: 2 },
    { name: 'category_id', label: 'Category', type: 'select',
      options: [{ value: '', label: '—' }, ...cats.map(c => ({ value: c.id, label: c.name }))] },
    { name: 'unit', label: 'Unit', hint: 'pcs, year, month, hour' },
    showCosts ? { name: 'cost_primary', label: 'My cost (' + cur + ')', type: 'number' } : null,
    { name: 'sell_primary', label: 'Customer price (' + cur + ')', type: 'number' },
    { name: 'show_in_list', label: 'Include in service list message', type: 'checkbox' },
    { name: 'is_active', label: 'Active', type: 'checkbox' },
    { name: 'description', label: 'Description', type: 'textarea', span: 2 }
  ], existing || { unit: 'pcs', show_in_list: 1, is_active: 1 });

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      if (existing) await api.patch('/services/' + existing.id, body);
      else await api.post('/services', body);
      work.forgetServices();   // so the next invoice sees it
      toast(existing ? 'Service updated' : 'Service added');
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Add service');

  const m = modal({
    title: existing ? 'Edit ' + existing.name : 'Add service', body: fields, wide: true,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

function categoryForm(onDone) {
  const fields = formFields([{ name: 'name', label: 'Category name', required: true, span: 2 }]);
  const save = el('button', { class: 'btn primary', onclick: async () => {
    try { await api.post('/service-categories', readFields(fields)); toast('Category added'); m.close(); onDone(); }
    catch (e) { fail(e); }
  } }, 'Add category');
  const m = modal({ title: 'Add category', body: fields,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save] });
}

/* ============================================================= suppliers */

export async function suppliers(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let query = '';

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/suppliers', { q: query, per_page: 100 });
    fill(host, card(null,
      table([
        { key: 'name', label: 'Supplier', render: r => el('div',
            { style: 'display:flex;align-items:center;gap:10px' }, avatar(r), el('div', {},
            el('strong', {}, r.name),
            el('span', { class: 'sub' }, r.services || '—'))) },
        { key: 'phone', label: 'Phone', render: r => r.phone || '—' },
        { key: 'payable', label: 'I owe', num: true, render: r =>
            Number(r.payable) > 0
              ? el('strong', { style: 'color:var(--out)' }, money(r.payable, r.default_currency))
              : el('span', { style: 'color:var(--faint)' }, '—') },
        { key: 'advance_paid', label: 'Paid ahead', num: true, render: r =>
            Number(r.advance_paid) > 0
              ? el('span', { style: 'color:var(--in)' }, money(r.advance_paid, r.default_currency))
              : el('span', { style: 'color:var(--faint)' }, '—') },
        { key: 'pending_works', label: 'Work with them', num: true, render: r =>
            Number(r.pending_works)
              ? el('div', {}, el('span', { class: 'tag hold' }, r.pending_works),
                  Number(r.pending_work_value)
                    ? el('span', { class: 'sub' }, money(r.pending_work_value)) : null)
              : '—' },
        { key: 'act', label: '', render: r => el('button',
            { class: 'btn sm', onclick: () => ctx.go('#/suppliers/' + r.id) }, 'Open') }
      ], res.data, {
        onRowClick: r => ctx.go('#/suppliers/' + r.id),
        empty: { title: 'No suppliers yet', hint: 'Add the people you pass work to.' }
      }), null, true));
  };

  const search = el('input', { type: 'search', placeholder: 'Search name, skill, work…',
    oninput: e => { query = e.target.value; pageNo = 1; clearTimeout(search._t); search._t = setTimeout(load, 250); } });

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Directory'), el('h1', {}, 'Suppliers')),
      el('div', { class: 'page-actions' },
        el('a', { class: 'btn', href: '#/who-does-what' }, 'Who does what'),
        el('button', { class: 'btn primary', onclick: () => supplierForm(null, load) }, 'Add supplier'))
    ),
    el('div', { class: 'filters', style: 'margin-bottom:14px' }, search),
    host
  );

  load();
  return page;
}

function supplierForm(existing, onDone) {
  const fields = formFields([
    { name: 'name', label: 'Name', required: true, span: 2 },
    { name: 'company_name', label: 'Company', span: 2 },
    { name: 'phone', label: 'Phone' },
    { name: 'whatsapp', label: 'WhatsApp' },
    { name: 'email', label: 'Email', type: 'email' },
    { name: 'payment_terms', label: 'Payment terms', span: 2 }
  ], existing || {});

  // which of your own services this supplier provides
  const servicePicker = el('div', {
    style: 'display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;'
         + 'max-height:200px;overflow:auto;border:1px solid var(--rule);'
         + 'border-radius:var(--radius-sm);padding:10px'
  }, loading('…'));

  const skillWrap = el('label', { class: 'field' },
    el('span', {}, 'What they do for me'),
    el('span', { class: 'hint', style: 'margin:0 0 6px' },
      'Tick the services this supplier provides. These are your own services.'),
    servicePicker);

  (async () => {
    try {
      const res = await api.get('/services', { per_page: 200 });
      const chosen = new Set(((existing && existing.services) || []).map(s2 => Number(s2.service_id ?? s2.id)));
      fill(servicePicker, ...res.data.map(sv => el('label', {
        style: 'display:flex;align-items:center;gap:7px;font-size:13px;font-weight:400'
      },
        el('input', {
          type: 'checkbox', value: sv.id, style: 'width:auto',
          checked: chosen.has(Number(sv.id)) ? true : undefined
        }),
        sv.name)));
    } catch (e) {
      fill(servicePicker, el('span', { style: 'color:var(--muted)' }, 'Could not load your services.'));
    }
  })();

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      const serviceIds = [...servicePicker.querySelectorAll('input:checked')].map(i => Number(i.value));
      body.service_ids = serviceIds;
      if (existing) {
        await api.patch('/suppliers/' + existing.id, body);
      } else {
        await api.post('/suppliers', body);
      }
      toast(existing ? 'Supplier updated' : 'Supplier added');
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Add supplier');

  const m = modal({
    title: existing ? 'Edit ' + existing.name : 'Add supplier',
    body: el('div', {}, fields,
      existing ? photoPicker('supplier', existing) : null,
      skillWrap), wide: true,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

export async function supplierDetail(ctx) {
  const id = ctx.params[0];
  const reload = () => ctx.go('#/suppliers/' + id, true);
  const [sRes, sumRes] = await Promise.all([
    api.get('/suppliers/' + id),
    api.get('/suppliers/' + id + '/summary', {})
  ]);
  const s = sRes.data, sum = sumRes.data;

  return el('div', { class: 'page' },
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, s.code || 'Supplier'),
        el('h1', {}, s.name),
        el('p', {}, sum.services_list || '—')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn', onclick: () => viewStatement('supplier', s, id) }, 'Statement'),
        ctx.can('messages.send')
          ? el('button', { class: 'btn', onclick: () => statementForm('supplier', s, id) }, 'Send statement')
          : null,
        el('button', { class: 'btn', onclick: () => supplierForm(s, reload) }, 'Edit'),
        ctx.can('jobs.edit')
          ? el('button', { class: 'btn', onclick: () => giveWorkForm(s, reload) }, 'Give them work')
          : null,
        el('a', { class: 'btn', href: '#/work?side=supplier&party=' + id }, 'Their work'),
        el('button', { class: 'btn primary',
          onclick: () => ctx.go('#/supplier-bills?supplier=' + s.id) }, 'Bills & payments'))
    ),

    el('div', { class: 'grid c4', style: 'margin-bottom:14px' },
      tile('I owe them', byCurrency(sum.by_currency, 'payable'), 'unpaid bills', 'out'),
      tile('Paid so far', byCurrency(sum.by_currency, 'paid'), 'against their bills', 'in'),
      tile('Paid ahead', byCurrency(sum.advance || []),
        'not yet set against a bill', Number((sum.advance || [])[0]?.amount) ? 'hold' : ''),
      tile('Work with them', String(sum.works.pending || 0),
        (sum.works.completed || 0) + ' finished', Number(sum.works.pending) ? 'hold' : '')
    ),

    card('Work in hand',
      table([
        { key: 'job_no', label: 'Job', render: r => el('div', {},
            el('strong', {}, r.job_title),
            el('span', { class: 'sub' }, r.customer_name)) },
        { key: 'work_detail', label: 'Task', render: r => r.work_detail || '—' },
        { key: 'agreed_cost', label: 'Agreed', num: true, render: r => money(r.agreed_cost, r.currency) },
        { key: 'due_date', label: 'Due', render: r => date(r.due_date) },
        { key: 'status', label: 'Status', render: r => tag(r.status) }
      ], s.pending_works, { empty: { title: 'No open work', hint: 'Assign work from a job.' } }), null, true),

    card('Services they cover',
      table([
        { key: 'name', label: 'Service' },
        { key: 'supplier_cost', label: 'Their price', num: true, render: r => money(r.supplier_cost, r.currency) },
        { key: 'is_preferred', label: '', render: r => Number(r.is_preferred) ? tag('preferred') : '' }
      ], s.services, { empty: { title: 'Not linked to any service', hint: 'Link them from a service to remember their price.' } }), null, true),

    card('Account',
      table([
        { key: 'entry_date', label: 'Date', render: r => date(r.entry_date) },
        { key: 'ref_no', label: 'Reference', render: r => el('span', { class: 'doc-no' }, r.ref_no) },
        { key: 'type', label: 'Type', render: r => tag(r.type) },
        { key: 'credit', label: 'Billed', num: true, render: r => Number(r.credit) ? money(r.credit, r.currency) : '—' },
        { key: 'debit', label: 'Paid', num: true, render: r =>
            Number(r.debit) ? el('span', { style: 'color:var(--in)' }, money(r.debit, r.currency)) : '—' }
      ], sum.ledger, { empty: { title: 'No entries yet', hint: 'Bills and payments will show here.' } }), null, true),

    card('Their papers and files',
      el('div', {},
        el('p', { style: 'color:var(--muted);margin-top:0' },
          'Agreements, trade licence, price lists — anything you hold for them.'),
        fileManager(api, 'supplier', Number(id), { canEdit: ctx.can('suppliers.edit') })))
  );
}


/**
 * Hand a supplier a piece of work. Either it belongs to a job you are
 * already doing, or you describe it and the system opens a small job to
 * hold it — so the cost still lands against a customer.
 */
async function giveWorkForm(supplier, onDone) {
  const [sups, jobs, custs] = await Promise.all([
    supplier ? Promise.resolve(null) : api.get('/suppliers', { per_page: 200 }).then(r => r.data),
    api.get('/jobs', { status: 'pending', per_page: 100 }).then(r => r.data).catch(() => []),
    work.customerOptions()
  ]);

  const fields = formFields([
    ...(supplier ? [] : [
      { name: 'supplier_id', label: 'Which supplier', type: 'select', required: true, span: 2,
        options: [{ value: '', label: 'Choose…' },
          ...sups.map(s2 => ({ value: s2.id, label: join(s2.name, s2.services) }))] }
    ]),
    { name: 'work_detail', label: 'What they must do', required: true, span: 2,
      hint: 'Logo design, domain registration, banner artwork…' },
    { name: 'job_id', label: 'Part of which job', type: 'select', span: 2,
      options: [{ value: '', label: 'Not part of a job — I will say who it is for' },
        ...jobs.map(j => ({ value: j.id,
          label: j.job_no + ' — ' + first(j.company_name, j.customer_name) + ' · ' + j.title }))] },
    { name: 'customer_id', label: 'Who it is for', type: 'select', span: 2,
      options: [{ value: '', label: 'Choose…' }, ...custs],
      hint: 'Only needed when it is not part of a job above' },
    { name: 'agreed_cost', label: 'Agreed cost', type: 'number' },
    { name: 'due_in_days', label: 'Wanted in (days)', type: 'number',
      hint: 'Or pick an exact date below' },
    { name: 'due_date', label: 'Wanted by', type: 'date', span: 2 },
    { name: 'note', label: 'Anything else', type: 'textarea', span: 2 }
  ]);

  // choosing a job makes the customer question unnecessary
  const jobSel = fields.querySelector('[name=job_id]');
  const custSel = fields.querySelector('[name=customer_id]');
  const syncCustomer = () => {
    const onJob = !!jobSel.value;
    custSel.disabled = onJob;
    custSel.closest('.field').style.opacity = onJob ? '.5' : '1';
  };
  jobSel.addEventListener('change', syncCustomer);
  syncCustomer();

  const save = el('button', { class: 'btn primary', onclick: async () => {
    const body = readFields(fields);
    if (supplier) body.supplier_id = supplier.id;
    if (!body.supplier_id) { toast('Pick a supplier', 'err'); return; }
    if (!body.work_detail) { toast('Say what they must do', 'err'); return; }
    if (!body.job_id && !body.customer_id) {
      toast('Say who it is for, or pick a job', 'err');
      return;
    }
    save.disabled = true;
    try {
      const res = await api.post('/give-work', body);
      toast(res.message);
      m.close(); onDone && onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Give it to them');

  const m = modal({
    title: supplier ? 'Give work to ' + supplier.name : 'Give work to a supplier',
    body: fields, wide: true,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

/** Giving work to anyone, reached from the menu. */
export async function giveWork(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/work/suppliers', { status: 'pending', per_page: 100 });

    fill(host, card('Work you are waiting on', table([
      { key: 'supplier_name', label: 'Supplier', render: r => el('div', {},
          el('strong', {}, r.supplier_name),
          el('span', { class: 'sub' }, r.job_no || '')) },
      { key: 'work_detail', label: 'What they are doing' },
      { key: 'due_date', label: 'Wanted by', render: r => r.due_date
          ? el('div', {}, date(r.due_date), el('span', { class: 'sub' }, daysPhrase(r.days_to_due)))
          : '—' },
      { key: 'agreed_cost', label: 'Agreed', num: true, render: r => money(r.agreed_cost) },
      { key: 'status', label: '', render: r => tag(r.status) },
      { key: 'act', label: '', render: r => ctx.can('jobs.edit')
          ? el('button', { class: 'btn sm', onclick: async () => {
              try {
                await api.patch('/jobs/' + r.job_id + '/suppliers/' + r.id, { status: 'completed' });
                toast('Marked done'); load();
              } catch (e) { fail(e); }
            } }, 'Done')
          : null }
    ], res.data, {
      empty: {
        title: 'Nothing out with a supplier',
        hint: 'Give someone a piece of work and it appears here until they finish it.'
      }
    }), null, true));
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Work'),
        el('h1', {}, 'Give work'),
        el('p', {}, 'Hand a piece of work to a supplier and keep track of it.')),
      el('div', { class: 'page-actions' },
        ctx.can('jobs.edit')
          ? el('button', { class: 'btn primary', onclick: () => giveWorkForm(null, load) },
              'Give work to a supplier')
          : null)),
    host
  );

  load();
  return page;
}

/* ========================================================= who does what */

export async function whoDoesWhat(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let side = ctx.query.get('by') === 'service' ? 'service' : 'person';

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/who-does-what');
    const d = res.data;

    if (side === 'person') {
      fill(host, card(null, table([
        { key: 'name', label: 'Supplier', render: r => el('div', {},
            el('strong', {}, first(r.company_name, r.name)),
            el('span', { class: 'sub' }, join(r.company_name ? r.name : null, r.phone))) },
        { key: 'services', label: 'What they do for me', render: r => r.services
            ? el('span', {}, r.services)
            : el('span', { style: 'color:var(--faint)' }, 'no services ticked yet') },
        { key: 'open_work', label: 'Work with them', num: true, render: r =>
            Number(r.open_work) ? el('span', { class: 'tag hold' }, r.open_work) : '—' },
        { key: 'act', label: '', render: r =>
            el('a', { class: 'btn sm', href: '#/suppliers/' + r.id }, 'Open') }
      ], d.by_person, {
        empty: { title: 'No suppliers yet', hint: 'Add one and tick the services they provide.' }
      }), null, true));
      return;
    }

    fill(host, card(null, table([
      { key: 'name', label: 'Service', render: r => el('div', {},
          el('strong', {}, r.name),
          el('span', { class: 'sub' }, r.category_name || '')) },
      { key: 'suppliers', label: 'Who can do it', render: r => r.suppliers
          ? el('span', {}, r.suppliers)
          : el('span', { style: 'color:var(--faint)' }, 'nobody linked yet') },
      { key: 'supplier_count', label: 'People', num: true },
      { key: 'cheapest', label: 'Cheapest', num: true, render: r =>
          Number(r.cheapest) ? money(r.cheapest) : '—' }
    ], d.by_service, {
      empty: { title: 'No services yet', hint: 'Add services, then tick who provides them.' }
    }), null, true));
  };

  const switchSide = which => {
    side = which;
    page.querySelectorAll('[data-side]').forEach(b =>
      b.setAttribute('aria-pressed', String(b.dataset.side === which)));
    load();
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Catalogue'),
        el('h1', {}, 'Who does what'),
        el('p', {}, 'Who covers which of your services — and who you can call for a given job.')),
      el('div', { class: 'page-actions' },
        el('div', { class: 'tab-strip' },
          el('button', { dataset: { side: 'person' }, 'aria-pressed': String(side === 'person'),
            onclick: () => switchSide('person') }, 'By person'),
          el('button', { dataset: { side: 'service' }, 'aria-pressed': String(side === 'service'),
            onclick: () => switchSide('service') }, 'By service')))),
    host
  );

  load();
  return page;
}

function statementForm(partyType, party, id) {
  const fields = formFields([
    { name: 'channel', label: 'Send on', type: 'select', span: 2, options: [
        { value: 'whatsapp', label: 'WhatsApp' },
        { value: 'telegram', label: 'Telegram' },
        { value: 'email', label: 'Email' }] }
  ]);

  const preview = el('pre', {
    style: 'background:var(--rule-soft);padding:12px;border-radius:6px;font-size:12.5px;'
         + 'white-space:pre-wrap;margin:14px 0 0'
  }, 'Press Send to work out their figures and deliver them.');

  const send = el('button', { class: 'btn primary', onclick: async () => {
    send.disabled = true;
    try {
      const res = await api.post('/messages/statement',
        { party_type: partyType, party_id: Number(id), ...readFields(fields) });
      preview.textContent = res.data.body;
      toast(res.message, res.data.result.success ? 'ok' : 'err');
    } catch (e) { fail(e); }
    send.disabled = false;
  } }, 'Send');

  const m = modal({
    title: 'Statement for ' + (party.company_name || party.name),
    body: el('div', {}, fields, preview),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Close'), send]
  });
}

/**
 * Their statement on screen: what was billed, what was paid, what is left.
 * The same figures the message would carry, laid out to read and to print.
 */
async function viewStatement(partyType, party, id) {
  const host = el('div', {}, loading());
  const m = modal({
    title: 'Statement — ' + first(party.company_name, party.name),
    wide: true, body: host,
    footer: [
      el('button', { class: 'btn', onclick: () => window.print() }, 'Print'),
      el('button', { class: 'btn', onclick: () => m.close() }, 'Close')
    ]
  });

  try {
    const isCustomer = partyType === 'customer';
    const res = await api.get(isCustomer ? '/customers/' + id + '/overview'
                                         : '/suppliers/' + id + '/summary');
    const d = res.data;

    const money0 = rows => byCurrency(rows || []);
    const ledger = d.ledger || [];

    const owed = isCustomer
      ? (d.money || []).reduce((sum, r) => sum + Number(r.due || 0), 0)
      : (d.by_currency || []).reduce((sum, r) => sum + Number(r.payable || 0), 0);

    fill(host,
      el('div', { class: 'grid c4', style: 'margin-bottom:16px' },
        tile(isCustomer ? 'Billed to them' : 'They billed me',
          isCustomer ? money0((d.money || []).map(r => ({ amount: r.invoiced })))
                     : money0((d.by_currency || []).map(r => ({ amount: r.billed })))),
        tile(isCustomer ? 'They paid' : 'I paid',
          isCustomer ? money0((d.money || []).map(r => ({ amount: r.paid })))
                     : money0((d.by_currency || []).map(r => ({ amount: r.paid }))), null, 'in'),
        tile(isCustomer ? 'Still due' : 'I still owe', money(owed), null, owed > 0 ? 'out' : 'in'),
        tile(isCustomer ? 'Advance held' : 'Paid ahead',
          money0(d.advance || []), 'not set against a bill',
          Number((d.advance || [])[0]?.amount) ? 'hold' : '')),

      card('Every entry',
        table([
          { key: 'entry_date', label: 'Date', render: r => date(r.entry_date) },
          { key: 'ref_no', label: 'Reference', render: r => el('div', {},
              el('span', { class: 'doc-no' }, r.ref_no),
              // what the money was for, so a line can be recognised months later
              r.what_for ? el('span', { class: 'sub' }, r.what_for) : null) },
          { key: 'type', label: 'What', render: r => tag(r.type) },
          { key: 'method', label: 'How', render: r => r.method
              ? el('div', {}, r.method,
                  r.account_label ? el('span', { class: 'sub' }, r.account_label) : null)
              : '—' },
          { key: 'debit', label: isCustomer ? 'Billed' : 'They billed', num: true,
            render: r => Number(isCustomer ? r.debit : r.credit)
              ? money(isCustomer ? r.debit : r.credit) : '—' },
          { key: 'credit', label: isCustomer ? 'Paid' : 'I paid', num: true,
            render: r => Number(isCustomer ? r.credit : r.debit)
              ? el('span', { style: 'color:var(--in)' },
                  money(isCustomer ? r.credit : r.debit)) : '—' }
        ], ledger, {
          empty: { title: 'Nothing yet', hint: 'Bills and payments will appear here.' }
        }), null, true),

      isCustomer
        ? el('div', { style: 'margin-top:8px' },
            el('button', { class: 'btn', onclick: () => { m.close(); paymentStatement(party, id); } },
              'Just the payments, in detail'))
        : null
    );
  } catch (e) {
    fail(e);
    fill(host, el('div', { class: 'empty' }, 'Could not build the statement.'));
  }
}

/** A face in the list, or their initials when there is no picture yet. */
function avatar(row, size = 34) {
  const name = String(row.company_name || row.name || '?').trim();
  const initials = name.split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();

  if (row.photo_url) {
    return el('img', {
      src: row.photo_url, alt: '',
      style: 'width:' + size + 'px;height:' + size + 'px;border-radius:50%;'
           + 'object-fit:cover;border:1px solid var(--rule);flex:none'
    });
  }
  return el('span', {
    style: 'width:' + size + 'px;height:' + size + 'px;border-radius:50%;flex:none;'
         + 'display:inline-flex;align-items:center;justify-content:center;'
         + 'background:var(--rule-soft);color:var(--muted);font-size:' + Math.round(size / 2.6)
         + 'px;font-weight:650;border:1px solid var(--rule)'
  }, initials || '?');
}

/** Choose, replace or remove their picture. */
function photoPicker(party, row, onChange) {
  const host = el('div', { style: 'display:flex;align-items:center;gap:12px' });

  const draw = () => fill(host,
    avatar(row, 56),
    el('div', { style: 'display:flex;flex-direction:column;gap:6px' },
      el('input', {
        type: 'file', accept: '.jpg,.jpeg,.png,.webp',
        onchange: async e => {
          const file = e.target.files && e.target.files[0];
          if (!file || !row.id) return;
          const fd = new FormData();
          fd.append('file', file);
          try {
            const res = await fetch(api.base + '/photo/' + party + '/' + row.id, {
              method: 'POST',
              headers: { Authorization: 'Bearer ' + api.store.access },
              body: fd
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message);
            row.photo_url = data.data.photo_url;
            draw();
            toast('Picture saved');
            onChange && onChange();
          } catch (err) { toast(err.message || 'Could not upload', 'err'); }
        }
      }),
      row.photo_url
        ? el('button', { class: 'btn sm danger', onclick: async () => {
            try {
              await api.del('/photo/' + party + '/' + row.id);
              row.photo_url = null;
              draw();
              onChange && onChange();
            } catch (e) { fail(e); }
          } }, 'Remove picture')
        : el('span', { class: 'hint' }, 'JPG or PNG — it is only shown to you')));

  draw();
  return el('label', { class: 'field', style: 'grid-column:1/-1' },
    el('span', {}, 'Picture'), host);
}

/**
 * Just the payments, spelled out: when it came, how it came, and which work
 * it paid for. This is the page to hand a customer who asks "what have I
 * actually paid you for?"
 */
async function paymentStatement(party, id) {
  const host = el('div', {}, loading());
  const m = modal({
    title: 'Payments — ' + first(party.company_name, party.name),
    wide: true, body: host,
    footer: [
      el('button', { class: 'btn', onclick: () => window.print() }, 'Print'),
      el('button', { class: 'btn', onclick: () => m.close() }, 'Close')
    ]
  });

  try {
    const res = await api.get('/customers/' + id + '/payments');
    const d = res.data;

    fill(host,
      el('div', { class: 'grid c3', style: 'margin-bottom:16px' },
        tile('Received', money(d.received), d.payments.length + ' payment(s)', 'in'),
        tile('Still due', money(d.still_due), 'across their invoices',
          Number(d.still_due) > 0 ? 'out' : 'in'),
        tile('Sitting on account', money(d.on_account), 'not yet set against work',
          Number(d.on_account) > 0 ? 'hold' : '')),

      d.by_method.length
        ? card('How they pay',
            table([
              { key: 'method', label: 'Way of paying' },
              { key: 'times', label: 'Times', num: true },
              { key: 'total', label: 'Total', num: true, render: r => money(r.total) }
            ], d.by_method), null, true)
        : null,

      card('Every payment',
        d.payments.length
          ? el('div', {},
              ...d.payments.map(p => el('div', {
                style: 'padding:14px 16px;border-bottom:1px solid var(--rule-soft)'
              },
                el('div', {
                  style: 'display:flex;justify-content:space-between;gap:12px;align-items:baseline'
                },
                  el('div', {},
                    el('strong', { class: 'doc-no' }, p.receipt_no),
                    el('span', { class: 'sub' },
                      join(date(p.payment_date), p.method, p.account_label, p.reference))),
                  el('strong', { class: 'num', style: 'color:var(--in);font-size:15px' },
                    money(p.amount))),

                // which work this money went towards
                p.towards && p.towards.length
                  ? el('ul', { style: 'margin:8px 0 0;padding-left:18px;color:var(--muted);font-size:13px' },
                      ...p.towards.map(t => el('li', {},
                        el('span', {}, first(t.subject, t.invoice_no)),
                        el('span', { style: 'color:var(--faint)' },
                          ' — ' + money(t.amount)
                          + (t.subject ? ' (' + t.invoice_no + ')' : '')))))
                  : el('p', { style: 'margin:8px 0 0;color:var(--hold);font-size:13px' },
                      'Not set against any invoice yet — held on account.'),

                Number(p.unallocated_amount) > 0 && p.towards && p.towards.length
                  ? el('p', { style: 'margin:6px 0 0;color:var(--hold);font-size:12.5px' },
                      money(p.unallocated_amount) + ' of this is still unapplied.')
                  : null)))
          : el('div', { class: 'empty' },
              el('strong', {}, 'No payments yet'),
              el('div', {}, 'Money you receive from them will be listed here.')),
        null, true)
    );
  } catch (e) {
    fail(e);
    fill(host, el('div', { class: 'empty' }, 'Could not build the statement.'));
  }
}
