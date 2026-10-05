/* AH5 Office — jobs, quotations, invoices, payments, supplier bills
   Designed & Developed by Anwar Hossain — https://anwar.com.bd */

import { api } from '../api.js';
import {
  el, table, card, tile, money, qty, date, today, addDays, tag, modal,
  formFields, readFields, toast, fail, confirmAction, loading, daysPhrase, pager, first, join, fileManager, fill, rowPicker
} from '../ui.js';


let cacheCustomers = null, cacheServices = null, cacheSuppliers = null, cacheSettings = null;

async function settings() {
  if (!cacheSettings) {
    cacheSettings = await api.get('/settings').then(r => r.data).catch(() => ({}));
  }
  return cacheSettings;
}


export async function customerOptions() {
  if (!cacheCustomers) cacheCustomers = (await api.get('/customers', { per_page: 200 })).data;
  return cacheCustomers.map(c => ({ value: c.id, label: c.company_name || c.name }));
}
/**
 * Your services. Kept in memory so forms open fast, but thrown away the
 * moment one is added or changed — otherwise a service you just created
 * would be missing from the next invoice you write.
 */
async function serviceList() {
  if (!cacheServices) cacheServices = (await api.get('/services', { per_page: 200 })).data;
  return cacheServices;
}

export function forgetServices() { cacheServices = null; }
async function supplierOptions() {
  if (!cacheSuppliers) cacheSuppliers = (await api.get('/suppliers', { per_page: 200 })).data;
  return cacheSuppliers.map(s => ({ value: s.id, label: s.name }));
}
export function clearCaches() {
  cacheCustomers = cacheServices = cacheSuppliers = cacheSettings = null;
}

/** The raw customer rows, so a form can read the currency of one. */
async function customerRows() {
  await customerOptions();
  return cacheCustomers || [];
}

/* ------------------------------------------------- reusable line editor */

/**
 * Editable line items. Price is always editable — the catalogue only fills
 * the first suggestion, it never locks the number.
 */
function lineEditor(services, currency, initial = [], showCosts = true, baseCurrency = 'BDT',
                    showWorkTick = false, onChange = () => {}) {
  const rows = el('tbody', {});
  const totalOut = el('span', {}, '0.00');

  const recalc = () => {
    let sum = 0;
    rows.querySelectorAll('tr').forEach(tr => {
      const q = Number(tr.querySelector('[name=qty]').value || 0);
      const p = Number(tr.querySelector('[name=unit_price]').value || 0);
      const t = q * p;
      sum += t;
      tr.querySelector('.row-total').textContent = t.toLocaleString('en-US',
        { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    });
    totalOut.textContent = sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    onChange();
  };

  const addRow = (data = {}) => {
    const sel = el('select', { name: 'service_id' },
      el('option', { value: '' }, 'Write it myself'),
      ...services.map(s => el('option', {
        value: s.id, selected: String(s.id) === String(data.service_id) || undefined
      }, s.name))
    );
    const desc = el('input', { name: 'description', value: data.description || '', placeholder: 'What is it for' });
    const q = el('input', { name: 'qty', type: 'number', step: '0.01', value: data.qty ?? 1, oninput: recalc });
    const price = el('input', { name: 'unit_price', class: 'mono', type: 'number', step: '0.01',
      value: data.unit_price ?? '', oninput: recalc });
    const cost = el('input', { name: 'cost_price', class: 'mono', type: 'number', step: '0.01',
      value: data.cost_price ?? '' });

    sel.addEventListener('change', () => {
      const s = services.find(x => String(x.id) === sel.value);
      if (!s) return;
      if (!desc.value) desc.value = s.name;
      // the catalogue only suggests; the price stays editable
      price.value = s.sell_primary;
      if (cost) cost.value = s.cost_primary;
      recalc();
    });

    const work = el('input', { type: 'checkbox', checked: data.to_work ? true : undefined });

    const tr = el('tr', {},
      el('td', { style: 'width:190px' }, sel),
      el('td', {}, desc),
      el('td', { style: 'width:82px' }, q),
      el('td', { style: 'width:112px' }, price),
      showCosts ? el('td', { style: 'width:112px' }, cost) : null,
      showWorkTick ? el('td', { class: 'tick', style: 'width:56px' }, work) : null,
      el('td', { style: 'width:96px' }, el('span', { class: 'row-total' }, '0.00')),
      el('td', { style: 'width:34px' },
        el('button', { class: 'icon-btn', title: 'Remove line',
          onclick: () => { tr.remove(); recalc(); } }, '\u00d7'))
    );
    rows.append(tr);
    recalc();
  };

  (initial.length ? initial : [{}]).forEach(addRow);

  const node = el('div', {},
    el('table', { class: 'lines' },
      el('thead', {}, el('tr', {},
        el('th', {}, 'Service'), el('th', {}, 'Description'), el('th', {}, 'Qty'),
        el('th', {}, 'Price'), showCosts ? el('th', {}, 'My cost') : null,
        showWorkTick ? el('th', { style: 'text-align:center' }, 'To do') : null,
        el('th', { style: 'text-align:right' }, 'Amount'), el('th', {})
      )),
      rows
    ),
    el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-top:10px' },
      el('button', { class: 'btn sm', onclick: () => addRow() }, '+ Add line'),
      el('div', { class: 'amount', style: 'font-size:16px;font-weight:600' }, 'Subtotal ', totalOut))
  );

  node.readLines = () => [...rows.querySelectorAll('tr')].map(tr => {
    const get = n => {
      const node = tr.querySelector('[name=' + n + ']');
      return node ? node.value : '';
    };
    const tick = tr.querySelector('.tick input');
    return {
      service_id: get('service_id') || null,
      description: get('description'),
      qty: get('qty') || 1,
      unit_price: get('unit_price'),
      cost_price: get('cost_price'),
      to_work: tick ? tick.checked : undefined
    };
  }).filter(l => l.description || l.service_id);

  node.recalc = recalc;
  return node;
}


/* ============================================================ work list */

/**
 * What is outstanding, from both sides:
 *   - work customers gave me
 *   - work I passed to suppliers
 * Either everything at once, or one party at a time.
 */
export async function workList(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  const canSuppliers = ctx.can('suppliers.view');

  let side   = ctx.query.get('side') === 'supplier' && canSuppliers ? 'supplier' : 'customer';
  let status = ctx.query.get('status') || 'pending';
  let partyId = ctx.query.get('party') || '';
  let query   = '';
  let pageNo  = 1;

  const partyList = el('div', {
    style: 'max-height:460px;overflow:auto;border:1px solid var(--rule);border-radius:6px;background:var(--surface)'
  });

  const loadParties = async () => {
    fill(partyList, loading('…'));
    const res = await api.get(side === 'supplier' ? '/work/by-supplier' : '/work/by-customer');
    const rows = res.data.rows;

    const line = (label, id, pending, done, extra) => el('button', {
      class: 'btn',
      style: 'display:block;width:100%;text-align:left;border:0;border-bottom:1px solid var(--rule-soft);'
           + 'border-radius:0;padding:10px 14px;'
           + (String(id) === String(partyId) ? 'background:var(--rule-soft);font-weight:600' : ''),
      onclick: () => { partyId = String(id) === String(partyId) ? '' : String(id); pageNo = 1; loadParties(); load(); }
    },
      el('div', { style: 'display:flex;justify-content:space-between;gap:10px' },
        el('span', {}, label),
        el('span', { class: 'num', style: 'color:' + (Number(pending) ? 'var(--out)' : 'var(--faint)') },
          pending + ' / ' + (Number(pending) + Number(done)))),
      extra ? el('span', { class: 'sub' }, extra) : null
    );

    fill(partyList, 
      line('Everyone', '', res.data.summary.counts.pending || 0, res.data.summary.counts.done || 0,
        'all work in one list'),
      ...rows.map(r => line(
        first(r.company_name, r.name), r.id, r.pending, r.done,
        side === 'supplier'
          ? (Number(r.pending_cost) ? money(r.pending_cost) + ' outstanding' : null)
          : (Number(r.pending_value) ? money(r.pending_value) + ' of work left' : null)
      ))
    );
  };

  const customerColumns = () => [
    { key: 'description', label: 'Task', render: r => el('div', {},
        el('strong', {}, r.description),
        el('span', { class: 'sub' }, join(r.job_no, r.job_title))) },
    { key: 'customer_name', label: 'Customer', render: r => first(r.company_name, r.customer_name) },
    { key: 'given_to', label: 'Doing it', render: r => r.given_to
        ? el('span', { class: 'tag' }, r.given_to)
        : el('span', { style: 'color:var(--muted)' }, 'me') },
    { key: 'due_date', label: 'Deliver by', render: r => r.due_date
        ? el('div', {}, date(r.due_date),
            el('span', { class: 'sub', style: Number(r.days_to_due) < 0 ? 'color:var(--out)' : '' },
              daysPhrase(r.days_to_due)))
        : '—' },
    { key: 'line_total', label: 'Value', num: true, render: r => money(r.line_total, r.currency) },
    { key: 'status', label: '', render: r => tag(r.status) },
    { key: 'act', label: '', render: r => r.status === 'completed'
        ? el('button', { class: 'btn sm', onclick: () => mark(r, 'pending') }, 'Reopen')
        : el('button', { class: 'btn sm', onclick: () => mark(r, 'completed') }, 'Mark done') }
  ];

  const supplierColumns = () => [
    { key: 'work_detail', label: 'Task', render: r => el('div', {},
        el('strong', {}, first(r.work_detail, r.item_description, r.job_title)),
        el('span', { class: 'sub' }, join(r.job_no, 'for ' + first(r.company_name, r.customer_name)))) },
    { key: 'supplier_name', label: 'Supplier' },
    { key: 'due_date', label: 'Wanted by', render: r => r.due_date
        ? el('div', {}, date(r.due_date),
            el('span', { class: 'sub', style: Number(r.days_to_due) < 0 ? 'color:var(--out)' : '' },
              daysPhrase(r.days_to_due)))
        : '—' },
    ...(ctx.can('costs.view')
      ? [{ key: 'agreed_cost', label: 'Agreed', num: true, render: r => money(r.agreed_cost, r.currency) }]
      : []),
    { key: 'is_billed', label: 'Billed', render: r => Number(r.is_billed) ? tag('yes') : tag('pending') },
    { key: 'status', label: '', render: r => tag(r.status) },
    { key: 'act', label: '', render: r => r.status === 'completed'
        ? el('button', { class: 'btn sm', onclick: () => mark(r, 'in_progress') }, 'Reopen')
        : el('button', { class: 'btn sm', onclick: () => mark(r, 'completed') }, 'Mark done') }
  ];

  const mark = async (row, status) => {
    try {
      if (side === 'supplier') {
        await api.patch('/jobs/' + row.job_id + '/suppliers/' + row.id, { status });
      } else {
        await api.patch('/jobs/' + row.job_id + '/items/' + row.id, { status });
      }
      toast(status === 'completed' ? 'Marked done' : 'Reopened');
      loadParties(); load();
    } catch (e) { fail(e); }
  };

  const load = async () => {
    fill(host, loading());
    const params = { status, q: query, page: pageNo, per_page: 25 };
    if (partyId) {
      params[side === 'supplier' ? 'supplier_id' : 'customer_id'] = partyId;
    }
    try {
      const res = await api.get(side === 'supplier' ? '/work/suppliers' : '/work/customers', params);
      const c = res.summary.counts;

      fill(host, 
        el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
          tile('Still to do', String(c.pending || 0),
            (c.overdue || 0) + ' past the date', Number(c.overdue) ? 'out' : 'hold'),
          tile('Finished', String(c.done || 0), 'marked complete', 'in'),
          tile('All work', String(c.total || 0), side === 'supplier' ? 'given to suppliers' : 'taken from customers')),
        card(null, table(
          side === 'supplier' ? supplierColumns() : customerColumns(),
          res.data,
          { empty: {
              title: status === 'pending' ? 'Nothing outstanding' : 'Nothing here',
              hint: side === 'supplier'
                ? 'Work you pass to a supplier from a job shows up here.'
                : 'Lines from the jobs customers give you show up here.'
            } }
        ), null, true),
        pager(res.meta, p => { pageNo = p; load(); })
      );
    } catch (e) {
      fail(e);
      fill(host, el('div', { class: 'empty' }, 'Could not load the work list.'));
    }
  };

  const sidebarTitle = el('div', { class: 'eyebrow', style: 'margin-bottom:6px' },
    side === 'supplier' ? 'By supplier' : 'By customer');

  const switchSide = which => {
    side = which; partyId = ''; pageNo = 1;
    page.querySelectorAll('[data-side]').forEach(b =>
      b.setAttribute('aria-pressed', String(b.dataset.side === which)));
    sidebarTitle.textContent = which === 'supplier' ? 'By supplier' : 'By customer';
    loadParties(); load();
  };

  const search = el('input', { type: 'search', placeholder: 'Search task, job or name…',
    oninput: e => { query = e.target.value; pageNo = 1;
      clearTimeout(search._t); search._t = setTimeout(load, 250); } });

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Work'),
        el('h1', {}, 'Work list'),
        el('p', {}, 'What is still to do, and what is finished.')),
      el('div', { class: 'page-actions' },
        el('div', { class: 'tab-strip', style: 'padding:0' },
          el('button', { dataset: { side: 'customer' }, 'aria-pressed': String(side === 'customer'),
            onclick: () => switchSide('customer') }, 'From customers'),
          canSuppliers ? el('button', { dataset: { side: 'supplier' }, 'aria-pressed': String(side === 'supplier'),
            onclick: () => switchSide('supplier') }, 'Given to suppliers') : null))),

    el('div', { class: 'filters', style: 'margin-bottom:14px' },
      search,
      el('select', { onchange: e => { status = e.target.value; pageNo = 1; load(); } },
        el('option', { value: 'pending', selected: status === 'pending' || undefined }, 'Still to do'),
        el('option', { value: 'done', selected: status === 'done' || undefined }, 'Finished'),
        el('option', { value: 'all' }, 'Everything'))),

    el('div', { class: 'grid', style: 'grid-template-columns:260px 1fr;gap:16px;align-items:start' },
      el('div', {}, sidebarTitle, partyList),
      host)
  );

  loadParties();
  load();
  return page;
}

