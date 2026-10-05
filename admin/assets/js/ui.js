/* AH5 Office — UI toolkit
   Designed & Developed by Anwar Hossain — https://anwar.com.bd */

export const el = (tag, attrs = {}, ...children) => {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') node.className = v;
    else if (k === 'html') node.innerHTML = v;
    else if (k === 'text') node.textContent = v;
    else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
    else if (k === 'dataset') Object.assign(node.dataset, v);
    else node.setAttribute(k, v === true ? '' : String(v));
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined || c === false) continue;
    if (c.nodeType) { node.append(c); continue; }
    const text = String(c);
    node.append(document.createTextNode(text === 'null' || text === 'undefined' ? '—' : text));
  }
  return node;
};

export const esc = s => String(s ?? '').replace(/[&<>"']/g,
  c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ------------------------------------------------------------ formatting */

/**
 * Money the way it is spoken: 1,800 — not 1,800.00.
 * Paisa only appear when there actually are some.
 */
export const money = (v, currency) => {
  const value = Number(v || 0);
  const hasFraction = Math.abs(value % 1) > 0.004;
  const n = value.toLocaleString('en-US', {
    minimumFractionDigits: hasFraction ? 2 : 0,
    maximumFractionDigits: 2
  });
  return currency ? n + ' ' + currency : n;
};

/** Join the pieces that are actually filled in: "JOB-0001 · Rahim Traders". */
export const join = (...parts) => {
  const kept = parts.filter(p => p !== null && p !== undefined && String(p).trim() !== '');
  return kept.length ? kept.join(' · ') : '—';
};

/**
 * Put children into a node, ignoring the empty ones.
 * replaceChildren() turns a null into the text "null", which is how the
 * word was leaking onto pages whenever an optional block was absent.
 */
export const fill = (host, ...kids) => {
  host.replaceChildren(...kids.flat().filter(k => k !== null && k !== undefined && k !== false));
  return host;
};

/** First value that is actually filled in, else a dash. */
export const first = (...values) => {
  for (const v of values) {
    if (v !== null && v !== undefined && String(v).trim() !== '') return v;
  }
  return '—';
};

export const qty = v => {
  const n = Number(v || 0);
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '');
};

export const date = d => {
  if (!d) return '—';
  const t = new Date(String(d).replace(' ', 'T'));
  if (isNaN(t)) return String(d);
  return t.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

export const today = () => new Date().toISOString().slice(0, 10);
export const addDays = (n, from) => {
  const d = from ? new Date(from) : new Date();
  d.setDate(d.getDate() + n);
  return d.toISOString().slice(0, 10);
};

/** Overdue / days-left phrasing, written from the reader's side. */
export const daysPhrase = n => {
  if (n === null || n === undefined || n === '') return '—';
  const d = Number(n);
  if (d === 0) return 'today';
  return d > 0 ? d + ' days left' : Math.abs(d) + ' days over';
};

const TAG_TONE = {
  paid: 'in', completed: 'in', delivered: 'in', accepted: 'in', sent: 'info', active: 'in', renewed: 'in',
  overdue: 'out', unpaid: 'out', rejected: 'out', cancelled: 'out', expired: 'out', failed: 'out',
  pending: 'hold', partial: 'hold', draft: 'hold', in_progress: 'hold', on_hold: 'hold', queued: 'hold'
};

export const tag = status => {
  const s = String(status || '').toLowerCase();
  return el('span', { class: 'tag ' + (TAG_TONE[s] || '') }, s.replace(/_/g, ' '));
};

/* ---------------------------------------------------------------- toasts */

let toastHost = null;
export function toast(message, kind = 'ok') {
  if (!toastHost) {
    toastHost = el('div', { class: 'toasts' });
    document.body.append(toastHost);
  }
  const node = el('div', { class: 'toast ' + kind }, message);
  toastHost.append(node);
  setTimeout(() => node.remove(), kind === 'err' ? 6000 : 3200);
}

export const fail = err => toast(err && err.detail ? err.detail : String(err), 'err');

/* ----------------------------------------------------------------- modal */

export function modal({ title, body, footer, wide = false, onClose }) {
  const backdrop = el('div', { class: 'modal-backdrop' });
  const close = () => { backdrop.remove(); document.removeEventListener('keydown', onKey); if (onClose) onClose(); };
  const onKey = e => { if (e.key === 'Escape') close(); };

  const box = el('div', { class: 'modal' + (wide ? ' wide' : '') },
    el('div', { class: 'modal-head' },
      el('h2', {}, title),
      el('button', { class: 'icon-btn', 'aria-label': 'Close', onclick: close }, '\u00d7')
    ),
    el('div', { class: 'modal-body' }, body),
    footer ? el('div', { class: 'modal-foot' }, footer) : null
  );

  backdrop.append(box);
  backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', onKey);
  document.body.append(backdrop);

  const firstInput = box.querySelector('input, select, textarea');
  if (firstInput) firstInput.focus();

  return { close, box };
}

/** Confirm dialog. Resolves true/false. */
export function confirmAction(title, message, confirmLabel = 'Delete') {
  return new Promise(resolve => {
    let done = false;
    const finish = v => { if (!done) { done = true; resolve(v); } };
    const m = modal({
      title,
      body: el('p', { style: 'margin:0;color:var(--muted)' }, message),
      footer: [
        el('button', { class: 'btn', onclick: () => { finish(false); m.close(); } }, 'Cancel'),
        el('button', { class: 'btn danger', onclick: () => { finish(true); m.close(); } }, confirmLabel)
      ],
      onClose: () => finish(false)
    });
  });
}

/* ------------------------------------------------------------------ form */

/**
 * fields: [{ name, label, type, options, value, required, span, hint, attrs }]
 * type: text | number | date | select | textarea | checkbox
 */
export function formFields(fields, values = {}) {
  const wrap = el('div', { class: 'grid c2' });
  for (const f of fields) {
    if (!f) continue;
    const v = values[f.name] ?? f.value ?? '';
    let input;

    if (f.type === 'select') {
      input = el('select', { name: f.name, ...(f.attrs || {}) },
        ...(f.options || []).map(o => el('option', {
          value: o.value,
          selected: String(o.value) === String(v) || undefined
        }, o.label))
      );
    } else if (f.type === 'textarea') {
      input = el('textarea', { name: f.name, ...(f.attrs || {}) }, v);
    } else if (f.type === 'checkbox') {
      input = el('input', { type: 'checkbox', name: f.name, checked: !!v || undefined,
        style: 'width:auto', ...(f.attrs || {}) });
    } else {
      input = el('input', {
        type: f.type || 'text', name: f.name, value: v,
        step: f.type === 'number' ? (f.step || '0.01') : undefined,
        ...(f.attrs || {})
      });
    }
    if (f.required) input.required = true;

    const label = el('label', { class: 'field', style: f.span === 2 ? 'grid-column:1/-1' : undefined },
      el('span', {}, f.label + (f.required ? ' *' : '')),
      input,
      f.hint ? el('small', { style: 'color:var(--faint);font-size:11.5px' }, f.hint) : null
    );
    wrap.append(label);
  }
  return wrap;
}

/** Collect a plain object from a container's inputs. */
export function readFields(container) {
  const out = {};
  container.querySelectorAll('input[name], select[name], textarea[name]').forEach(i => {
    if (i.type === 'checkbox') { out[i.name] = i.checked ? 1 : 0; return; }
    // A number left blank on purpose (a price, a quantity) means "use the
    // usual one" - the backend already knows how to fall back for those,
    // so an empty number field is left out rather than sent as "".
    // Every other kind of field always sends what it holds, blank or not -
    // otherwise there is no way to clear a heading or a note back to
    // nothing; the old value would just sit there forever, unreachable.
    if (i.type === 'number' && i.value === '') return;
    out[i.name] = i.value;
  });
  return out;
}

/* ----------------------------------------------------------------- table */

/**
 * columns: [{ key, label, num, render(row), width }]
 */
/**
 * Turn a cell value into something safe to show. An empty value reads as a
 * dash, never as the word "null" — that leaked through before.
 */
export const cell = value => {
  if (value === null || value === undefined || value === '') return '—';
  if (value.nodeType) return value;
  const text = String(value);
  return (text === 'null' || text === 'undefined' || text.trim() === '') ? '—' : text;
};


/**
 * A tick box on every row, and one in the header that takes the lot.
 * Returns the checkbox column to prepend, plus a way to read what is ticked
 * and to hear about it when the selection changes.
 */
export function rowPicker(rows, onChange) {
  const boxes = new Map();

  const all = el('input', {
    type: 'checkbox', style: 'width:auto',
    onchange: e => {
      boxes.forEach(b => { b.checked = e.target.checked; });
      onChange && onChange();
    }
  });

  const column = {
    key: '_pick',
    label: all,
    width: '34px',
    render: row => {
      const box = el('input', {
        type: 'checkbox', style: 'width:auto',
        onclick: e => e.stopPropagation(),
        onchange: () => {
          all.checked = rows.length > 0 && [...boxes.values()].every(b => b.checked);
          onChange && onChange();
        }
      });
      boxes.set(row.id, box);
      return box;
    }
  };

  return {
    column,
    chosen: () => [...boxes.entries()].filter(([, b]) => b.checked).map(([id]) => id),
    clear: () => { boxes.forEach(b => { b.checked = false; }); all.checked = false; }
  };
}

export function table(columns, rows, { onRowClick, empty } = {}) {
  if (!rows || !rows.length) {
    return el('div', { class: 'empty' },
      el('strong', {}, (empty && empty.title) || 'Nothing here yet'),
      el('div', {}, (empty && empty.hint) || 'Add the first record to get started.')
    );
  }

  const head = el('tr', {}, ...columns.map(c =>
    el('th', { class: c.num ? 'num' : null, style: c.width ? 'width:' + c.width : null }, c.label)));

  const body = rows.map(row => {
    const tr = el('tr', { class: onRowClick ? 'clickable' : null },
      ...columns.map(c => {
        const content = c.render ? c.render(row) : row[c.key];
        return el('td', { class: c.num ? 'num' : null }, cell(content));
      })
    );
    if (onRowClick) {
      tr.addEventListener('click', e => {
        if (e.target.closest('button, a, input')) return;
        onRowClick(row);
      });
    }
    return tr;
  });

  return el('div', { class: 'table-scroll' },
    el('table', { class: 'data' },
      el('thead', {}, head),
      el('tbody', {}, ...body)
    )
  );
}


/**
 * Page controls. meta comes straight from the API's paginated response.
 * Renders nothing when everything fits on one page.
 */
export function pager(meta, onPage) {
  if (!meta || meta.total_pages <= 1) return null;
  const page = Number(meta.page);
  const last = Number(meta.total_pages);

  const step = (label, target, disabled) => el('button', {
    class: 'btn sm', disabled: disabled || undefined,
    onclick: () => onPage(target)
  }, label);

  return el('div', {
    style: 'display:flex;align-items:center;gap:10px;justify-content:flex-end;padding:12px 16px;border-top:1px solid var(--rule-soft)'
  },
    el('span', { style: 'color:var(--muted);font-size:12.5px' },
      'Showing page ' + page + ' of ' + last + ' · ' + meta.total + ' records'),
    step('Previous', page - 1, page <= 1),
    step('Next', page + 1, page >= last)
  );
}

export const card = (title, bodyNode, actions, tight = false) =>
  el('div', { class: 'card' },
    title ? el('div', { class: 'card-head' },
      el('h2', {}, title),
      actions ? el('div', { class: 'page-actions' }, actions) : null
    ) : null,
    el('div', { class: 'card-body' + (tight ? ' tight' : '') }, bodyNode)
  );

export const tile = (label, value, note, tone) =>
  el('div', { class: 'tile' + (tone ? ' ' + tone : '') },
    el('span', { class: 'eyebrow' }, label),
    el('span', { class: 'amount' }, value),
    note ? el('small', {}, note) : null
  );

export const loading = (msg = 'Loading…') => el('div', { class: 'loading' }, msg);

/** Per-currency rows -> "12,000.00 BDT · 450.00 AED" */
export const byCurrency = (rows, field = 'amount') => {
  if (!rows || !rows.length) return '0';
  return rows.map(r => money(r[field], r.currency)).join('  ·  ');
};


/* ------------------------------------------------------------ file list */

const KIND = name => {
  const ext = String(name || '').split('.').pop().toLowerCase();
  return ['jpg', 'jpeg', 'png', 'webp'].includes(ext) ? 'img' : ext.slice(0, 4);
};

/**
 * Files attached to one thing: a payment's proof, a passport scan.
 * View opens it in a tab, Download saves it — two separate buttons,
 * because opening a licence to read it is not the same as filing it away.
 */
export function fileManager(api, entityType, entityId, { canEdit = true, compact = false } = {}) {
  const host = el('div', { class: 'files' });
  const wrap = el('div', {});

  const draw = async () => {
    fill(host, loading('…'));
    let files = [];
    try {
      files = (await api.get('/attachments/' + entityType + '/' + entityId)).data;
    } catch (e) {
      fill(host, el('p', { style: 'color:var(--muted);margin:0' }, 'Could not load the files.'));
      return;
    }

    const rows = files.map(f => el('div', { class: 'file-row' },
      el('div', { class: 'file-kind' }, KIND(f.file_name)),
      el('div', { class: 'name' },
        f.label || f.file_name,
        el('small', {}, join(f.label ? f.file_name : null, f.size_label, date(f.created_at)))),
      f.can_view
        ? el('a', { class: 'btn sm', href: f.view_url, target: '_blank', rel: 'noopener' }, 'View')
        : null,
      el('a', { class: 'btn sm', href: f.download_url }, 'Download'),
      canEdit ? el('button', { class: 'btn sm danger', onclick: async () => {
        if (!await confirmAction('Remove file', f.file_name + ' will be deleted from the server.')) return;
        try { await api.del('/attachments/' + f.id); toast('File removed'); draw(); }
        catch (e) { fail(e); }
      } }, 'Remove') : null
    ));

    if (!rows.length && !canEdit) {
      rows.push(el('p', { style: 'color:var(--muted);margin:0;font-size:13px' }, 'No files yet.'));
    }
    fill(host, ...rows);
  };

  const picker = el('input', {
    type: 'file', style: 'display:none',
    accept: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.zip'
  });

  const upload = async files => {
    for (const file of files) {
      const fd = new FormData();
      fd.append('file', file);
      try {
        const res = await fetch(api.base + '/attachments/' + entityType + '/' + entityId, {
          method: 'POST',
          headers: { Authorization: 'Bearer ' + api.store.access },
          body: fd
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || 'Upload failed');
      } catch (e) {
        toast(file.name + ': ' + e.message, 'err');
      }
    }
    toast('Files attached');
    picker.value = '';
    draw();
  };

  picker.addEventListener('change', () => {
    if (picker.files && picker.files.length) upload([...picker.files]);
  });

  const zone = el('div', {
    class: 'drop-zone',
    onclick: () => picker.click(),
    ondragover: e => { e.preventDefault(); zone.style.borderColor = 'var(--ink-soft)'; },
    ondragleave: () => { zone.style.borderColor = ''; },
    ondrop: e => {
      e.preventDefault();
      zone.style.borderColor = '';
      if (e.dataTransfer && e.dataTransfer.files.length) upload([...e.dataTransfer.files]);
    }
  }, compact ? 'Attach a file' : 'Drop a file here, or click to choose — PDF, image, Word, Excel (max 10 MB)');

  wrap.append(host);
  if (canEdit) wrap.append(zone, picker);

  draw();
  wrap.refresh = draw;
  return wrap;
}

/** A copy-to-clipboard button that reports what happened. */
export const copyButton = (getText, label = 'Copy text') =>
  el('button', {
    class: 'btn sm',
    onclick: async ev => {
      const text = typeof getText === 'function' ? getText() : getText;
      try {
        await navigator.clipboard.writeText(text);
        toast('Copied to clipboard');
      } catch {
        const ta = el('textarea', { style: 'position:fixed;opacity:0' }, text);
        document.body.append(ta); ta.select();
        document.execCommand('copy'); ta.remove();
        toast('Copied to clipboard');
      }
      ev.currentTarget.blur();
    }
  }, label);