/* ================================================================= jobs */

export async function jobs(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let status = ctx.query.get('status') || '', pageNo = 1;
  const customerFilter = ctx.query.get('customer') || '';

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/jobs',
      { status, customer_id: customerFilter, page: pageNo, per_page: 25 });
    fill(host, el('div', {}, card(null,
      table([
        { key: 'job_no', label: 'Job', render: r => el('div', {},
            el('strong', {}, r.title),
            el('span', { class: 'sub' }, join(r.job_no, first(r.company_name, r.customer_name)))) },
        { key: 'received_date', label: 'Received', render: r => date(r.received_date) },
        { key: 'due_date', label: 'Deliver by', render: r => r.due_date
            ? el('div', {}, date(r.due_date),
                el('span', { class: 'sub' }, daysPhrase(r.days_to_due)))
            : '—' },
        { key: 'items', label: 'Lines', num: true, render: r => r.completed_items + ' / ' + r.item_count },
        { key: 'est_total', label: 'Value', num: true, render: r => money(r.est_total, r.currency) },
        { key: 'status', label: 'Status', render: r => tag(r.status) },
        { key: 'is_invoiced', label: 'Invoiced', render: r => Number(r.is_invoiced) ? tag('yes') : tag('pending') }
      ], res.data, {
        onRowClick: r => ctx.go('#/jobs/' + r.id),
        empty: { title: 'No jobs recorded', hint: 'Add a job when a customer gives you work.' }
      }), null, true),
      pager(res.meta, p => { pageNo = p; load(); })));
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Work'), el('h1', {}, 'Jobs'),
        el('p', {}, 'What customers have given you, and what is still open.')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn primary', onclick: () => jobForm(null, load, ctx.can('costs.view')) }, 'Add job'))
    ),
    el('div', { class: 'filters', style: 'margin-bottom:14px' },
      el('select', { onchange: e => { status = e.target.value; pageNo = 1; load(); } },
        el('option', { value: '' }, 'All jobs'),
        el('option', { value: 'pending,in_progress,on_hold' }, 'Open only'),
        el('option', { value: 'completed' }, 'Completed'),
        el('option', { value: 'delivered' }, 'Delivered'))),
    host
  );

  load();
  return page;
}

async function jobForm(existing, onDone, showCosts = true) {
  const [custOpts, svc, cfg, rows] = await Promise.all([
    customerOptions(), serviceList(), settings(), customerRows()]);
  const base = cfg.base_currency || 'BDT';

  const fields = formFields([
    { name: 'customer_id', label: 'Customer', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...custOpts], span: 2 },
    { name: 'title', label: 'Job title', required: true, span: 2 },
    { name: 'received_date', label: 'Received on', type: 'date', value: today() },
    { name: 'due_date', label: 'Deliver by', type: 'date' },
    { name: 'priority', label: 'Priority', type: 'select', options: [
        { value: 'normal', label: 'Normal' }, { value: 'high', label: 'High' },
        { value: 'urgent', label: 'Urgent' }, { value: 'low', label: 'Low' }] },
    { name: 'status', label: 'Status', type: 'select', options: [
        { value: 'pending', label: 'Pending' }, { value: 'in_progress', label: 'In progress' },
        { value: 'on_hold', label: 'On hold' }, { value: 'completed', label: 'Completed' },
        { value: 'delivered', label: 'Delivered' }, { value: 'cancelled', label: 'Cancelled' }] },
    { name: 'description', label: 'Details', type: 'textarea', span: 2 }
  ], existing || { received_date: today() });

  const lines = lineEditor(svc, () => base, existing ? (existing.items || []) : [], showCosts, base);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      body.items = lines.readLines();
      if (existing) {
        await api.patch('/jobs/' + existing.id, body);
        toast('Job updated');
      } else {
        const res = await api.post('/jobs', body);
        toast('Job ' + res.data.job_no + ' added');
      }
      m.close(); onDone && onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Add job');

  const m = modal({
    title: existing ? 'Edit ' + existing.job_no : 'Add job', wide: true,
    body: el('div', {}, fields,
      el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px' },
        'What needs doing'),
      lines),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

export async function jobDetail(ctx) {
  const id = ctx.params[0];
  const res = await api.get('/jobs/' + id);
  const j = res.data;

  const reload = () => ctx.go('#/jobs/' + id, true);

  const setItem = async (itemId, status) => {
    try { await api.patch('/jobs/' + id + '/items/' + itemId, { status }); toast('Line marked ' + status); reload(); }
    catch (e) { fail(e); }
  };

  const setAssign = async (assignId, status) => {
    try { await api.patch('/jobs/' + id + '/suppliers/' + assignId, { status }); toast('Marked ' + status); reload(); }
    catch (e) { fail(e); }
  };

  return el('div', { class: 'page' },
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, j.job_no + ' · ' + j.status.replace('_', ' ')),
        el('h1', {}, j.title),
        el('p', {}, (j.company_name || j.customer_name) + ' · received ' + date(j.received_date) +
          (j.due_date ? ' · deliver by ' + date(j.due_date) : ''))),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn', onclick: () => jobForm(j, reload, ctx.can('costs.view')) }, 'Edit job'),
        ctx.can('suppliers.view')
          ? el('button', { class: 'btn', onclick: () => assignForm(id, reload) }, 'Give to supplier') : null,
        ctx.can('supplier_bills.entry') && (j.suppliers || []).some(a => !Number(a.is_billed))
          ? el('button', { class: 'btn', onclick: () => billFromJob(j, reload) }, 'Bill from supplier') : null,
        Number(j.is_invoiced)
          ? el('a', { class: 'btn', href: '#/invoices?customer=' + j.customer_id }, 'View invoice')
          : el('button', { class: 'btn primary', onclick: () => invoiceFromJob(j) }, 'Make invoice'))
    ),

    el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
      tile('Job value', money(j.est_total, j.currency)),
      ctx.can('costs.view')
        ? tile('My cost', money(j.est_cost, j.currency), 'from the lines below') : null,
      ctx.can('costs.view')
        ? tile('Margin', money(Number(j.est_total) - Number(j.est_cost), j.currency), null,
            Number(j.est_total) - Number(j.est_cost) >= 0 ? 'in' : 'out') : null),

    card('Work lines',
      table([
        { key: 'description', label: 'Line', render: r => el('div', {},
            el('strong', {}, r.description),
            r.service_name ? el('span', { class: 'sub' }, r.service_name) : null) },
        { key: 'qty', label: 'Qty', num: true, render: r => qty(r.qty) },
        { key: 'unit_price', label: 'Price', num: true, render: r => money(r.unit_price) },
        { key: 'line_total', label: 'Amount', num: true, render: r => money(r.line_total) },
        { key: 'status', label: 'Status', render: r => tag(r.status) },
        { key: 'act', label: '', render: r => r.status === 'completed'
            ? el('button', { class: 'btn sm', onclick: () => setItem(r.id, 'pending') }, 'Reopen')
            : el('button', { class: 'btn sm', onclick: () => setItem(r.id, 'completed') }, 'Mark done') }
      ], j.items, { empty: { title: 'No lines on this job', hint: 'Edit the job to add what needs doing.' } }),
      null, true),

    ctx.can('suppliers.view') ? card('Given to suppliers',
      table([
        { key: 'supplier_name', label: 'Supplier', render: r => el('div', {},
            el('strong', {}, r.supplier_name),
            el('span', { class: 'sub' }, r.work_detail || '—')) },
        { key: 'agreed_cost', label: 'Agreed', num: true, render: r => money(r.agreed_cost, r.currency) },
        { key: 'due_date', label: 'Due', render: r => date(r.due_date) },
        { key: 'status', label: 'Status', render: r => tag(r.status) },
        { key: 'act', label: '', render: r => r.status === 'completed'
            ? el('button', { class: 'btn sm', onclick: () => setAssign(r.id, 'in_progress') }, 'Reopen')
            : el('button', { class: 'btn sm', onclick: () => setAssign(r.id, 'completed') }, 'Mark done') }
      ], j.suppliers, { empty: { title: 'Nothing passed on', hint: 'Assign a line to a supplier if someone else does it.' } }),
      null, true) : null
  );
}

async function assignForm(jobId, onDone) {
  const supOpts = await supplierOptions();
  const fields = formFields([
    { name: 'supplier_id', label: 'Supplier', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...supOpts], span: 2 },
    { name: 'work_detail', label: 'What they will do', span: 2 },
    { name: 'agreed_cost', label: 'Agreed price', type: 'number' },
    { name: 'assigned_date', label: 'Given on', type: 'date', value: today() },
    { name: 'due_date', label: 'Wanted by', type: 'date' }
  ]);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      await api.post('/jobs/' + jobId + '/suppliers', readFields(fields));
      toast('Work assigned'); m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Assign work');

  const m = modal({ title: 'Give work to a supplier', body: fields,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save] });
}

function invoiceFromJob(j) {
  const fields = formFields([
    { name: 'invoice_date', label: 'Invoice date', type: 'date', value: today() },
    { name: 'due_date', label: 'Payment due', type: 'date', value: addDays(7) },
    { name: 'vat_percent', label: 'VAT %', type: 'number', value: 0 },
    { name: 'status', label: 'Status', type: 'select', options: [
        { value: 'sent', label: 'Sent to customer' }, { value: 'draft', label: 'Keep as draft' }] }
  ]);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      body.customer_id = j.customer_id;
      body.job_id = j.id;
      const res = await api.post('/invoices', body);
      toast('Invoice ' + res.data.invoice_no + ' created');
      m.close(); location.hash = '#/invoices/' + res.data.id;
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Create invoice');

  const m = modal({
    title: 'Invoice this job',
    body: el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'Every line not already invoiced will be copied across. You can change prices afterwards.'),
      fields),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}


/** Turn the work a supplier did on this job into a bill you owe them. */
function billFromJob(job, onDone) {
  const open = (job.suppliers || []).filter(a => !Number(a.is_billed));
  if (!open.length) {
    toast('Every assignment on this job is already billed', 'err');
    return;
  }

  const bySupplier = new Map();
  open.forEach(a => {
    if (!bySupplier.has(a.supplier_id)) bySupplier.set(a.supplier_id, []);
    bySupplier.get(a.supplier_id).push(a);
  });

  const supSel = el('select', {}, ...[...bySupplier.entries()].map(([id, rows]) =>
    el('option', { value: id }, rows[0].supplier_name + ' — ' + rows.length + ' task(s)')));

  const picks = el('div', { style: 'border:1px solid var(--rule);border-radius:6px;padding:10px' });
  const amount = el('input', { type: 'number', step: '0.01', class: 'mono' });

  const refresh = () => {
    const rows = bySupplier.get(Number(supSel.value)) || [];
    fill(picks, ...rows.map(a => el('label',
      { style: 'display:flex;gap:8px;align-items:center;padding:3px 0;font-size:13px' },
      el('input', { type: 'checkbox', value: a.id, checked: true, style: 'width:auto',
        onchange: sum }),
      (a.work_detail || 'Task #' + a.id) + ' — ' + money(a.agreed_cost, a.currency))));
    sum();
  };
  const sum = () => {
    const rows = bySupplier.get(Number(supSel.value)) || [];
    const chosen = [...picks.querySelectorAll('input:checked')].map(i => Number(i.value));
    amount.value = rows.filter(a => chosen.includes(Number(a.id)))
      .reduce((t, a) => t + Number(a.agreed_cost || 0), 0).toFixed(2);
  };
  supSel.addEventListener('change', refresh);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const rows = bySupplier.get(Number(supSel.value)) || [];
      const res = await api.post('/supplier-bills', {
        supplier_id: Number(supSel.value),
        job_id: job.id,
        amount: amount.value,
        currency: rows[0] ? rows[0].currency : job.currency,
        description: job.title,
        assign_ids: [...picks.querySelectorAll('input:checked')].map(i => Number(i.value))
      });
      toast('Bill ' + res.data.bill_no + ' added');
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Add bill');

  const m = modal({
    title: 'Bill from supplier',
    body: el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'Record what a supplier is charging you for their part of this job.'),
      el('label', { class: 'field' }, el('span', {}, 'Supplier'), supSel),
      el('label', { class: 'field' }, el('span', {}, 'Tasks covered by this bill'), picks),
      el('label', { class: 'field' }, el('span', {}, 'Bill amount'), amount)),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });

  refresh();
}

/* ============================================================= invoices */

export async function invoices(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let status = '', unpaid = ctx.query.get('unpaid') === '1', pageNo = 1;
  const customerFilter = ctx.query.get('customer') || '';

  // a bar that appears only once something is ticked
  const sendBar = el('div', { style: 'display:none' });

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/invoices', {
      status, unpaid: unpaid ? 1 : '', customer_id: customerFilter, page: pageNo, per_page: 25
    });

    const picker = rowPicker(res.data, () => {
      const n = picker.chosen().length;
      sendBar.style.display = n ? 'flex' : 'none';
      sendBar.dataset.count = String(n);
      fill(sendBar,
        el('span', {}, n + ' ticked'),
        el('button', { class: 'btn sm primary', onclick: () =>
          sendManyForm('invoice', picker.chosen(), load) }, 'Send them'),
        el('button', { class: 'btn sm', onclick: () => { picker.clear(); sendBar.style.display = 'none'; } },
          'Clear'));
    });

    fill(host, 
      res.by_currency && res.by_currency.length
        ? el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
            ...res.by_currency.map(m => tile('Invoiced (' + m.currency + ')', money(m.total),
              'paid ' + money(m.paid) + ' · due ' + money(m.due),
              Number(m.due) > 0 ? 'out' : 'in')))
        : null,
      card(null, table([
        ...(ctx.can('messages.send') ? [picker.column] : []),
        { key: 'invoice_no', label: 'Invoice', render: r => el('div', {},
            el('strong', { class: 'doc-no' }, r.invoice_no),
            r.subject ? el('span', { style: 'margin-left:8px' }, r.subject) : null,
            el('span', { class: 'sub' }, first(r.company_name, r.customer_name))) },
        { key: 'invoice_date', label: 'Date', render: r => date(r.invoice_date) },
        { key: 'due_date', label: 'Due', render: r => r.due_date
            ? el('div', {}, date(r.due_date),
                Number(r.days_overdue) > 0 && Number(r.due_amount) > 0
                  ? el('span', { class: 'sub', style: 'color:var(--out)' }, r.days_overdue + ' days over')
                  : null)
            : '—' },
        { key: 'total', label: 'Total', num: true, render: r => money(r.total, r.currency) },
        { key: 'paid_amount', label: 'Paid', num: true, render: r => money(r.paid_amount) },
        { key: 'due_amount', label: 'Due', num: true, render: r => Number(r.due_amount)
            ? el('strong', { style: 'color:var(--out)' }, money(r.due_amount)) : '—' },
        { key: 'status', label: 'Status', render: r => tag(r.status) }
      ], res.data, {
        onRowClick: r => ctx.go('#/invoices/' + r.id),
        empty: { title: 'No invoices yet', hint: 'Create one from a job, or start a blank invoice.' }
      }), null, true),
      pager(res.meta, p => { pageNo = p; load(); })
    );
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Billing'), el('h1', {}, 'Invoices')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn primary', onclick: () => invoiceForm(load, undefined, ctx.can('costs.view')) }, 'New invoice'))
    ),
    el('div', { class: 'filters', style: 'margin-bottom:14px' },
      el('select', { onchange: e => { status = e.target.value; pageNo = 1; load(); } },
        el('option', { value: '' }, 'All statuses'),
        ...['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled']
          .map(s => el('option', { value: s }, s[0].toUpperCase() + s.slice(1)))),
      el('label', { style: 'display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted)' },
        el('input', { type: 'checkbox', style: 'width:auto', checked: unpaid || undefined,
          onchange: e => { unpaid = e.target.checked; pageNo = 1; load(); } }), 'Unpaid only')),
    Object.assign(sendBar, {
      className: 'card',
      style: 'display:none;align-items:center;gap:12px;padding:12px 16px;margin-bottom:14px'
    }),
    host
  );

  load();
  return page;
}

async function invoiceForm(onDone, existing, showCosts = true) {
  const [custOpts, svc, cfg, rows] = await Promise.all([
    customerOptions(), serviceList(), settings(), customerRows()]);
  const base = cfg.base_currency || 'BDT';

  const fields = formFields([
    { name: 'customer_id', label: 'Customer', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...custOpts], span: 2,
      attrs: existing ? { disabled: true } : {} },
    { name: 'invoice_date', label: 'Invoice date', type: 'date', value: today() },
    { name: 'due_date', label: 'Payment due', type: 'date', value: addDays(7) },
    { name: 'vat_percent', label: 'VAT %', type: 'number', value: 0 },
    { name: 'discount_type', label: 'Discount', type: 'select', options: [
        { value: 'none', label: 'None' }, { value: 'flat', label: 'Fixed amount' },
        { value: 'percent', label: 'Percent' }] },
    { name: 'discount_value', label: 'Discount value', type: 'number', value: 0 },
    { name: 'subject', label: 'Subject', span: 2 },
    { name: 'status', label: 'Status', type: 'select', options: [
        { value: 'draft', label: 'Draft' }, { value: 'sent', label: 'Sent to customer' }] },
    { name: 'terms', label: 'Terms', type: 'textarea', span: 2 }
  ], existing || {});

  // the totals board isn't defined until further down, so lineEditor gets
  // a small forwarding shim here rather than the real function directly
  let onLinesChange = () => {};
  const lines = lineEditor(svc, () => base, existing ? (existing.items || []) : [],
    showCosts, base, !existing, () => onLinesChange());

  const jobTitle = el('input', { placeholder: 'Leave empty to name it after the invoice' });
  const jobDue = el('input', { type: 'date' });
  const workNote = existing ? null : el('div', { style: 'margin-top:14px' },
    el('p', { style: 'color:var(--muted);font-size:13px;margin:0 0 10px' },
      'Tick "To do" on the lines you still have to work on. They go to the work list; '
      + 'the rest are only billed. Billing and starting the work are separate choices.'),
    el('div', { class: 'grid c2' },
      el('label', { class: 'field' }, el('span', {}, 'Name the work'), jobTitle),
      el('label', { class: 'field' }, el('span', {}, 'Finish it by'), jobDue)));

  /**
   * How much of this are they paying right now: none, all of it, or a part.
   * A part payment writes a receipt for what they hand over and leaves the
   * rest on the invoice as due.
   */
  const payWhen = el('select', { name: 'pay_when' },
    el('option', { value: 'none' }, 'Nothing yet — leave it all due'),
    el('option', { value: 'full' }, 'Paying the whole amount now'),
    el('option', { value: 'part' }, 'Paying part of it now'));

  const payAmount = el('input', { name: 'pay_amount', type: 'number', step: '0.01', min: '0', disabled: true });
  const paidWhere = el('select', { name: 'pay_account', disabled: true });
  const paidHow = el('select', { name: 'pay_method', disabled: true });
  const payNote = el('p', { style: 'color:var(--faint);font-size:12.5px;margin:8px 0 0' }, '');

  // the live summary board - kept in exact lockstep with what
  // Finance::recalcInvoice() computes server-side, so this preview and the
  // invoice that actually gets created never disagree with each other
  const sumSubtotal = el('span', { class: 'mono' }, '0.00');
  const sumDiscount = el('span', { class: 'mono' }, '0.00');
  const sumTax = el('span', { class: 'mono' }, '0.00');
  const sumPaid = el('span', { class: 'mono' }, '0.00');
  const sumGrand = el('span', { class: 'mono' }, '0.00');

  const fmt = n => (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  /** Subtotal → discount → VAT → grand total, the same order and the same caps the server applies. */
  const computeTotals = () => {
    const subtotal = lines.readLines()
      .reduce((sum, l) => sum + (Number(l.qty) || 0) * (Number(l.unit_price) || 0), 0);
    const vatPercent = Number(fields.querySelector('[name=vat_percent]')?.value) || 0;
    const discountType = fields.querySelector('[name=discount_type]')?.value || 'none';
    const discountValue = Number(fields.querySelector('[name=discount_value]')?.value) || 0;

    let discount = 0;
    if (discountType === 'percent') {
      const pct = Math.min(Math.max(discountValue, 0), 100);
      discount = Math.min(subtotal * (pct / 100), subtotal);
    } else if (discountType === 'flat') {
      discount = Math.min(Math.max(discountValue, 0), subtotal);
    }
    // 'none' stays exactly 0 - matching Finance::discountAmount() on the
    // server, which never lets any type touch the subtotal except these two

    const afterDiscount = subtotal - discount;
    const vat = afterDiscount * (vatPercent / 100);
    const grandTotal = afterDiscount + vat;
    return { subtotal, discount, vat, grandTotal };
  };

  const invoiceTotal = () => computeTotals().grandTotal;

  const updateTotals = () => {
    const t = computeTotals();
    // payAmount is disabled for both "none" and "full" (it just shows the
    // auto-filled figure then) - only "none" actually means zero paid, so
    // the mode itself decides this, not the disabled flag alone
    const paid = payWhen.value === 'none' ? 0 : (Number(payAmount.value) || 0);
    sumSubtotal.textContent = fmt(t.subtotal);
    sumDiscount.textContent = fmt(t.discount);
    sumTax.textContent = fmt(t.vat);
    sumPaid.textContent = fmt(paid);
    // what they still owe once this payment is taken - not the invoice's
    // own stored Total (which invoiceTotal() below still returns
    // untouched, since "pay in full" needs the real figure to fill in,
    // not one already reduced by whatever is sitting in the amount box)
    sumGrand.textContent = fmt(Math.max(t.grandTotal - paid, 0));
  };

  const totalsBoard = el('div', { class: 'invoice-totals' },
    el('div', {}, el('span', {}, 'Subtotal:'), sumSubtotal),
    el('div', {}, el('span', {}, 'Tax:'), sumTax),
    el('div', {}, el('span', {}, 'Discount:'), sumDiscount),
    el('div', {}, el('span', {}, 'Paid Amount:'), sumPaid),
    el('div', { class: 'grand' }, el('span', {}, 'Grand Total:'), sumGrand));

  ['vat_percent', 'discount_type', 'discount_value'].forEach(name => {
    const input = fields.querySelector('[name=' + name + ']');
    if (input) input.addEventListener('input', () => { updateTotals(); syncPay(); });
  });

  const syncPay = () => {
    const mode = payWhen.value;
    const off = mode === 'none';
    paidWhere.disabled = off;
    paidHow.disabled = off;
    payAmount.disabled = mode !== 'part';

    const total = invoiceTotal();
    if (mode === 'full') {
      payAmount.value = total ? total.toFixed(2) : '';
      payNote.textContent = total
        ? 'A receipt for ' + money(total) + ' — nothing left owing.'
        : 'Add the lines first and the amount follows.';
    } else if (mode === 'part') {
      if (!payAmount.value) payAmount.value = '';
      const paid = Number(payAmount.value) || 0;
      payNote.textContent = total
        ? money(paid) + ' now, ' + money(Math.max(total - paid, 0)) + ' left owing.'
        : 'Add the lines first.';
    } else {
      payAmount.value = '';
      payNote.textContent = 'The whole amount stays on their account as due.';
    }
  };

  payWhen.addEventListener('change', () => { syncPay(); updateTotals(); });
  payAmount.addEventListener('input', () => { syncPay(); updateTotals(); });
  onLinesChange = updateTotals;
  updateTotals();

  if (!existing) {
    Promise.all([accountChoices(), methodChoices()]).then(([accs, methods]) => {
      accs.forEach(a => paidWhere.append(el('option', { value: a.value }, a.label)));
      methods.forEach(m2 => paidHow.append(el('option', { value: m2.value }, m2.label)));
    });
  }

  const paidBlock = existing ? null : el('div', {
    style: 'margin-top:14px;border-top:1px solid var(--rule-soft);padding-top:14px'
  },
    el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;'
                    + 'color:var(--muted);margin:0 0 10px' }, 'Payment'),
    el('div', { class: 'grid c2' },
      el('label', { class: 'field' }, el('span', {}, 'Are they paying now'), payWhen),
      el('label', { class: 'field' }, el('span', {}, 'How much now'), payAmount)),
    payNote,
    totalsBoard,
    el('div', { class: 'grid c2', style: 'margin-top:14px' },
      el('label', { class: 'field' }, el('span', {}, 'Into which account'), paidWhere),
      el('label', { class: 'field' }, el('span', {}, 'How'), paidHow)));

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      body.items = lines.readLines();
      // the payment boxes live outside `fields`, so nothing extra rides along
      if (jobTitle.value.trim()) body.job_title = jobTitle.value.trim();
      if (jobDue.value) body.job_due_date = jobDue.value;
      if (!body.items.length) { toast('Add at least one line', 'err'); save.disabled = false; return; }

      if (existing) {
        await api.patch('/invoices/' + existing.id, body);
        toast('Invoice updated');
        m.close(); onDone && onDone();
      } else {
        const mode = payWhen.value;
        if (mode !== 'none') body.status = 'sent';   // money against a draft makes no sense

        const res = await api.post('/invoices', body);
        const inv = res.data;

        if (mode !== 'none') {
          const total = Number(inv.total);
          let amount = mode === 'full' ? total : Number(payAmount.value) || 0;
          if (amount > total) amount = total;      // never take more than the invoice

          if (amount <= 0) {
            toast('Invoice ' + inv.invoice_no + ' created — no payment was recorded', 'err');
          } else {
            try {
              await api.post('/payments', {
                customer_id: body.customer_id,
                amount,
                account_id: paidWhere.value || undefined,
                method: paidHow.value || undefined,
                allocations: [{ invoice_id: inv.id, amount }]
              });
              const left = total - amount;
              toast(left > 0
                ? 'Invoice ' + inv.invoice_no + ' created — ' + money(amount) + ' taken, '
                  + money(left) + ' still due'
                : 'Invoice ' + inv.invoice_no + ' created and paid in full');
            } catch (e) {
              toast('Invoice made, but the payment did not save: ' + (e.message || ''), 'err');
            }
          }
        } else {
          toast('Invoice ' + inv.invoice_no + ' created');
        }

        m.close(); location.hash = '#/invoices/' + inv.id;
        onDone && onDone();
      }
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Create invoice');

  const m = modal({
    title: existing ? 'Edit ' + existing.invoice_no : 'New invoice', wide: true,
    body: el('div', {}, fields,
      el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px' },
        'Lines'),
      lines, workNote, paidBlock),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

export async function invoiceDetail(ctx) {
  const id = ctx.params[0];
  const res = await api.get('/invoices/' + id);
  const inv = res.data;
  const reload = () => ctx.go('#/invoices/' + id, true);

  const openPrint = async () => {
    try {
      const r = await api.get('/invoices/' + id + '/print-link');
      window.open(r.data.url, '_blank', 'noopener');
    } catch (e) { fail(e); }
  };

  const remind = async () => {
    try {
      const r = await api.post('/messages/due-reminder', { invoice_id: Number(id), channel: 'whatsapp' });
      toast(r.data.result.success ? 'Reminder sent on WhatsApp' : r.data.result.error, r.data.result.success ? 'ok' : 'err');
    } catch (e) { fail(e); }
  };

  return el('div', { class: 'page' },
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Invoice'),
        el('h1', { class: 'doc-no', style: 'display:flex;align-items:center;gap:10px' },
          inv.invoice_no, tag(inv.status)),
        el('p', {}, (inv.company_name || inv.customer_name) + ' · ' + date(inv.invoice_date) +
          (inv.due_date ? ' · due ' + date(inv.due_date) : ''))),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn', onclick: openPrint }, 'Print / PDF'),
        ctx.can('messages.send')
          ? el('button', { class: 'btn primary', onclick: () => sendDocumentForm('invoice', inv, reload) }, 'Send')
          : null,
        inv.status !== 'cancelled'
          ? el('button', { class: 'btn', onclick: () => invoiceForm(reload, inv, ctx.can('costs.view')) }, 'Edit') : null,
        Number(inv.paid_amount) === 0 && inv.status !== 'cancelled'
          ? el('button', { class: 'btn danger', onclick: async () => {
              if (!await confirmAction('Cancel invoice',
                inv.invoice_no + ' will be marked cancelled. It stays in the records.', 'Cancel invoice')) return;
              try { await api.post('/invoices/' + id + '/cancel'); toast('Invoice cancelled'); reload(); }
              catch (e) { fail(e); } } }, 'Cancel') : null,
        Number(inv.due_amount) > 0
          ? el('button', { class: 'btn', onclick: remind }, 'Send reminder') : null,
        inv.status === 'draft'
          ? el('button', { class: 'btn', onclick: async () => {
              try { await api.post('/invoices/' + id + '/mark-sent'); toast('Marked as sent'); reload(); }
              catch (e) { fail(e); } } }, 'Mark as sent') : null,
        Number(inv.due_amount) > 0
          ? el('button', { class: 'btn primary', onclick: () => paymentForm({ invoice: inv }, reload) }, 'Record payment')
          : null)
    ),

    el('div', { class: 'grid c4', style: 'margin-bottom:14px' },
      tile('Total', money(inv.total, inv.currency)),
      tile('Paid', money(inv.paid_amount), null, 'in'),
      tile('Due', money(inv.due_amount), null, Number(inv.due_amount) > 0 ? 'out' : 'in'),
      ctx.can('costs.view') ? tile('Profit', money(inv.profit), 'after my cost') : null),

    card('Lines',
      table([
        { key: 'description', label: 'Description' },
        { key: 'qty', label: 'Qty', num: true, render: r => qty(r.qty) + (r.unit ? ' ' + r.unit : '') },
        { key: 'unit_price', label: 'Price', num: true, render: r => money(r.unit_price) },
        ...(ctx.can('costs.view') ? [
          { key: 'cost_price', label: 'My cost', num: true, render: r => money(r.cost_price) }
        ] : []),
        { key: 'line_total', label: 'Amount', num: true, render: r => money(r.line_total) }
      ], inv.items), null, true),

    inv.job_id
      ? card('Work started from this invoice',
          el('p', { style: 'margin:0' },
            'These lines are on the work list. ',
            el('a', { href: '#/jobs/' + inv.job_id }, 'Open the job')))
      : null,

    card('Proof and papers', fileManager(api, 'invoice', Number(id))),

    card('Payments received',
      table([
        { key: 'payment_date', label: 'Date', render: r => date(r.payment_date) },
        { key: 'receipt_no', label: 'Receipt', render: r => el('span', { class: 'doc-no' }, r.receipt_no) },
        { key: 'method', label: 'Method', render: r => tag(r.method) },
        { key: 'reference', label: 'Reference', render: r => r.reference || '—' },
        { key: 'amount', label: 'Amount', num: true, render: r => money(r.amount, r.currency) }
      ], inv.payments, { empty: { title: 'Nothing received yet', hint: 'Record a payment when the money arrives.' } }),
      null, true)
  );
}

/* =========================================================== quotations */

export async function quotations(ctx) {
  const qBar = el('div', {
    class: 'card',
    style: 'display:none;align-items:center;gap:12px;padding:12px 16px;margin-bottom:14px'
  });
  const page = el('div', { class: 'page' });
  const host = el('div', {});

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/quotations', { per_page: 100 });

    const picker = rowPicker(res.data, () => {
      const n = picker.chosen().length;
      qBar.style.display = n ? 'flex' : 'none';
      fill(qBar,
        el('span', {}, n + ' ticked'),
        el('button', { class: 'btn sm primary', onclick: () =>
          sendManyForm('quotation', picker.chosen(), load) }, 'Send them'),
        el('button', { class: 'btn sm', onclick: () => { picker.clear(); qBar.style.display = 'none'; } },
          'Clear'));
    });

    fill(host, qBar, card(null, table([
      ...(ctx.can('messages.send') ? [picker.column] : []),
      { key: 'quote_no', label: 'Quotation', render: r => el('div', {},
          el('strong', { class: 'doc-no' }, r.quote_no),
          el('span', { class: 'sub' }, first(r.company_name, r.customer_name))) },
      { key: 'quote_date', label: 'Date', render: r => date(r.quote_date) },
      { key: 'valid_until', label: 'Valid until', render: r => date(r.valid_until) },
      { key: 'total', label: 'Total', num: true, render: r => money(r.total, r.currency) },
      { key: 'status', label: 'Status', render: r => tag(r.status) },
      { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
          el('button', { class: 'btn sm', onclick: async () => {
            try {
              const link = await api.get('/quotations/' + r.id + '/print-link');
              window.open(link.data.url, '_blank', 'noopener');
            } catch (e) { fail(e); }
          } }, 'PDF'),
          r.status !== 'converted' ? el('button', { class: 'btn sm', onclick: async () => {
            if (!await confirmAction('Convert to invoice',
              'This creates a draft invoice with the same lines.', 'Convert')) return;
            try { const inv = await api.post('/quotations/' + r.id + '/convert');
              toast('Invoice ' + inv.data.invoice_no + ' created');
              location.hash = '#/invoices/' + inv.data.id;
            } catch (e) { fail(e); }
          } }, 'To invoice') : null) }
    ], res.data, {
      onRowClick: r => ctx.go('#/quotations/' + r.id),
      empty: { title: 'No quotations yet', hint: 'Send a price before the work starts.' }
    }), null, true));
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Billing'), el('h1', {}, 'Quotations')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn primary', onclick: () => quotationForm(load, undefined, ctx.can('costs.view')) }, 'New quotation'))),
    host
  );

  load();
  return page;
}

async function quotationForm(onDone, existing, showCosts = true) {
  const [custOpts, svc, cfg, rows] = await Promise.all([
    customerOptions(), serviceList(), settings(), customerRows()]);
  const base = cfg.base_currency || 'BDT';
  const fields = formFields([
    { name: 'customer_id', label: 'Customer', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...custOpts], span: 2 },
    { name: 'quote_date', label: 'Date', type: 'date', value: today() },
    { name: 'valid_until', label: 'Valid until', type: 'date', value: addDays(15) },
    { name: 'vat_percent', label: 'VAT %', type: 'number', value: 0 },
    { name: 'subject', label: 'Subject', span: 2 },
    { name: 'status', label: 'Status', type: 'select', options: [
        { value: 'draft', label: 'Draft' }, { value: 'sent', label: 'Sent' },
        { value: 'accepted', label: 'Accepted' }, { value: 'rejected', label: 'Rejected' }] },
    { name: 'terms', label: 'Terms', type: 'textarea', span: 2 }
  ], existing || {});
  const lines = lineEditor(svc, () => base, existing ? (existing.items || []) : [], showCosts, base);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      body.items = lines.readLines();
      if (existing) {
        await api.patch('/quotations/' + existing.id, body);
        toast('Quotation updated');
      } else {
        const res = await api.post('/quotations', body);
        toast('Quotation ' + res.data.quote_no + ' created');
      }
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Create quotation');

  const m = modal({
    title: existing ? 'Edit ' + existing.quote_no : 'New quotation', wide: true,
    body: el('div', {}, fields, el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px' }, 'Lines'), lines),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}


export async function quotationDetail(ctx) {
  const id = ctx.params[0];
  const res = await api.get('/quotations/' + id);
  const q = res.data;
  const reload = () => ctx.go('#/quotations/' + id, true);

  const openPrint = async () => {
    try {
      const r = await api.get('/quotations/' + id + '/print-link');
      window.open(r.data.url, '_blank', 'noopener');
    } catch (e) { fail(e); }
  };

  return el('div', { class: 'page' },
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Quotation'),
        el('h1', { class: 'doc-no', style: 'display:flex;align-items:center;gap:10px' },
          q.quote_no, tag(q.status)),
        el('p', {}, (q.company_name || q.customer_name) + ' · ' + date(q.quote_date) +
          (q.valid_until ? ' · valid until ' + date(q.valid_until) : ''))),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn', onclick: openPrint }, 'Print / PDF'),
        ctx.can('messages.send')
          ? el('button', { class: 'btn primary', onclick: () => sendDocumentForm('quotation', q, reload) }, 'Send')
          : null,
        q.status !== 'converted'
          ? el('button', { class: 'btn', onclick: () => quotationForm(reload, q, ctx.can('costs.view')) }, 'Edit') : null,
        q.status !== 'converted'
          ? el('button', { class: 'btn primary', onclick: async () => {
              if (!await confirmAction('Turn this into an invoice',
                'A draft invoice will be created with the same lines.', 'Create invoice')) return;
              try {
                const inv = await api.post('/quotations/' + id + '/convert');
                toast('Invoice ' + inv.data.invoice_no + ' created');
                location.hash = '#/invoices/' + inv.data.id;
              } catch (e) { fail(e); }
            } }, 'Convert to invoice')
          : el('a', { class: 'btn', href: '#/invoices/' + q.converted_invoice_id }, 'View invoice'))
    ),

    el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
      tile('Subtotal', money(q.subtotal, q.currency)),
      tile('Discount + VAT', money(Number(q.vat_amount) - Number(q.discount_amount))),
      tile('Total', money(q.total, q.currency))),

    card('Lines',
      table([
        { key: 'description', label: 'Description' },
        { key: 'qty', label: 'Qty', num: true, render: r => qty(r.qty) + (r.unit ? ' ' + r.unit : '') },
        { key: 'unit_price', label: 'Price', num: true, render: r => money(r.unit_price) },
        { key: 'line_total', label: 'Amount', num: true, render: r => money(r.line_total) }
      ], q.items, { empty: { title: 'No lines yet', hint: 'Edit the quotation to add lines.' } }), null, true),

    q.terms ? card('Terms', el('p', { style: 'margin:0;white-space:pre-wrap' }, q.terms)) : null
  );
}

/* ============================================================= payments */

export async function payments(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let view = 'due';

  const load = async () => {
    fill(host, loading());
    if (view === 'advance') {
      const res = await api.get('/payments', { advance_only: 1, per_page: 100 });
      fill(host, 
        el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
          ...(res.by_currency || []).map(t => tile('Advance held (' + t.currency + ')',
            money(t.advance_held), 'not applied to any invoice', 'in'))),
        card(null, table([
          { key: 'payment_date', label: 'Received', render: r => date(r.payment_date) },
          { key: 'receipt_no', label: 'Receipt', render: r => el('span', { class: 'doc-no' }, r.receipt_no) },
          { key: 'customer_name', label: 'Customer', render: r => first(r.company_name, r.customer_name) },
          { key: 'amount', label: 'Paid', num: true, render: r => money(r.amount, r.currency) },
          { key: 'unallocated_amount', label: 'Still unapplied', num: true, render: r =>
              el('strong', { style: 'color:var(--in)' }, money(r.unallocated_amount)) },
          { key: 'act', label: '', render: r => el('button', { class: 'btn sm', onclick: async () => {
              if (!await confirmAction('Apply advance',
                money(r.unallocated_amount, r.currency) + ' will be put against this customer\'s oldest unpaid invoices.',
                'Apply')) return;
              try {
                const rr = await api.post('/payments/' + r.id + '/apply-advance', {});
                toast('Applied — ' + money(rr.data.unallocated_amount, r.currency) + ' still unapplied');
                load();
              } catch (e) { fail(e); }
            } }, 'Apply') }
        ], res.data, {
          empty: { title: 'No advance held', hint: 'Money received without an invoice shows up here.' }
        }), null, true));
    } else if (view === 'due') {
      const res = await api.get('/payments/due-list');
      fill(host, 
        el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
          ...Object.entries(res.data.totals_by_currency || {}).map(([cur, amt]) =>
            tile('Outstanding (' + cur + ')', money(amt), 'across all customers', 'out'))),
        card(null, table([
          { key: 'name', label: 'Customer', render: r => el('div', {},
              el('strong', {}, first(r.company_name, r.name)),
              el('span', { class: 'sub' }, r.phone || '—')) },
          { key: 'invoice_count', label: 'Invoices', num: true },
          { key: 'invoiced', label: 'Invoiced', num: true, render: r => money(r.invoiced, r.currency) },
          { key: 'paid', label: 'Paid', num: true, render: r => money(r.paid) },
          { key: 'due', label: 'Still due', num: true, render: r =>
              el('strong', { style: 'color:var(--out)' }, money(r.due)) },
          { key: 'days_overdue', label: 'Oldest', render: r => Number(r.days_overdue) > 0
              ? el('span', { class: 'tag out' }, r.days_overdue + 'd over') : tag('sent') },
          { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
              el('button', { class: 'btn sm', onclick: async () => {
                try {
                  const rr = await api.post('/messages/due-reminder',
                    { customer_id: r.customer_id, channel: 'whatsapp' });
                  toast(rr.data.result.success ? 'Reminder sent' : rr.data.result.error,
                    rr.data.result.success ? 'ok' : 'err');
                } catch (e) { fail(e); }
              } }, 'Remind'),
              el('button', { class: 'btn sm', onclick: () =>
                paymentForm({ customerId: r.customer_id, currency: r.currency }, load) }, 'Take payment')) }
        ], res.data.rows, {
          empty: { title: 'Everyone has paid', hint: 'Every invoice has been settled.' }
        }), null, true));
    } else {
      const res = await api.get('/payments/received');
      fill(host, 
        el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
          ...(res.data.totals_by_currency || []).map(t =>
            tile('Received (' + t.currency + ')', money(t.total),
              date(res.data.from) + ' – ' + date(res.data.to), 'in'))),
        card(null, table([
          { key: 'payment_date', label: 'Date', render: r => date(r.payment_date) },
          { key: 'receipt_no', label: 'Receipt', render: r => el('span', { class: 'doc-no' }, r.receipt_no) },
          { key: 'customer_name', label: 'Customer', render: r => first(r.company_name, r.customer_name) },
          { key: 'method', label: 'Method', render: r => el('div', {},
              tag(r.method),
              el('span', { class: 'sub' }, r.account_label || '')) },
          { key: 'reference', label: 'Reference', render: r => r.reference || '—' },
          { key: 'amount', label: 'Amount', num: true, render: r =>
              el('strong', { style: 'color:var(--in)' }, money(r.amount, r.currency)) },
          { key: 'act', label: '', render: r => el('button', { class: 'btn sm',
              onclick: () => attachProof('payment', r.id, r.receipt_no) }, 'Proof') }
        ], res.data.rows, { empty: { title: 'No payments this month', hint: 'Recorded payments appear here.' } }),
        null, true));
    }
  };

  const switchView = v => {
    view = v;
    page.querySelectorAll('[data-view]').forEach(b =>
      b.setAttribute('aria-pressed', String(b.dataset.view === v)));
    load();
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Money'), el('h1', {}, 'Payments')),
      el('div', { class: 'page-actions' },
        el('div', { class: 'tab-strip', style: 'padding:0' },
          el('button', { dataset: { view: 'due' }, 'aria-pressed': 'true',
            onclick: () => switchView('due') }, 'Outstanding'),
          el('button', { dataset: { view: 'received' }, 'aria-pressed': 'false',
            onclick: () => switchView('received') }, 'Received'),
          el('button', { dataset: { view: 'advance' }, 'aria-pressed': 'false',
            onclick: () => switchView('advance') }, 'Advance held')),
        el('button', { class: 'btn primary', onclick: () => paymentForm({}, load) }, 'Record payment'))),
    host
  );

  load();
  return page;
}

/**
 * opts: { invoice } | { customerId, currency } | {}
 */
async function paymentForm(opts, onDone) {
  const [custOpts, accountOptions, methodOptions] = await Promise.all([
    customerOptions(), accountChoices(), methodChoices()]);
  const inv = opts.invoice;

  const fields = formFields([
    { name: 'customer_id', label: 'Customer', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...custOpts], span: 2,
      value: inv ? inv.customer_id : (opts.customerId || '') },
    { name: 'amount', label: 'Amount received', type: 'number', required: true,
      value: inv ? inv.due_amount : '' },
    { name: 'payment_date', label: 'Received on', type: 'date', value: today() },
    { name: 'method', label: 'How they paid', type: 'select', options: methodOptions,
      hint: 'Shown on their statement, so they can recognise the payment' },
    { name: 'account_id', label: 'Into which account', type: 'select', options: accountOptions },
    { name: 'reference', label: 'Reference', hint: 'bKash TrxID, cheque no.', span: 2 },
    { name: 'note', label: 'Note', span: 2 }
  ]);

  /**
   * What the money is for. Left to itself the system clears the oldest
   * invoices, which is usually right — but when a customer pays for one
   * particular job you want to say so, and sometimes money arrives before
   * any invoice exists at all.
   */
  const against = el('select', { name: 'against' },
    el('option', { value: 'auto' }, 'Clear the oldest invoices first'),
    el('option', { value: 'pick' }, 'Against invoices I choose'),
    el('option', { value: 'advance' }, 'Advance — no invoice yet'));

  const invoiceBox = el('div', { style: 'display:none' });
  const custSel = fields.querySelector('[name=customer_id]');
  const amountInput = fields.querySelector('[name=amount]');
  let openInvoices = [];
  const picked = new Map();     // invoice id -> the amount box

  const summary = el('p', { style: 'color:var(--faint);font-size:12.5px;margin:8px 0 0' }, '');

  const retotal = () => {
    const chosen = [...picked.entries()]
      .filter(([, row]) => row.box.checked)
      .map(([, row]) => Number(row.amount.value) || 0);
    const sum = chosen.reduce((a, b) => a + b, 0);
    if (sum > 0) amountInput.value = sum.toFixed(2);
    summary.textContent = chosen.length
      ? chosen.length + ' invoice(s), ' + money(sum) + ' in total'
      : 'Tick the invoices this payment is for.';
  };

  const drawInvoices = () => {
    if (!openInvoices.length) {
      fill(invoiceBox, el('p', { style: 'color:var(--muted);font-size:13px;margin:0' },
        'Nothing outstanding for them — this will be held as advance.'));
      return;
    }

    fill(invoiceBox,
      el('p', { style: 'color:var(--muted);font-size:12.5px;margin:0 0 8px' },
        'Tick what this payment covers. The amount follows what you tick.'),
      ...openInvoices.map(i => {
        const box = el('input', {
          type: 'checkbox', style: 'width:auto',
          onchange: () => { amount.disabled = !box.checked; retotal(); }
        });
        const amount = el('input', {
          type: 'number', step: '0.01', value: Number(i.due_amount).toFixed(2), disabled: true,
          style: 'width:110px', oninput: retotal
        });
        picked.set(i.id, { box, amount });

        return el('label', {
          style: 'display:flex;align-items:center;gap:10px;padding:8px 0;'
               + 'border-bottom:1px solid var(--rule-soft);font-weight:400'
        },
          box,
          el('span', { style: 'flex:1;min-width:0' },
            el('strong', { class: 'doc-no' }, i.invoice_no),
            el('span', { class: 'sub' },
              join(i.subject || i.job_title, 'due ' + money(i.due_amount),
                Number(i.days_overdue) > 0 ? i.days_overdue + ' days over' : null))),
          amount);
      }),
      summary);
    retotal();
  };

  const loadOpen = async () => {
    picked.clear();
    const id = custSel.value;
    if (!id) { openInvoices = []; drawInvoices(); return; }
    fill(invoiceBox, loading('…'));
    try {
      const res = await api.get('/customers/' + id + '/open-invoices');
      openInvoices = res.data.invoices;
      drawInvoices();
    } catch {
      openInvoices = [];
      fill(invoiceBox, el('p', { style: 'color:var(--out)' }, 'Could not load their invoices.'));
    }
  };

  const syncAgainst = () => {
    const mode = against.value;
    invoiceBox.style.display = mode === 'pick' ? '' : 'none';
    if (mode === 'pick') loadOpen();
  };

  against.addEventListener('change', syncAgainst);
  custSel.addEventListener('change', () => { if (against.value === 'pick') loadOpen(); });

  // arriving from an invoice means the answer is already known
  if (inv) {
    against.value = 'pick';
    against.disabled = true;
  }

  const notifyBox = el('label', { style: 'display:flex;align-items:center;gap:8px;font-size:13px' },
    el('input', { type: 'checkbox', style: 'width:auto' }),
    'Send a WhatsApp confirmation to the customer');

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      const mode = against.value;

      if (inv) {
        body.allocations = [{ invoice_id: inv.id, amount: body.amount }];
      } else if (mode === 'pick') {
        const allocations = [...picked.entries()]
          .filter(([, row]) => row.box.checked)
          .map(([id, row]) => ({ invoice_id: id, amount: Number(row.amount.value) || 0 }))
          .filter(a => a.amount > 0);
        if (!allocations.length) {
          toast('Tick at least one invoice, or choose another option', 'err');
          save.disabled = false;
          return;
        }
        body.allocations = allocations;
      } else if (mode === 'auto') {
        body.auto_allocate = true;
      }
      // 'advance' sends neither, so the money sits on their account

      if (notifyBox.querySelector('input').checked) body.notify = 'whatsapp';

      const res = await api.post('/payments', body);
      const left = Number(res.data.unallocated_amount) || 0;
      toast(left > 0
        ? 'Payment ' + res.data.receipt_no + ' recorded — ' + money(left) + ' held as advance'
        : 'Payment ' + res.data.receipt_no + ' recorded');

      if (res.data.notification && !res.data.notification.success) {
        toast('Saved, but the message did not send: ' + res.data.notification.error, 'err');
      }
      m.close(); onDone && onDone();
      attachProof('payment', res.data.id, res.data.receipt_no);
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Record payment');

  const m = modal({
    title: inv ? 'Payment for ' + inv.invoice_no : 'Record a payment',
    wide: true,
    body: el('div', {},
      fields,
      el('label', { class: 'field', style: 'grid-column:1/-1' },
        el('span', {}, 'What is it for'), against),
      invoiceBox,
      el('div', { style: 'margin-top:12px' }, notifyBox)),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });

  syncAgainst();
}


/** Ask for the receipt or slip right after the money is recorded. */
function attachProof(entityType, entityId, title) {
  const m = modal({
    title: 'Proof for ' + title,
    body: el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'Attach the deposit slip, bKash screenshot or signed receipt. '
        + 'You can always add it later from the same record.'),
      fileManager(api, entityType, entityId)),
    footer: [el('button', { class: 'btn primary', onclick: () => m.close() }, 'Done')]
  });
}


/** Send this invoice or quotation to the customer, on whichever channel. */
function sendDocumentForm(kind, row, onDone) {
  const fields = formFields([
    { name: 'channel', label: 'Send on', type: 'select', span: 2, options: [
        { value: 'whatsapp', label: 'WhatsApp' },
        { value: 'telegram', label: 'Telegram' },
        { value: 'email', label: 'Email' }] },
    { name: 'note', label: 'Add a line of your own', type: 'textarea', span: 2 }
  ]);

  const preview = el('pre', {
    style: 'background:var(--rule-soft);padding:12px;border-radius:6px;font-size:12.5px;'
         + 'white-space:pre-wrap;margin:14px 0 0;max-height:180px;overflow:auto'
  }, 'They will get a short message with a link that opens the '
     + kind + '. Nothing is sent until you press Send.');

  const send = el('button', { class: 'btn primary', onclick: async () => {
    send.disabled = true;
    try {
      const res = await api.post('/messages/send-document',
        { type: kind, id: row.id, ...readFields(fields) });
      preview.textContent = res.data.body;
      toast(res.message, res.data.result.success ? 'ok' : 'err');
      if (res.data.result.success) { m.close(); onDone && onDone(); }
    } catch (e) { fail(e); }
    send.disabled = false;
  } }, 'Send');

  const m = modal({
    title: 'Send ' + (kind === 'invoice' ? row.invoice_no : row.quote_no),
    body: el('div', {}, fields, preview),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), send]
  });
}

/* ====================================================== supplier ledger */

export async function supplierBills(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  const supplierFilter = ctx.query.get('supplier') || '';

  const load = async () => {
    fill(host, loading());
    const billBar = el('div', {
      class: 'card',
      style: 'display:none;align-items:center;gap:12px;padding:12px 16px;margin-bottom:14px'
    });

    const [payable, bills] = await Promise.all([
      api.get('/supplier-bills/payable'),
      api.get('/supplier-bills', { supplier_id: supplierFilter, per_page: 100 })
    ]);

    const billPicker = rowPicker(bills.data, () => {
      const n = billPicker.chosen().length;
      billBar.style.display = n ? 'flex' : 'none';
      fill(billBar,
        el('span', {}, n + ' ticked'),
        el('button', { class: 'btn sm primary', onclick: () =>
          sendManyForm('supplier_bill', billPicker.chosen(), load) }, 'Send them'),
        el('button', { class: 'btn sm',
          onclick: () => { billPicker.clear(); billBar.style.display = 'none'; } }, 'Clear'));
    });

    fill(host, 
      el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
        ...Object.entries(payable.data.totals_by_currency || {}).map(([cur, amt]) =>
          tile('I owe (' + cur + ')', money(amt), 'unpaid supplier bills', 'out'))),

      card('Who I owe',
        table([
          { key: 'name', label: 'Supplier', render: r => el('div', {},
              el('strong', {}, r.name), el('span', { class: 'sub' }, r.phone || '—')) },
          { key: 'bill_count', label: 'Bills', num: true },
          { key: 'payable', label: 'Payable', num: true, render: r =>
              el('strong', { style: 'color:var(--out)' }, money(r.payable, r.currency)) },
          { key: 'act', label: '', render: r => el('button', { class: 'btn sm',
              onclick: () => supplierPayForm(r, load) }, 'Pay') }
        ], payable.data.rows, { empty: { title: 'Nothing owed', hint: 'All supplier bills are settled.' } }),
        null, true),

      billBar,

      card('All bills',
        table([
          ...(ctx.can('messages.send') ? [billPicker.column] : []),
          { key: 'bill_no', label: 'Bill', render: r => el('div', {},
              el('strong', { class: 'doc-no' }, r.bill_no),
              el('span', { class: 'sub' }, join(r.supplier_name, r.job_title))) },
          { key: 'bill_date', label: 'Date', render: r => date(r.bill_date) },
          { key: 'amount', label: 'Amount', num: true, render: r => money(r.amount, r.currency) },
          { key: 'paid_amount', label: 'Paid', num: true, render: r => money(r.paid_amount) },
          { key: 'due_amount', label: 'Due', num: true, render: r => Number(r.due_amount)
              ? el('strong', { style: 'color:var(--out)' }, money(r.due_amount)) : '—' },
          { key: 'status', label: 'Status', render: r => tag(r.status) }
        ], bills.data, { empty: { title: 'No supplier bills', hint: 'Add a bill when a supplier charges you.' } }),
        null, true)
    );
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Money'), el('h1', {}, 'Supplier bills')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn', onclick: () => supplierPayForm(null, load) }, 'Record payment'),
        el('button', { class: 'btn primary', onclick: () => supplierBillForm(load) }, 'Add bill'))),
    host
  );

  load();
  return page;
}

async function supplierBillForm(onDone) {
  const supOpts = await supplierOptions();
  const fields = formFields([
    { name: 'supplier_id', label: 'Supplier', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...supOpts], span: 2 },
    { name: 'amount', label: 'Amount', type: 'number', required: true },
    { name: 'bill_date', label: 'Bill date', type: 'date', value: today() },
    { name: 'due_date', label: 'Pay by', type: 'date' },
    { name: 'description', label: 'What for', span: 2 }
  ]);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const r = await api.post('/supplier-bills', readFields(fields));
      toast('Bill ' + r.data.bill_no + ' added');
      m.close(); onDone();
      attachProof('supplier_bill', r.data.id, r.data.bill_no);
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Add bill');

  const m = modal({ title: 'Add supplier bill', body: fields,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save] });
}

async function supplierPayForm(row, onDone) {
  const [supOpts, accountOptions, methodOptions] = await Promise.all([
    supplierOptions(), accountChoices(), methodChoices()]);
  const fields = formFields([
    { name: 'supplier_id', label: 'Supplier', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...supOpts],
      value: row ? row.supplier_id : '', span: 2 },
    { name: 'amount', label: 'Amount paid', type: 'number', required: true, value: row ? row.payable : '' },
    { name: 'payment_date', label: 'Paid on', type: 'date', value: today() },
    { name: 'method', label: 'How', type: 'select', options: methodOptions },
    { name: 'account_id', label: 'Out of which account', type: 'select', options: accountOptions },
    { name: 'reference', label: 'Reference', span: 2 }
  ]);

  const notify = el('label', { style: 'display:flex;align-items:center;gap:8px;font-size:13px' },
    el('input', { type: 'checkbox', style: 'width:auto' }),
    'Tell the supplier on WhatsApp');

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      if (notify.querySelector('input').checked) body.notify = 'whatsapp';
      const r = await api.post('/supplier-payments', body);
      toast('Payment ' + r.data.voucher_no + ' recorded');
      if (r.data.notification && !r.data.notification.success) {
        toast('Saved, but the message did not send: ' + r.data.notification.error, 'err');
      }
      m.close(); onDone();
      attachProof('supplier_payment', r.data.id, r.data.voucher_no);
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Record payment');

  const m = modal({
    title: 'Pay a supplier',
    body: el('div', {}, fields, notify,
      el('p', { style: 'color:var(--faint);font-size:12px;margin:12px 0 0' },
        'The payment settles their oldest unpaid bills first.')),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

/** Accounts as picker options, with balances so you can see where money sits. */
async function accountChoices() {
  try {
    const res = await api.get('/accounts');
    return res.data.accounts.map(a => ({
      value: a.id,
      label: a.name + '  (' + money(a.balance) + ')'
    }));
  } catch {
    return [];
  }
}

/** The ways money changes hands, as you have listed them in Settings. */
async function methodChoices() {
  try {
    const res = await api.get('/payment-methods');
    return res.data.map(m => ({ value: m.name, label: m.name }));
  } catch {
    return [{ value: 'Cash', label: 'Cash' }];
  }
}

/**
 * Send whatever is ticked. One channel for the run; each item is tried on
 * its own so a missing number does not stop the rest, and the result says
 * plainly who it reached.
 */
export function sendManyForm(kind, ids, onDone) {
  const what = {
    invoice: 'invoice', quotation: 'quotation',
    supplier_bill: 'bill', statement: 'statement', document: 'reminder'
  }[kind] || 'item';

  const fields = formFields([
    { name: 'channel', label: 'Send on', type: 'select', span: 2, options: [
        { value: 'whatsapp', label: 'WhatsApp' },
        { value: 'telegram', label: 'Telegram' },
        { value: 'email', label: 'Email' }] },
    ...(kind === 'invoice' || kind === 'quotation'
      ? [{ name: 'note', label: 'Add a line of your own', type: 'textarea', span: 2 }]
      : [])
  ]);

  const out = el('div', { style: 'margin-top:14px' });

  const send = el('button', { class: 'btn primary', onclick: async () => {
    send.disabled = true;
    fill(out, loading('Sending ' + ids.length + '…'));
    try {
      const res = await api.post('/messages/send-many', { kind, ids, ...readFields(fields) });
      const d = res.data;

      fill(out,
        el('p', { style: 'margin:0 0 10px;font-weight:550;color:'
                       + (d.failed.length ? 'var(--hold)' : 'var(--in)') }, res.message),
        d.sent.length
          ? el('div', { style: 'font-size:13px;color:var(--muted)' },
              el('strong', {}, 'Went: '), d.sent.map(x => x.name).join(', '))
          : null,
        d.failed.length
          ? el('div', { style: 'font-size:13px;color:var(--out);margin-top:8px' },
              el('strong', {}, 'Did not go: '),
              d.failed.map(x => x.name + ' (' + x.why + ')').join(', '))
          : null);

      if (!d.failed.length) {
        setTimeout(() => { m.close(); onDone && onDone(); }, 1600);
      } else {
        onDone && onDone();
      }
    } catch (e) {
      fail(e);
      fill(out, el('p', { style: 'color:var(--out)' }, e.message || 'Could not send'));
    }
    send.disabled = false;
  } }, 'Send ' + ids.length + ' ' + what + (ids.length === 1 ? '' : 's'));

  const m = modal({
    title: 'Send ' + ids.length + ' ' + what + (ids.length === 1 ? '' : 's'),
    body: el('div', {}, fields, out),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Close'), send]
  });
}
