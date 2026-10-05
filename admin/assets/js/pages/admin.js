/* AH5 Office — documents, expenses, reports, messages, settings
   Designed & Developed by Anwar Hossain — https://anwar.com.bd */

import { api } from '../api.js';
import * as work from './work.js';
import {
  el, table, card, tile, money, date, today, addDays, tag, modal, formFields,
  readFields, toast, fail, confirmAction, byCurrency, daysPhrase, loading, copyButton, first, join, fileManager, fill, rowPicker
} from '../ui.js';


/* ============================================================ documents */

export async function documents(ctx) {
  let customerFilter = ctx.query.get('customer') || '';

  const docBar = el('div', {
    class: 'card',
    style: 'display:none;align-items:center;gap:12px;padding:12px 16px;margin-bottom:14px'
  });
  let docPicker = null;

  // narrow to one customer, or show everyone grouped
  const customerPicker = el('select', {
    onchange: e => { customerFilter = e.target.value; load(); }
  }, el('option', { value: '' }, 'All customers'));
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let window_ = 30;

  const load = async () => {
    fill(host, loading());
    const [exp, all, types, custRes] = await Promise.all([
      api.get('/documents/expiring', { days: window_ }),
      api.get('/documents', { per_page: 200, customer_id: customerFilter || undefined }),
      api.get('/document-types'),
      api.get('/customers', { per_page: 200 })
    ]);
    page._types = types.data;
    page._customers = custRes.data;

    docPicker = rowPicker(all.data, () => {
      const n = docPicker.chosen().length;
      docBar.style.display = n ? 'flex' : 'none';
      fill(docBar,
        el('span', {}, n + ' ticked'),
        el('button', { class: 'btn sm primary', onclick: () =>
          work.sendManyForm('document', docPicker.chosen(), load) }, 'Send reminders'),
        el('button', { class: 'btn sm',
          onclick: () => { docPicker.clear(); docBar.style.display = 'none'; } }, 'Clear'));
    });

    if (customerPicker.options.length <= 1) {
      custRes.data.forEach(c => customerPicker.append(
        el('option', { value: c.id, selected: String(c.id) === customerFilter || undefined },
          first(c.company_name, c.name))));
    }

    const actions = r => el('div', { style: 'display:flex;gap:6px' },
      el('button', { class: 'btn sm', onclick: async () => {
        try {
          const full = await api.get('/documents/' + r.id);
          documentForm(page._customers || [], page._types || [], load, full.data);
        } catch (e) { fail(e); }
      } }, 'Edit'),
      el('button', { class: 'btn sm', onclick: () => remindForm(r, load) }, 'Remind'),
      el('button', { class: 'btn sm', onclick: () => renewForm(r, load) }, 'Renew'),
      el('button', { class: 'btn sm', onclick: () => filesFor(r) },
        Number(r.file_count) ? 'Files (' + r.file_count + ')' : 'Attach'));

    fill(host, 
      el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
        tile('Already expired', String(exp.data.count.expired), 'needs attention now', 'out'),
        tile('Expiring in ' + window_ + ' days', String(exp.data.count.soon), 'renewals coming up', 'hold'),
        tile('On file', String(all.meta.total), 'documents tracked')),

      card('Coming up',
        table([
          { key: 'title', label: 'Document', render: r => el('div', {},
              el('strong', {}, r.title),
              el('span', { class: 'sub' }, join(r.customer_name, r.doc_type_name))) },
          { key: 'expiry_date', label: 'Expires', render: r => date(r.expiry_date) },
          { key: 'days_left', label: '', render: r => el('span',
              { class: 'tag ' + (Number(r.days_left) < 0 ? 'out' : 'hold') }, daysPhrase(r.days_left)) },
          { key: 'act', label: '', render: actions }
        ], [...exp.data.expired, ...exp.data.soon], {
          empty: { title: 'Nothing expiring', hint: 'No renewals inside this window.' }
        }), null, true),

      docBar,
      ...documentsByCustomer(all.data, actions, docPicker)
    );
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Renewals'),
        el('h1', {}, 'Documents'),
        el('p', {}, 'Domains, hosting, licences, visas — anything with an expiry date.')),
      el('div', { class: 'page-actions' },
        customerPicker,
        el('select', { onchange: e => { window_ = Number(e.target.value); load(); } },
          el('option', { value: '30' }, 'Next 30 days'),
          el('option', { value: '60' }, 'Next 60 days'),
          el('option', { value: '90' }, 'Next 90 days')),
        el('button', { class: 'btn', onclick: () => typeManager(load) }, 'Document types'),
        el('button', { class: 'btn primary',
          onclick: () => documentForm(page._customers || [], page._types || [], load) }, 'Add document'))),
    host
  );

  await load();
  return page;
}

function documentForm(customers, types, onDone, existing) {
  const custom = el('div', {});
  const pending = {};        // field_key -> File chosen but not uploaded yet

  /** Send every file the form is holding for one type's fields, once that
   *  document has an id. $prefix is '' when editing a single document,
   *  or "<typeId>::" while creating several at once. */
  const uploadPending = async (docId, prefix = '') => {
    for (const [key, picked] of Object.entries(pending)) {
      if (!key.startsWith(prefix) || key.slice(prefix.length).includes('::')) {
        continue;   // belongs to a different type's block on this screen
      }
      const fieldKey = key.slice(prefix.length);
      const files = Array.isArray(picked) ? picked : (picked ? [picked] : []);
      for (const file of files) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('label', 'field:' + fieldKey);
        try {
          const res = await fetch(api.base + '/attachments/document/' + docId, {
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
    }
  };
  const fields = formFields([
    { name: 'customer_id', label: 'Customer', type: 'select', required: true, span: 2,
      options: [{ value: '', label: 'Choose…' },
        ...customers.map(c => ({ value: c.id, label: c.company_name || c.name }))] },
    { name: 'doc_type_id', label: 'Type', type: 'select',
      options: [{ value: '', label: '—' }, ...types.map(t => ({ value: t.id, label: t.name }))] },
    { name: 'title', label: 'Title', required: true, hint: 'e.g. example.com domain' },
    { name: 'issue_date', label: 'Issued on', type: 'date' },
    { name: 'expiry_date', label: 'Expires on', type: 'date', required: true },
    { name: 'remind_days', label: 'Remind me', hint: 'days before expiry, e.g. 30,15,7,1', value: '30,15,7,1' },
    { name: 'remind_customer', label: 'Also remind the customer', type: 'checkbox', value: 1 },
    { name: 'remind_self', label: 'Remind me', type: 'checkbox', value: 1 },
    { name: 'note', label: 'Note', type: 'textarea', span: 2 }
  ], existing || {});

  const typeSel = fields.querySelector('[name=doc_type_id]');

  /**
   * One customer often hands you several papers at once — passport, visa,
   * trade licence. A native multi-select is hard to work with a finger on
   * a phone (no reliable way to tap more than one option), so this is a
   * plain list of checkboxes instead — tapping one never affects another.
   */
  let typeChecks = [];
  const selectedTypeIds = () => typeChecks.filter(c => c.checked).map(c => Number(c.value));

  if (!existing) {
    const wrapper = typeSel.closest('.field');
    typeChecks = types.map(t => el('input', { type: 'checkbox', value: t.id, style: 'width:auto' }));
    const list = el('div', { class: 'type-checklist' },
      ...types.map((t, i) => el('label', { class: 'check-row' }, typeChecks[i], el('span', {}, t.name))));
    typeChecks.forEach(c => c.addEventListener('change', () => drawCustom()));

    const typeField = el('label', { class: 'field', style: 'grid-column:1/-1' },
      el('span', {}, 'Type'),
      list,
      el('small', { style: 'color:var(--faint);font-size:11.5px' },
        'Tick more than one and a separate document is made for each.'));
    if (wrapper) wrapper.replaceWith(typeField);
  }


  /** Draw the extra questions each selected type asks for - one block per
   *  type when creating (so every document gets filled in at once, not
   *  left blank to open and finish later), or the one type's own block
   *  when editing an existing single document. */
  const drawCustom = (answers = {}) => {
    if (existing) {
      const type = types.find(t => String(t.id) === typeSel.value);
      const list = (type && type.fields) || [];
      if (!list.length) {
        fill(custom);
        return;
      }
      fill(custom,
        el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px' },
          (type.name || 'Type') + ' details'),
        formFields(list.filter(f => f.field_type !== 'file').map(f => fieldSpec(f, '')), answers),
        ...list.filter(f => f.field_type === 'file').map(f => fileField(f, ''))
      );
      return;
    }

    // creating new: each ticked checkbox is its own type - reading it this
    // way (rather than a multi-select) is what makes tapping several of
    // them on a phone actually work
    const chosenTypes = selectedTypeIds()
      .map(id => types.find(t => Number(t.id) === id))
      .filter(Boolean);

    if (!chosenTypes.length) {
      fill(custom);
      return;
    }

    const blocks = [];
    for (const type of chosenTypes) {
      const list = type.fields || [];
      if (!list.length) {
        continue;
      }
      const prefix = type.id + '::';
      blocks.push(
        el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px' },
          type.name + ' details'),
        formFields(list.filter(f => f.field_type !== 'file').map(f => fieldSpec(f, prefix)), answers),
        ...list.filter(f => f.field_type === 'file').map(f => fileField(f, prefix))
      );
    }
    fill(custom, ...blocks);
  };

  /** One custom field's formFields() spec - the same shape either way, just
   *  a different name prefix so several types' fields can share one form
   *  without a field from one type overwriting a same-named field from another. */
  function fieldSpec(f, prefix) {
    return {
      name: 'cf__' + prefix + f.field_key,
      label: f.label + (Number(f.is_archived) ? ' (retired)' : ''),
      type: f.field_type === 'select' ? 'select'
          : f.field_type === 'textarea' ? 'textarea'
          : f.field_type === 'checkbox' ? 'checkbox'
          : f.field_type,
      required: !!Number(f.is_required) && !Number(f.is_archived),
      options: f.field_type === 'select'
        ? [{ value: '', label: '—' },
           ...String(f.options || '').split(/\r?\n/).filter(Boolean).map(o => ({ value: o, label: o }))]
        : undefined,
      attrs: f.placeholder ? { placeholder: f.placeholder } : undefined,
      span: f.field_type === 'textarea' ? 2 : 1
    };
  }

  /**
   * One "file" answer. A field marked "many" takes several pages at once —
   * a passport has a photo page and a signature page. $prefix keeps one
   * type's file picker from colliding with another type's when several
   * are being filled in on the same screen.
   */
  function fileField(f, prefix) {
    const many = !!Number(f.allow_multiple);
    const held = f.files || (f.file ? [f.file] : []);
    const pendKey = prefix + f.field_key;

    const chosen = el('span', { class: 'sub' }, '');
    const picker = el('input', {
      type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.webp',
      multiple: many || undefined,
      onchange: () => {
        const list = picker.files ? [...picker.files] : [];
        pending[pendKey] = many ? list : (list[0] || null);
        chosen.textContent = list.map(x => x.name).join(', ');
      }
    });

    const row = file => el('div', { class: 'file-row', style: 'margin-bottom:6px' },
      el('div', { class: 'file-kind' }, String(file.file_name).split('.').pop().slice(0, 4)),
      el('div', { class: 'name' }, file.file_name, el('small', {}, file.size_label)),
      file.can_view
        ? el('a', { class: 'btn sm', href: file.view_url, target: '_blank', rel: 'noopener' }, 'View')
        : null,
      el('a', { class: 'btn sm', href: file.download_url }, 'Download'));

    return el('label', { class: 'field' },
      el('span', {}, f.label + (Number(f.is_required) ? ' *' : '')),
      ...held.map(row),
      picker,
      chosen,
      el('span', { class: 'hint' },
        many
          ? 'PDF, JPG or PNG — pick as many as you need'
          : (held.length ? 'Choose a file to add another' : 'PDF, JPG or PNG'))
    );
  }

  typeSel.addEventListener('change', () => drawCustom());
  drawCustom(existing && existing.fields
    ? Object.fromEntries(existing.fields.map(f => ['cf__' + f.field_key, f.value ?? '']))
    : {});

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);

      if (existing) {
        const answers = readFields(custom);
        body.fields = {};
        for (const [k, v] of Object.entries(answers)) {
          if (k.startsWith('cf__')) body.fields[k.slice(4)] = v;
        }
        await api.patch('/documents/' + existing.id, body);
        await uploadPending(existing.id);
        toast('Document updated');
        m.close(); onDone();
      } else {
        const chosen = selectedTypeIds();

        if (!chosen.length) {
          toast('Pick at least one type', 'err');
          save.disabled = false;
          return;
        }

        // one field-answers object holds every selected type's questions at
        // once (each named "cf__<typeId>::<fieldKey>"), so each document
        // below pulls out only the slice that belongs to it
        const answers = readFields(custom);

        let made = 0;
        for (const typeId of chosen) {
          const type = types.find(t => Number(t.id) === typeId);
          const ownPrefix = 'cf__' + typeId + '::';
          const ownFields = {};
          for (const [k, v] of Object.entries(answers)) {
            if (k.startsWith(ownPrefix)) ownFields[k.slice(ownPrefix.length)] = v;
          }
          try {
            const res = await api.post('/documents', {
              ...body,
              doc_type_id: typeId,
              title: chosen.length > 1 ? (type ? type.name : body.title) : body.title,
              fields: ownFields
            });
            await uploadPending(res.data.id, typeId + '::');
            made++;
          } catch (e) {
            toast((type ? type.name : 'One type') + ': ' + (e.message || 'could not add'), 'err');
          }
        }

        toast(made > 1 ? made + ' documents added, each with its own details' : 'Document added');
        m.close(); onDone();
        return;
      }
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Add document');

  const filesBlock = existing
    ? null
    : el('p', { style: 'color:var(--faint);font-size:12.5px;margin:16px 0 0' },
        'The files you pick above upload as soon as you save.');

  const m = modal({
    title: existing ? existing.title : 'Add document',
    body: el('div', {}, fields, custom, filesBlock), wide: true,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

function renewForm(doc, onDone) {
  const fields = formFields([
    { name: 'new_expiry_date', label: 'New expiry date', type: 'date', required: true,
      value: addDays(365, doc.expiry_date) }
  ]);
  const save = el('button', { class: 'btn primary', onclick: async () => {
    try { await api.post('/documents/' + doc.id + '/renew', readFields(fields));
      toast('Renewed'); m.close(); onDone(); }
    catch (e) { fail(e); }
  } }, 'Save renewal');
  const m = modal({
    title: 'Renew ' + doc.title,
    body: el('div', {}, el('p', { style: 'color:var(--muted);margin-top:0' },
      'Current expiry: ' + date(doc.expiry_date)), fields),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

function remindForm(doc, onDone) {
  const fields = formFields([
    { name: 'channel', label: 'Send on', type: 'select', options: [
        { value: 'whatsapp', label: 'WhatsApp' }, { value: 'telegram', label: 'Telegram' },
        { value: 'email', label: 'Email' }] },
    { name: 'to', label: 'Send to', type: 'select', options: [
        { value: 'customer', label: 'The customer' }, { value: 'self', label: 'Me only' },
        { value: 'both', label: 'Both of us' }] }
  ]);
  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const r = await api.post('/documents/' + doc.id + '/remind', readFields(fields));
      const results = Object.values(r.data.results || {});
      const bad = results.find(x => !x.success);
      toast(bad ? bad.error : 'Reminder sent', bad ? 'err' : 'ok');
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Send reminder');

  const m = modal({
    title: 'Remind about ' + doc.title,
    body: el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'Expires ' + date(doc.expiry_date) + ' — ' + daysPhrase(doc.days_left) + '.'),
      fields),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}



/** Manage document types and decide what information each one holds. */
function typeManager(onDone) {
  const host = el('div', {});

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/document-types');
    fill(host, 
      table([
        { key: 'name', label: 'Type', render: r => el('div', {},
            el('strong', {}, r.name),
            el('span', { class: 'sub' }, r.name)) },
        { key: 'fields', label: 'Information it holds', render: r => r.fields.length
            ? r.fields.map(f => f.label).join(', ')
            : el('span', { style: 'color:var(--muted)' }, 'only the standard fields') },
        { key: 'default_remind_days', label: 'Reminds', render: r =>
            (r.default_remind_days || '—') + ' days before' },
        { key: 'in_use', label: 'In use', num: true },
        { key: 'file_count', label: 'Files', render: r => Number(r.file_count)
            ? el('span', { class: 'clip' }, '📎 ' + r.file_count) : '—' },
        { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
            el('button', { class: 'btn sm', onclick: () => typeForm(r, load) }, 'Edit'),
            el('button', { class: 'btn sm danger', onclick: async () => {
              if (!await confirmAction('Remove ' + r.name,
                Number(r.in_use) ? 'It is used by ' + r.in_use + ' document(s), so it will be switched off.'
                                 : 'This type will be removed.')) return;
              try { const rr = await api.del('/document-types/' + r.id); toast(rr.message); load(); }
              catch (e) { fail(e); }
            } }, 'Remove')) }
      ], res.data, { empty: { title: 'No types yet', hint: 'Add one, and say what it should record.' } })
    );
  };

  const m = modal({
    title: 'Document types', wide: true,
    body: el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'Each type decides what it records. A trade licence is not a passport, so give each one its own fields.'),
      host),
    footer: [
      el('button', { class: 'btn', onclick: () => { m.close(); onDone(); } }, 'Done'),
      el('button', { class: 'btn primary', onclick: () => typeForm(null, load) }, 'Add type')
    ]
  });

  load();
}

/** Build a type and its field list. */
function typeForm(existing, onDone) {
  const fields = formFields([
    { name: 'name', label: 'Type name', required: true, hint: 'Trade Licence, Passport, Visa…' },
    { name: 'default_remind_days', label: 'Remind me', span: 2,
      hint: 'days before expiry, e.g. 60,30,7' }
  ], existing || { default_remind_days: '30,15,7,1' });

  const rows = el('tbody', {});

  const addRow = (f = {}) => {
    const label = el('input', { value: f.label || '', placeholder: 'Licence number' });
    const type = el('select', {},
      ...[['text', 'Text'], ['number', 'Number'], ['date', 'Date'],
          ['select', 'Choose from a list'], ['textarea', 'Long text'],
          ['checkbox', 'Yes / no'], ['file', 'File (PDF, photo)']]
        .map(([v, l]) => el('option', { value: v, selected: (f.field_type || 'text') === v || undefined }, l)));
    const options = el('input', { value: f.options || '', placeholder: 'one choice per line' });
    const required = el('input', { type: 'checkbox', style: 'width:auto', checked: Number(f.is_required) ? true : undefined });
    const multi = el('input', { type: 'checkbox', style: 'width:auto',
      checked: Number(f.allow_multiple) ? true : undefined });

    const toggleOptions = () => {
      options.disabled = type.value !== 'select';
      multi.disabled = type.value !== 'file';   // only a file field can take several
      if (multi.disabled) multi.checked = false;
    };
    type.addEventListener('change', toggleOptions);
    toggleOptions();

    const tr = el('tr', {},
      el('td', {}, label),
      el('td', { style: 'width:150px' }, type),
      el('td', {}, options),
      el('td', { style: 'width:40px;text-align:center' }, required),
      el('td', { style: 'width:52px;text-align:center' }, multi),
      el('td', { style: 'width:34px' },
        el('button', { class: 'icon-btn', title: 'Remove', onclick: () => tr.remove() }, '\u00d7'))
    );
    tr._read = () => ({
      id: f.id || undefined,
      field_key: f.field_key || undefined,
      label: label.value.trim(),
      field_type: type.value,
      options: type.value === 'select' ? options.value : null,
      is_required: required.checked ? 1 : 0,
      allow_multiple: multi.checked ? 1 : 0
    });
    rows.append(tr);
  };

  ((existing && existing.fields) || []).forEach(addRow);
  if (!rows.children.length) addRow();

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      body.fields = [...rows.querySelectorAll('tr')].map(tr => tr._read()).filter(f => f.label);
      if (existing) {
        await api.patch('/document-types/' + existing.id, body);
        toast('Type updated');
        m.close(); onDone();
      } else {
        const res = await api.post('/document-types', body);
        toast('Type added');
        m.close(); onDone();
        typeForm(res.data, onDone);      // reopen so files can go on it
        return;
      }
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Add type');

  const sampleBlock = null;

  const m = modal({
    title: existing ? 'Edit ' + existing.name : 'New document type', wide: true,
    body: el('div', {}, fields,
      el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px' },
        'What should this type record'),
      el('p', { style: 'color:var(--faint);font-size:12px;margin:0 0 8px' },
        'Customer, title, dates and reminders are always there. Add anything else this kind of document carries.'),
      el('table', { class: 'lines' },
        el('thead', {}, el('tr', {},
          el('th', {}, 'Question'), el('th', {}, 'Answer type'),
          el('th', {}, 'Choices'), el('th', {}, 'Must'),
          el('th', { title: 'A file field that takes several files' }, 'Many'), el('th', {}))),
        rows),
      el('button', { class: 'btn sm', style: 'margin-top:10px', onclick: () => addRow() }, '+ Add field'),
      sampleBlock),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

/** Every file on this document: view one, download one, add more. */
function filesFor(doc) {
  const m = modal({
    title: doc.title,
    wide: true,
    body: el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'Passport pages, licence scans, anything that belongs to this record. '
        + 'View opens it in a tab; Download saves it.'),
      fileManager(api, 'document', doc.id)),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Done')]
  });
}

/**
 * The category-and-date-range row every money filter in this app shares -
 * built once so the report and the list read the same way and never drift
 * apart from each other.
 */
function moneyFilters(cats, state, onChange) {
  const catSel = el('select', {
    onchange: e => { state.category_id = e.target.value; onChange(); }
  },
    el('option', { value: '' }, 'Select Expense Category'),
    ...cats.map(c => el('option', { value: c.id }, c.name)));

  const fromInput = el('input', {
    type: 'date', 'aria-label': 'Start Date',
    onchange: e => { state.from = e.target.value; onChange(); }
  });
  const toInput = el('input', {
    type: 'date', 'aria-label': 'End Date',
    onchange: e => { state.to = e.target.value; onChange(); }
  });

  return el('div', { class: 'money-filters' },
    catSel,
    el('div', { class: 'date-range' }, fromInput, el('span', {}, '→'), toInput));
}

/* ============================================================= expenses */

export async function expenses(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let kind = '';
  const filter = { category_id: '', from: '', to: '' };

  const cats = (await api.get('/expense-categories')).data;
  page._cats = cats;

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/expenses', {
      kind, per_page: 100,
      category_id: filter.category_id || undefined,
      from: filter.from || undefined,
      to: filter.to || undefined
    });

    const spend = (res.by_currency || []).filter(r => r.kind === 'expense');
    const earn  = (res.by_currency || []).filter(r => r.kind === 'income');

    fill(host, 
      el('div', { class: 'grid c2', style: 'margin-bottom:14px' },
        tile('Spent', byCurrency(spend, 'total'), 'in this view', 'out'),
        tile('Other income', byCurrency(earn, 'total'), 'not counting invoices', 'in')),
      card(null, table([
        { key: 'entry_date', label: 'Date', render: r => date(r.entry_date) },
        { key: 'title', label: 'Entry', render: r => el('div', {},
            el('strong', {}, r.title),
            el('span', { class: 'sub' }, join(r.category_name, r.paid_to))) },
        { key: 'kind', label: 'Type', render: r => tag(r.kind === 'income' ? 'income' : 'expense') },
        { key: 'method', label: 'How', render: r => el('div', {},
            r.method,
            el('span', { class: 'sub' }, r.account_label || '')) },
        { key: 'amount', label: 'Amount', num: true, render: r =>
            el('strong', { style: 'color:' + (r.kind === 'income' ? 'var(--in)' : 'var(--out)') },
              money(r.amount, r.currency)) },
        { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
            el('button', { class: 'btn sm', onclick: () => expenseProof(r) },
              Number(r.file_count) ? 'Proof (' + r.file_count + ')' : 'Proof'),
            el('button', { class: 'btn sm danger', onclick: async () => {
              if (!await confirmAction('Delete entry', '"' + r.title + '" will be removed.')) return;
              try { await api.del('/expenses/' + r.id); toast('Entry deleted'); load(); } catch (e) { fail(e); }
            } }, 'Delete')) }
      ], res.data, {
        empty: { title: 'No entries yet', hint: 'Record office costs here so the monthly numbers are real.' }
      }), null, true)
    );
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Overview'),
        el('h1', {}, 'Expenses'),
        el('p', {}, 'Office costs and any income that does not come from an invoice.')),
      el('div', { class: 'page-actions' },
        moneyFilters(cats, filter, load),
        el('button', { class: 'btn', onclick: () => categoryManager(load) }, 'Categories'),
        el('button', { class: 'btn primary',
          onclick: () => expenseForm(page._cats || [], load) }, 'Add entry'))),
    el('div', { class: 'filters', style: 'margin-bottom:14px' },
      el('select', { onchange: e => { kind = e.target.value; load(); } },
        el('option', { value: '' }, 'Everything'),
        el('option', { value: 'expense' }, 'Expenses only'),
        el('option', { value: 'income' }, 'Other income only'))),
    host
  );

  await load();
  return page;
}

async function expenseForm(cats, onDone) {
  const [accountOptions, methodOptions] = await Promise.all([accountChoices(), methodChoices()]);
  const fields = formFields([
    { name: 'title', label: 'What was it', required: true, span: 2 },
    { name: 'amount', label: 'Amount', type: 'number', required: true },
    { name: 'kind', label: 'Type', type: 'select', options: [
        { value: 'expense', label: 'Expense' }, { value: 'income', label: 'Other income' }] },
    { name: 'category_id', label: 'Category', type: 'select',
      options: [{ value: '', label: '—' }, ...cats.map(c => ({ value: c.id, label: c.name + ' (' + c.kind + ')' }))] },
    { name: 'entry_date', label: 'Date', type: 'date', value: today() },
    { name: 'account_id', label: 'Out of which account', type: 'select', options: accountOptions,
      hint: 'The balance of this account moves by this amount' },
    { name: 'paid_to', label: 'Paid to', span: 2 },
        { name: 'reference', label: 'Reference' },
    { name: 'note', label: 'Note', type: 'textarea', span: 2 }
  ]);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const res = await api.post('/expenses', readFields(fields));
      toast('Entry saved');
      m.close(); onDone();
      expenseProof(res.data);
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Save entry');

  const m = modal({ title: 'Add entry', body: fields, wide: true,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save] });
}

/* ============================================================== reports */

export async function reports(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  // The office may be hours ahead of this browser. Let the server pick the
  // window in its own timezone, otherwise an entry made "today" can fall
  // outside "this month" and the report quietly reads zero.
  let from = null;
  let to = null;

  const openPrint = async () => {
    try {
      const r = await api.get('/reports/print-link',
        from && to ? { from, to } : {});
      window.open(r.data.url, '_blank', 'noopener');
    } catch (e) { fail(e); }
  };

  const load = async () => {
    fill(host, loading('Adding it all up…'));
    const res = await api.get('/reports/full',
      from && to ? { from, to } : {});
    const d = res.data;

    // adopt whatever window the server used, so the pickers agree with it
    from = d.from;
    to = d.to;
    fromInput.value = from;
    toInput.value = to;
    const t = d.trading, w = d.work, p = d.position;
    const isProfit = t.result === 'profit';
    const isQuiet = t.result === 'quiet';

    fill(host,
      el('div', {
        class: 'card',
        style: 'padding:18px 20px;display:flex;justify-content:space-between;'
             + 'align-items:baseline;gap:18px;flex-wrap:wrap;background:'
             + (isQuiet ? 'var(--rule-soft)' : (isProfit ? 'var(--in-bg)' : 'var(--out-bg)'))
      },
        el('div', {},
          el('strong', {
            style: 'font-size:15px;color:'
                 + (isQuiet ? 'var(--muted)' : (isProfit ? 'var(--in)' : 'var(--out)'))
          }, isQuiet
              ? 'No money moved in this period'
              : (isProfit ? 'You are ahead for this period' : 'You are behind for this period')),
          el('div', { style: 'color:var(--muted);font-size:13px;margin-top:2px' },
            money(t.money_in) + ' came in · ' + money(t.money_out) + ' went out')),
        el('span', {
          class: 'num',
          style: 'font-size:26px;font-weight:650;color:'
               + (isQuiet ? 'var(--muted)' : (isProfit ? 'var(--in)' : 'var(--out)'))
        }, (isProfit && !isQuiet ? '+' : '') + money(t.net, d.currency))),

      el('div', { class: 'grid c4', style: 'margin-bottom:16px' },
        tile('Money in hand', money(p.in_hand), d.accounts.length + ' account(s)',
          Number(p.in_hand) >= 0 ? 'in' : 'out'),
        tile('Owed to me', money(p.receivable), d.who_owes_me.length + ' customer(s)', 'out'),
        tile('I owe suppliers', money(p.payable), d.i_owe.length + ' supplier(s)', 'hold'),
        tile('Net worth', money(p.net_worth), 'in hand + owed − payable')),

      el('div', { class: 'grid c2', style: 'align-items:start' },
        card('Money in and out',
          table([
            { key: 'label', label: '' },
            { key: 'amount', label: d.currency, num: true, render: r =>
                el('span', { style: r.tone ? 'color:var(--' + r.tone + ')' : '' }, money(r.amount)) }
          ], [
            { label: 'Received from customers', amount: t.from_customers, tone: 'in' },
            { label: 'Other income', amount: t.other_income, tone: 'in' },
            { label: 'Office expenses', amount: t.office_expense, tone: 'out' },
            { label: 'Paid to suppliers', amount: t.supplier_paid, tone: 'out' },
            { label: isQuiet ? 'Net' : (isProfit ? 'Profit' : 'Loss'), amount: t.net,
              tone: isQuiet ? '' : (isProfit ? 'in' : 'out') }
          ]), null, true),

        card('What the work earned',
          el('div', {},
            table([
              { key: 'label', label: '' },
              { key: 'amount', label: d.currency, num: true, render: r => money(r.amount) }
            ], [
              { label: 'Billed to customers', amount: w.billed },
              { label: 'What it cost me', amount: w.cost },
              { label: 'Gross profit', amount: w.gross_profit }
            ]),
            el('p', { style: 'margin:12px 16px;color:var(--muted);font-size:13px' },
              'Margin ' + w.margin + '% on the work invoiced in this period.')),
          null, true)),

      el('div', { class: 'grid c2', style: 'align-items:start' },
        card('Who owes me',
          table([
            { key: 'name', label: 'Customer', render: r => first(r.company_name, r.name) },
            { key: 'due', label: 'Due', num: true, render: r =>
                el('strong', { style: 'color:var(--out)' }, money(r.due)) },
            { key: 'days_over', label: 'Since', render: r => Number(r.days_over) > 0
                ? el('span', { class: 'tag out' }, r.days_over + 'd over')
                : date(r.oldest_due) }
          ], d.who_owes_me, { empty: { title: 'Nobody owes you', hint: 'Every invoice is settled.' } }),
          null, true),

        card('Who I owe',
          table([
            { key: 'name', label: 'Supplier', render: r => first(r.company_name, r.name) },
            { key: 'due', label: 'Due', num: true, render: r =>
                el('strong', { style: 'color:var(--out)' }, money(r.due)) }
          ], d.i_owe, { empty: { title: 'Nothing outstanding', hint: 'All supplier bills are paid.' } }),
          null, true)),

      card('Where the money sits',
        table([
          { key: 'name', label: 'Account' },
          { key: 'type', label: 'Kind' },
          { key: 'balance', label: 'Balance', num: true, render: r =>
              el('strong', { style: 'color:' + (Number(r.balance) >= 0 ? 'var(--in)' : 'var(--out)') },
                money(r.balance)) }
        ], d.accounts), null, true),

      d.expense_breakdown.length
        ? card('What the money went on',
            table([
              { key: 'category', label: 'Category' },
              { key: 'entries', label: 'Entries', num: true },
              { key: 'total', label: 'Amount', num: true, render: r => money(r.total) }
            ], d.expense_breakdown), null, true)
        : null,

      d.earned_by_service.length
        ? card('Which work earned it',
            table([
              { key: 'service', label: 'Service' },
              { key: 'billed', label: 'Billed', num: true, render: r => money(r.billed) },
              ...(ctx.can('costs.view') ? [
                { key: 'cost', label: 'Cost', num: true, render: r => money(r.cost) },
                { key: 'profit', label: 'Profit', num: true, render: r => {
                    const gain = Number(r.billed) - Number(r.cost);
                    return el('span', { style: 'color:' + (gain >= 0 ? 'var(--in)' : 'var(--out)') },
                      money(gain));
                  } }
              ] : [])
            ], d.earned_by_service), null, true)
        : null,

      d.coming_up.length
        ? card('Coming up — papers expiring within 60 days',
            table([
              { key: 'title', label: 'Document', render: r => el('div', {},
                  el('strong', {}, r.title),
                  el('span', { class: 'sub' }, first(r.company_name, r.customer_name))) },
              { key: 'expiry_date', label: 'Expires', render: r => date(r.expiry_date) },
              { key: 'days_left', label: '', render: r => el('span',
                  { class: 'tag ' + (Number(r.days_left) < 15 ? 'out' : 'hold') },
                  daysPhrase(r.days_left)) }
            ], d.coming_up), null, true)
        : null
    );
  };

  const fromInput = el('input', { type: 'date',
    onchange: e => { from = e.target.value; load(); } });
  const toInput = el('input', { type: 'date',
    onchange: e => { to = e.target.value; load(); } });

  const quick = (label, pick) => el('button', { class: 'btn sm', onclick: () => {
    const [f, t2] = pick(new Date(to || Date.now()));
    from = f; to = t2; fromInput.value = f; toInput.value = t2; load();
  } }, label);

  const iso = d => d.toISOString().slice(0, 10);
  const monthStart = d => iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1)));

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Overview'),
        el('h1', {}, 'Reports'),
        el('p', {}, 'Everything in one place — and printable, so you can file it or send it on.')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn primary', onclick: openPrint }, 'Print / Save as PDF'))),

    el('div', { class: 'filters', style: 'margin-bottom:16px' },
      el('span', { style: 'font-size:12px;color:var(--muted)' }, 'From'), fromInput,
      el('span', { style: 'font-size:12px;color:var(--muted)' }, 'to'), toInput,
      quick('This month', d => [monthStart(d), iso(d)]),
      quick('Last month', d => [
        iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() - 1, 1))),
        iso(new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 0)))
      ]),
      quick('This year', d => [
        iso(new Date(Date.UTC(d.getUTCFullYear(), 0, 1))), iso(d)
      ])),

    host
  );

  await load();
  return page;
}


/* ============================================================= messages */

export async function messages(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});

  const load = async () => {
    fill(host, loading());
    const [log, queue] = await Promise.all([
      api.get('/messages/log', { per_page: 40 }),
      api.get('/messages/queue', { status: 'queued' })
    ]);

    fill(host, 
      queue.data.length
        ? card('Waiting to send',
            table([
              { key: 'channel', label: 'Channel', render: r => tag(r.channel) },
              { key: 'recipient', label: 'To', render: r => el('span', { class: 'doc-no' }, r.recipient) },
              { key: 'body', label: 'Message', render: r => String(r.body ?? '').slice(0, 90) + '…' },
              { key: 'scheduled_at', label: 'Scheduled', render: r => date(r.scheduled_at) },
              { key: 'act', label: '', render: r => el('button', { class: 'btn sm', onclick: async () => {
                  try { await api.del('/messages/queue/' + r.id); toast('Cancelled'); load(); } catch (e) { fail(e); }
                } }, 'Cancel') }
            ], queue.data),
            el('button', { class: 'btn sm', onclick: async () => {
              try { const r = await api.post('/messages/queue/flush');
                toast('Sent ' + r.data.sent + ', failed ' + r.data.failed, r.data.failed ? 'err' : 'ok'); load();
              } catch (e) { fail(e); }
            } }, 'Send now'), true)
        : null,

      card('Recently sent',
        table([
          { key: 'created_at', label: 'When', render: r => date(r.created_at) },
          { key: 'channel', label: 'Channel', render: r => tag(r.channel) },
          { key: 'recipient', label: 'To', render: r => el('span', { class: 'doc-no' }, r.recipient || '—') },
          { key: 'body', label: 'Message', render: r => String(r.body ?? '').slice(0, 80) + '…' },
          { key: 'status', label: '', render: r => r.status === 'failed'
              ? el('span', { class: 'tag out', title: r.error }, 'failed') : tag(r.status) }
        ], log.data, { empty: { title: 'Nothing sent yet', hint: 'Reminders and messages will be listed here.' } }),
        null, true)
    );
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Outbox'), el('h1', {}, 'Messages')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn', onclick: () => customMessageForm(load) }, 'Custom message'),
        el('button', { class: 'btn primary', onclick: () => serviceListForm() }, 'Send service list'))),
    host
  );

  await load();
  return page;
}

/** #17 — pick services, get copy-ready text, or send it straight away. */
async function serviceListForm() {
  const [svcRes, custRes] = await Promise.all([
    api.get('/services', { per_page: 200 }),
    api.get('/customers', { per_page: 200 })
  ]);
  const services = svcRes.data.filter(s => Number(s.is_active));

  const picks = el('div', { style: 'max-height:230px;overflow:auto;border:1px solid var(--rule);border-radius:6px;padding:10px' },
    ...services.map(s => el('label', { style: 'display:flex;gap:8px;align-items:center;padding:3px 0;font-size:13px' },
      el('input', { type: 'checkbox', value: s.id, style: 'width:auto',
        checked: Number(s.show_in_list) ? true : undefined }),
      s.name)));

  const custSel = el('select', {},
    el('option', { value: '' }, 'No customer (generic text)'),
    ...custRes.data.map(c => el('option', { value: c.id }, c.company_name || c.name)));

  const withPrice = el('input', { type: 'checkbox', style: 'width:auto', checked: true });
  const preview = el('textarea', { readonly: true, style: 'min-height:150px;font-size:13px' });

  const build = async () => {
    const ids = [...picks.querySelectorAll('input:checked')].map(i => Number(i.value));
    if (!ids.length) { preview.value = 'Pick at least one service.'; return; }
    try {
      const r = await api.post('/messages/service-list', {
        service_ids: ids,
        customer_id: custSel.value || undefined,
        with_price: withPrice.checked
      });
      preview.value = r.data.text;
    } catch (e) { fail(e); }
  };

  picks.addEventListener('change', build);
  custSel.addEventListener('change', build);
  withPrice.addEventListener('change', build);

  const send = el('button', { class: 'btn primary', onclick: async () => {
    if (!custSel.value) { toast('Choose a customer to send it to', 'err'); return; }
    send.disabled = true;
    try {
      const ids = [...picks.querySelectorAll('input:checked')].map(i => Number(i.value));
      const r = await api.post('/messages/service-list', {
        service_ids: ids, customer_id: Number(custSel.value),
        with_price: withPrice.checked, send: true, channel: 'whatsapp'
      });
      if (r.data.sent && r.data.sent.success) { toast('Sent on WhatsApp'); m.close(); }
      else toast((r.data.sent && r.data.sent.error) || 'Could not send', 'err');
    } catch (e) { fail(e); }
    send.disabled = false;
  } }, 'Send on WhatsApp');

  const m = modal({
    title: 'Send your service list', wide: true,
    body: el('div', {},
      el('div', { class: 'grid c2' },
        el('label', { class: 'field' }, el('span', {}, 'Send to'), custSel),
        el('label', { class: 'field', style: 'display:flex;align-items:center;gap:8px;margin-top:22px' },
          withPrice, el('span', { style: 'margin:0' }, 'Include prices'))),
      el('label', { class: 'field' }, el('span', {}, 'Services'), picks),
      el('label', { class: 'field' }, el('span', {}, 'Message'), preview)),
    footer: [
      el('button', { class: 'btn', onclick: () => m.close() }, 'Close'),
      copyButton(() => preview.value, 'Copy text'),
      send
    ]
  });

  build();
}

async function customMessageForm(onDone) {
  const [custRes, supRes] = await Promise.all([
    api.get('/customers', { per_page: 200 }),
    api.get('/suppliers', { per_page: 200 })
  ]);

  const typeSel = el('select', {},
    el('option', { value: 'customer' }, 'Customers'),
    el('option', { value: 'supplier' }, 'Suppliers'));

  const list = el('select', { multiple: true, size: 8, style: 'height:auto' });
  const fillList = () => {
    const rows = typeSel.value === 'supplier' ? supRes.data : custRes.data;
    fill(list, ...rows.map(r => el('option', { value: r.id }, first(r.company_name, r.name))));
  };
  typeSel.addEventListener('change', fillList);
  fillList();

  const body = el('textarea', { placeholder: 'Write the message. {{customer_name}} inserts their name.' });
  const channel = el('select', {},
    el('option', { value: 'whatsapp' }, 'WhatsApp'),
    el('option', { value: 'email' }, 'Email'),
    el('option', { value: 'telegram' }, 'Telegram'));

  const send = el('button', { class: 'btn primary', onclick: async () => {
    const ids = [...list.selectedOptions].map(o => Number(o.value));
    if (!ids.length) { toast('Pick at least one recipient', 'err'); return; }
    if (!body.value.trim()) { toast('Write a message first', 'err'); return; }
    send.disabled = true;
    try {
      const r = await api.post('/messages/custom', {
        party_type: typeSel.value, party_ids: ids,
        channel: channel.value, body: body.value
      });
      const okCount = r.data.results.filter(x => x.success).length;
      const bad = r.data.results.filter(x => !x.success);
      toast('Sent to ' + okCount + ' of ' + r.data.results.length, bad.length ? 'err' : 'ok');
      if (bad.length) toast(bad[0].name + ': ' + bad[0].error, 'err');
      m.close(); onDone && onDone();
    } catch (e) { fail(e); send.disabled = false; }
  } }, 'Send message');

  const m = modal({
    title: 'Custom message', wide: true,
    body: el('div', {},
      el('div', { class: 'grid c2' },
        el('label', { class: 'field' }, el('span', {}, 'Send to'), typeSel),
        el('label', { class: 'field' }, el('span', {}, 'Channel'), channel)),
      el('label', { class: 'field' }, el('span', {}, 'Recipients (hold Ctrl to pick several)'), list),
      el('label', { class: 'field' }, el('span', {}, 'Message'), body)),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), send]
  });
}

/* ============================================================= settings */

export async function settings(ctx) {
  const page = el('div', { class: 'page' });
  const sRes = await api.get('/settings');
  const s = sRes.data;

  const group = (title, fields, note) => {
    const box = formFields(fields, s);
    const save = el('button', { class: 'btn primary', onclick: async () => {
      save.disabled = true;
      try { await api.patch('/settings', readFields(box)); toast('Settings saved'); }
      catch (e) { fail(e); }
      save.disabled = false;
    } }, 'Save');
    return card(title, el('div', {}, note ? el('p', { style: 'color:var(--muted);margin-top:0' }, note) : null,
      box, el('div', { style: 'display:flex;justify-content:flex-end' }, save)));
  };

  const testBtn = ch => el('button', { class: 'btn sm', onclick: async () => {
    try {
      const r = await api.post('/settings/test-channel', { channel: ch });
      toast(r.data.success ? 'Test message sent on ' + ch : r.data.error, r.data.success ? 'ok' : 'err');
    } catch (e) { fail(e); }
  } }, 'Send test on ' + ch);

  page.append(
    el('div', { class: 'page-head' },
      el('div', {}, el('span', { class: 'eyebrow' }, 'Configuration'), el('h1', {}, 'Settings'))),

    group('Name and logo', [
      { name: 'app_name', label: 'What this system is called', span: 2,
        hint: 'Shown on the sign-in screen, the sidebar and the browser tab' },
      { name: 'company_name', label: 'Company name', span: 2,
        hint: 'Used on invoices, messages and the website' }
    ], 'Change the name and it changes everywhere at once.'),

    brandImageCard('app_logo', 'App logo',
      'Shown on the sign-in screen and beside the name in the sidebar. '
      + 'A square or wide PNG with a transparent background works best.'),

    brandImageCard('app_favicon', 'Browser icon',
      'The little picture on the browser tab. A square PNG, 64×64 or larger.'),

    group('Money', [
      { name: 'base_currency', label: 'Currency', type: 'select', span: 2,
        options: [
          { value: 'BDT', label: 'BDT — Bangladeshi Taka' },
          { value: 'AED', label: 'AED — UAE Dirham' },
          { value: 'USD', label: 'USD — US Dollar' },
          { value: 'SAR', label: 'SAR — Saudi Riyal' },
          { value: 'INR', label: 'INR — Indian Rupee' },
          { value: 'GBP', label: 'GBP — Pound Sterling' },
          { value: 'EUR', label: 'EUR — Euro' }
        ],
        hint: 'Every price, invoice, account and report uses this one currency' },
      { name: 'invoice_prefix', label: 'Invoice number starts with' },
      { name: 'invoice_due_days', label: 'Payment due after (days)', type: 'number' },
      { name: 'due_reminder_days', label: 'Chase unpaid invoices after (days)',
        hint: 'comma separated, e.g. 3,7,15' }
    ], 'Set the currency once. Nothing else in the system asks for it again.'),

    group('Business details', [
      { name: 'company_name', label: 'Business name', span: 2 },
      { name: 'company_owner', label: 'Owner name' },
      { name: 'company_phone', label: 'Phone' },
      { name: 'company_email', label: 'Email' },
      { name: 'company_website', label: 'Website' },
      { name: 'company_trade_license', label: 'Trade licence number' },
      { name: 'company_tax_number', label: 'TRN / BIN' },
      { name: 'company_address', label: 'Address', type: 'textarea', span: 2 },
      { name: 'company_bank_details', label: 'Bank details', type: 'textarea', span: 2,
        hint: 'Shown on invoices if you switch it on in the design' }
    ], 'These appear on every invoice and quotation.'),

    imageCard(),

    group('Numbering and terms', [
      { name: 'invoice_prefix', label: 'Invoice prefix', hint: 'AH5- gives AH5-300826 01' },
      { name: 'quotation_prefix', label: 'Quotation prefix' },
      { name: 'receipt_prefix', label: 'Receipt prefix' },
      { name: 'invoice_due_days', label: 'Payment due after (days)', type: 'number', attrs: { step: '1' } },
      { name: 'due_reminder_days', label: 'Chase unpaid invoices after', hint: 'days overdue, e.g. 3,7,15,30' },
      { name: 'base_currency', label: 'Reporting currency' }
    ], 'Invoice numbers restart at 01 every day.'),

    group('WhatsApp, Telegram and reminders', [
      { name: 'wa_phone_number_id', label: 'WhatsApp phone number ID' },
      { name: 'wa_access_token', label: 'WhatsApp access token', hint: 'Leave the dots to keep the saved one' },
      { name: 'wa_api_version', label: 'Graph API version' },
      { name: 'telegram_bot_token', label: 'Telegram bot token' },
      { name: 'self_whatsapp', label: 'My WhatsApp number', hint: 'Daily digests come here' },
      { name: 'self_email', label: 'My email address' },
      { name: 'self_telegram_chat_id', label: 'My Telegram chat ID' }
    ], 'WhatsApp needs an approved template to reach someone outside a 24-hour window. Messenger only allows replies inside 24 hours, so reminders do not go there.'),

    await designCard(),

    group('Email', [
      { name: 'email_enabled', label: 'Send email', type: 'select',
        options: [{ value: '0', label: 'Off' }, { value: '1', label: 'On' }] },
      { name: 'email_mode', label: 'Send it through', type: 'select',
        options: [
          { value: 'gmail', label: 'My Gmail account' },
          { value: 'smtp', label: 'My own mail server (SMTP)' },
          { value: 'server', label: "The hosting server's own mail" }
        ],
        hint: 'Gmail is the quickest to set up; SMTP looks more official from your own domain' },
      { name: 'smtp_from_name', label: 'Send from name', span: 2 }
    ], 'Pick how mail goes out, then fill in that section below.'),

    group('Gmail', [
      { name: 'gmail_address', label: 'Gmail address', span: 2, hint: 'you@gmail.com' },
      { name: 'gmail_app_password', label: 'App password', span: 2, attrs: { type: 'password' },
        hint: 'Not your normal password. Google Account → Security → 2-Step Verification → '
            + 'App passwords → Mail. Paste the 16 characters here.' }
    ], 'Google stopped allowing plain passwords, so an App Password is the way in. '
     + 'Gmail sends about 500 mails a day, which is plenty for invoices and reminders.'),

    group('Your own mail server (SMTP)', [
      { name: 'smtp_from_email', label: 'Send from address', hint: 'office@yourdomain.com' },
      { name: 'smtp_host', label: 'SMTP host', hint: 'mail.yourdomain.com' },
      { name: 'smtp_port', label: 'Port', type: 'number', hint: '587 for TLS, 465 for SSL' },
      { name: 'smtp_secure', label: 'Security', type: 'select',
        options: [{ value: 'tls', label: 'TLS' }, { value: 'ssl', label: 'SSL' }, { value: 'none', label: 'None' }] },
      { name: 'smtp_user', label: 'Username' },
      { name: 'smtp_pass', label: 'Password', attrs: { type: 'password' } }
    ], 'cPanel gives you these under Email Accounts → Connect Devices.'),

    emailTestCard(),

    card('Test a channel', el('div', { style: 'display:flex;gap:8px' },
      testBtn('whatsapp'), testBtn('telegram'))),




    card('Message wording', await templatesPanel(),
      null, true),

    card('Recent activity', await activityPanel(), null, true),
    await cronCard(),

    cacheCard(),

    await methodsCard(),

    await storageCard(),

    await backupsCard(),

    await updateCard()
  );

  return page;
}

/** Edit the words that go out on WhatsApp and Telegram. */
async function templatesPanel() {
  const host = el('div', {});

  const load = async () => {
    const res = await api.get('/message-templates');
    fill(host, table([
      { key: 'name', label: 'Message', render: r => el('div', {},
          el('strong', {}, r.name),
          el('span', { class: 'sub' }, 'placeholders: ' + (r.variables || '—'))) },
      { key: 'body', label: 'Text', render: r => String(r.body).slice(0, 70) + '…' },
      { key: 'wa_template_name', label: 'WhatsApp template', render: r => r.wa_template_name
          ? el('span', { class: 'doc-no' }, r.wa_template_name)
          : el('span', { class: 'tag hold', title: 'Needed to reach someone outside a 24-hour window' }, 'not set') },
      { key: 'act', label: '', render: r => el('button', { class: 'btn sm',
          onclick: () => editTemplate(r, load) }, 'Edit') }
    ], res.data));
  };

  await load();
  return host;
}

function editTemplate(tpl, onDone) {
  const fields = formFields([
    { name: 'body', label: 'Message text', type: 'textarea', span: 2,
      hint: 'Placeholders you can use: ' + (tpl.variables || 'none') },
    { name: 'wa_template_name', label: 'Approved WhatsApp template name' },
    { name: 'wa_language', label: 'Template language', hint: 'e.g. en, bn' },
    { name: 'is_active', label: 'In use', type: 'checkbox' }
  ], tpl);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try { await api.patch('/message-templates/' + tpl.id, readFields(fields));
      toast('Wording saved'); m.close(); onDone(); }
    catch (e) { fail(e); save.disabled = false; }
  } }, 'Save wording');

  const m = modal({ title: tpl.name, body: fields, wide: true,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save] });
}

async function activityPanel() {
  const res = await api.get('/activity-log', { per_page: 25 });
  return table([
    { key: 'created_at', label: 'When', render: r => date(r.created_at) },
    { key: 'entity', label: 'Where', render: r => r.entity + (r.entity_id ? ' #' + r.entity_id : '') },
    { key: 'action', label: 'What', render: r => tag(r.action) },
    { key: 'note', label: 'Detail', render: r => r.note || '—' },
    { key: 'user_name', label: 'By', render: r => r.user_name || '—' }
  ], res.data, { empty: { title: 'Nothing logged yet', hint: 'Changes you make will be listed here.' } });
}

/* ================================================================ staff */

export async function staff(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});

  const load = async () => {
    fill(host, loading());
    const [users, cat] = await Promise.all([
      api.get('/users'),
      api.get('/users/permissions')
    ]);
    page._catalogue = cat.data;

    fill(host, card(null,
      table([
        { key: 'name', label: 'Person', render: r => el('div', {},
            el('strong', {}, r.name),
            el('span', { class: 'sub' }, r.email)) },
        { key: 'role', label: 'Role', render: r => el('span',
            { class: 'tag ' + (r.role === 'admin' ? 'in' : '') },
            r.role === 'admin' ? 'owner' : 'staff') },
        { key: 'permissions', label: 'Can do', render: r => r.role === 'admin'
            ? el('span', { style: 'color:var(--muted)' }, 'everything')
            : (r.permissions.length + ' of ' + countAll(cat.data) + ' things') },
        { key: 'device_count', label: 'Signed in', num: true, render: r =>
            r.device_count ? r.device_count + ' device(s)' : '—' },
        { key: 'last_login_at', label: 'Last seen', render: r =>
            r.last_login_at ? date(r.last_login_at) : 'never' },
        { key: 'is_active', label: 'Status', render: r =>
            Number(r.is_active) ? tag('active') : tag('off') },
        { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
            el('button', { class: 'btn sm', onclick: () => staffForm(r, cat.data, load) },
              r.role === 'admin' ? 'Edit' : 'Permissions'),
            r.role !== 'admin' ? el('button', { class: 'btn sm', onclick: async () => {
              const pw = window.prompt('New password for ' + r.name + ' (at least 8 characters)');
              if (!pw) return;
              try {
                const res = await api.post('/users/' + r.id + '/reset-password', { new_password: pw });
                toast('Password changed. ' + res.data.sessions_ended + ' session(s) ended.');
              } catch (e) { fail(e); }
            } }, 'Reset password') : null,
            r.role !== 'admin' && Number(r.is_active) ? el('button', { class: 'btn sm danger',
              onclick: async () => {
                if (!await confirmAction('Switch off ' + r.name,
                  'They can no longer sign in. Everything they entered stays in the records.',
                  'Switch off')) return;
                try { await api.del('/users/' + r.id); toast('Account switched off'); load(); }
                catch (e) { fail(e); }
              } }, 'Switch off') : null) }
      ], users.data, {
        empty: { title: 'Only you so far', hint: 'Add a staff account when someone joins.' }
      }), null, true));
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Access'),
        el('h1', {}, 'Staff'),
        el('p', {}, 'Tick exactly what each person may see and do. Your cost prices stay hidden unless you tick them.')),
      el('div', { class: 'page-actions' },
        el('button', { class: 'btn primary',
          onclick: () => staffForm(null, page._catalogue, load) }, 'Add staff'))),
    host
  );

  await load();
  return page;
}

const countAll = cat => cat.groups.reduce((n, g) => n + g.items.length, 0);

function staffForm(existing, catalogue, onDone) {
  const isOwner = existing && existing.role === 'admin';

  const fields = formFields([
    { name: 'name', label: 'Name', required: true },
    { name: 'email', label: 'Email', type: 'email', required: true },
    { name: 'phone', label: 'Phone' },
    existing ? null : { name: 'password', label: 'Password', type: 'password', required: true,
      hint: 'at least 8 characters' }
  ].filter(Boolean), existing || {});

  const granted = new Set(existing ? existing.permissions : catalogue.staff_defaults);

  const PRIVATE = ['costs.view', 'settings.manage', 'users.manage'];

  const tickBox = it => {
    const box = el('input', {
      type: 'checkbox', value: it.key, style: 'width:auto;margin-top:3px',
      checked: granted.has(it.key) || undefined
    });
    const caption = el('span', {}, it.label);
    if (PRIVATE.includes(it.key)) {
      caption.append(el('span', { class: 'tag out', style: 'margin-left:8px' }, 'private'));
    }
    return el('label', {
      style: 'display:flex;gap:8px;align-items:flex-start;padding:3px 0;font-size:13px'
    }, box, caption);
  };

  const groupBlock = g => el('div', { style: 'margin-bottom:14px' },
    el('div', { class: 'eyebrow', style: 'margin-bottom:6px' }, g.group),
    ...g.items.map(tickBox)
  );

  const groups = el('div', {
    style: 'max-height:340px;overflow:auto;border:1px solid var(--rule);border-radius:6px;padding:12px'
  }, ...catalogue.groups.map(groupBlock));

  const quick = el('div', { style: 'display:flex;gap:8px;margin-bottom:10px' },
    el('button', { class: 'btn sm', onclick: () => setAll(true) }, 'Tick all'),
    el('button', { class: 'btn sm', onclick: () => setAll(false) }, 'Untick all'),
    el('button', { class: 'btn sm', onclick: () => {
      setAll(false);
      catalogue.staff_defaults.forEach(k => {
        const box = groups.querySelector('input[value="' + k + '"]');
        if (box) box.checked = true;
      });
    } }, 'Usual staff set'));

  const setAll = on => groups.querySelectorAll('input[type=checkbox]').forEach(b => { b.checked = on; });

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      body.permissions = [...groups.querySelectorAll('input:checked')].map(i => i.value);
      if (existing) {
        await api.patch('/users/' + existing.id, body);
        toast('Permissions saved');
      } else {
        body.role = 'staff';
        await api.post('/users', body);
        toast('Staff account created');
      }
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Create account');

  const m = modal({
    title: existing ? existing.name : 'Add staff', wide: true,
    body: el('div', {}, fields,
      isOwner
        ? el('p', { style: 'color:var(--muted)' },
            'This is an owner account. Owners always have every permission.')
        : el('div', {},
            el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:18px 0 8px' },
              'What they may do'),
            quick, groups)),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

/**
 * The one cron job you must add in cPanel, ready to copy, plus what it
 * has actually been doing.
 */
async function cronCard() {
  const res = await api.get('/settings/cron');
  const d = res.data;

  const commandBox = el('pre', {
    style: 'background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:6px;overflow:auto;'
         + 'font-size:12.5px;margin:0 0 10px'
  }, d.command);

  const steps = el('ol', { style: 'margin:0 0 14px;padding-left:20px;color:var(--muted);font-size:13px' },
    ...d.cpanel_steps.map(t => el('li', { style: 'margin-bottom:4px' }, t)));

  const health = d.ever_ran
    ? el('div', { class: 'tag in' }, 'last ran ' + date(d.last_run_at))
    : el('div', { class: 'tag out' }, 'never run yet — reminders will not go out until you add this');

  const fallback = d.url_fallback ? el('details', { style: 'margin-top:14px' },
    el('summary', { style: 'cursor:pointer;font-size:13px;color:var(--muted)' },
      'No command-line cron on your host?'),
    el('p', { style: 'font-size:13px;color:var(--muted);margin:8px 0' }, d.url_note),
    el('pre', {
      style: 'background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;overflow:auto;font-size:12px'
    }, d.url_fallback),
    copyButton(() => d.url_fallback, 'Copy URL')
  ) : null;

  return card('Cron job — add this in cPanel',
    el('div', {},
      el('div', { style: 'display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap' },
        health,
        el('span', { style: 'color:var(--muted);font-size:13px' },
          'Schedule: ' + d.schedule + '  (' + d.schedule_note + ')')),
      steps,
      commandBox,
      el('div', { style: 'display:flex;gap:8px;margin-bottom:18px' },
        copyButton(() => d.command, 'Copy command'),
        copyButton(() => d.schedule, 'Copy schedule')),
      fallback,
      el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:20px 0 8px' },
        'What it runs'),
      table([
        { key: 'title', label: 'Task', render: r => el('div', {},
            el('strong', {}, r.title),
            el('span', { class: 'sub' }, r.run_at_time
              ? 'daily at ' + String(r.run_at_time).slice(0, 5)
              : 'every ' + r.interval_min + ' minutes')) },
        { key: 'last_run_at', label: 'Last run', render: r => r.last_run_at ? date(r.last_run_at) : 'never' },
        { key: 'last_status', label: 'Result', render: r => r.last_status
            ? el('span', { class: 'tag ' + (r.last_status === 'error' ? 'out' : 'in'), title: r.last_message },
                r.last_status)
            : '—' },
        { key: 'last_message', label: 'Detail', render: r => r.last_message || '—' }
      ], d.tasks)
    ));
}

/** Upload the logo and the signature that go on invoices. */
function imageCard() {
  const host = el('div', { class: 'grid c2' });

  const slot = (kind, title, note) => {
    const box = el('div', {
      style: 'border:1px solid var(--rule);border-radius:6px;padding:14px;text-align:center'
    }, loading('…'));

    const draw = async () => {
      fill(box, loading('…'));
      let url = null;
      try {
        const r = await api.get('/settings/asset/company_' + kind);
        url = r.data.url;
      } catch { /* nothing set yet */ }

      const file = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp',
        style: 'display:none' });

      const upload = async () => {
        const f = file.files && file.files[0];
        if (!f) return;
        try {
          const fd = new FormData();
          fd.append('kind', kind);
          fd.append('file', f);
          const res = await fetch(api.base + '/settings/image', {
            method: 'POST',
            headers: { Authorization: 'Bearer ' + api.store.access },
            body: fd
          });
          const data = await res.json();
          if (!res.ok || !data.success) throw new Error(data.message || 'Upload failed');
          toast(title + ' updated');
          draw();
        } catch (e) { toast(e.message, 'err'); }
      };
      file.addEventListener('change', upload);

      fill(box, 
        el('div', { class: 'eyebrow', style: 'margin-bottom:8px' }, title),
        url
          ? el('img', { src: url, alt: '',
              style: 'max-height:80px;max-width:100%;margin-bottom:10px;display:block;margin-inline:auto' })
          : el('p', { style: 'color:var(--faint);font-size:12.5px;margin:0 0 10px' }, note),
        el('div', { style: 'display:flex;gap:8px;justify-content:center' },
          el('button', { class: 'btn sm', onclick: () => file.click() }, url ? 'Replace' : 'Upload'),
          url ? el('button', { class: 'btn sm danger', onclick: async () => {
            if (!await confirmAction('Remove ' + title.toLowerCase(), 'It will no longer appear on invoices.')) return;
            try { await api.del('/settings/image/' + kind); toast(title + ' removed'); draw(); }
            catch (e) { fail(e); }
          } }, 'Remove') : null),
        file
      );
    };

    draw();
    return box;
  };

  host.append(
    slot('logo', 'Company logo', 'PNG or JPG, roughly 400×160 works well'),
    slot('signature', 'Signature', 'A scan of your signature, background removed if possible')
  );

  return card('Logo and signature', host);
}

/** Choose how invoices look, or write the HTML yourself. */
async function designCard() {
  const res = await api.get('/settings/invoice-design');
  const d = res.data;
  const cur = d.current;

  const preview = async () => {
    try {
      const r = await api.get('/settings/design-preview');
      window.open(r.data.url, '_blank', 'noopener');
    } catch (e) { fail(e); }
  };

  const fields = formFields([
    { name: 'template', label: 'Design', type: 'select', span: 2,
      options: d.templates.map(t => ({ value: t.key, label: t.label })) },
    { name: 'accent', label: 'Accent colour', attrs: { type: 'color', style: 'height:38px;padding:3px' } },
    { name: 'paper', label: 'Paper size', type: 'select',
      options: [{ value: 'A4', label: 'A4' }, { value: 'Letter', label: 'Letter' }] },
    { name: 'footer_note', label: 'Footer line', span: 2,
      hint: 'A thank-you or a payment note, printed under the totals' },
    { name: 'show_logo', label: 'Show the logo', type: 'checkbox' },
    { name: 'show_signature', label: 'Show the signature', type: 'checkbox' },
    { name: 'show_unit', label: 'Show units beside quantities', type: 'checkbox' },
    { name: 'show_bank', label: 'Print bank details', type: 'checkbox' },
    { name: 'show_credit', label: 'Print the "Designed & Developed by" line', type: 'checkbox',
      hint: 'Turn it off for invoices you send to your own clients' }
  ], cur);

  const customHtml = el('textarea', {
    style: 'min-height:220px;font-family:var(--mono);font-size:12.5px',
    placeholder: 'Leave empty to keep using the design above'
  }, cur.custom_html || '');

  const useCustom = el('input', { type: 'checkbox', style: 'width:auto',
    checked: cur.use_custom || undefined });

  const tags = el('div', {
    style: 'display:flex;flex-wrap:wrap;gap:5px;max-height:130px;overflow:auto;margin-bottom:8px'
  }, ...d.placeholders.map(p => el('button', {
    class: 'btn sm', title: p.meaning, style: 'font-family:var(--mono);font-size:11.5px',
    onclick: () => {
      const at = customHtml.selectionStart ?? customHtml.value.length;
      customHtml.value = customHtml.value.slice(0, at) + p.tag + customHtml.value.slice(at);
      customHtml.focus();
    }
  }, p.tag)));

  const advanced = el('details', { style: 'margin-top:6px' },
    el('summary', { style: 'cursor:pointer;font-size:13px;color:var(--muted)' },
      'Write the HTML myself'),
    el('p', { style: 'color:var(--muted);font-size:13px;margin:10px 0' },
      'Your own HTML replaces the design above completely. Click a tag to drop it in — '
      + 'the system fills it when the invoice prints. Anything a customer typed is escaped, '
      + 'so a stray angle bracket in a name cannot break the page.'),
    tags,
    customHtml,
    el('div', { style: 'display:flex;gap:8px;margin-top:8px;align-items:center' },
      el('label', { style: 'display:flex;align-items:center;gap:7px;font-size:13px' },
        useCustom, 'Use my HTML instead of the design above'),
      el('button', { class: 'btn sm', onclick: () => { customHtml.value = d.starter_html; } },
        'Load a starting point'))
  );

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      body.use_custom = useCustom.checked ? 1 : 0;
      body.custom_html = customHtml.value;
      await api.patch('/settings/invoice-design', body);
      toast('Invoice design saved');
    } catch (e) { fail(e); }
    save.disabled = false;
  } }, 'Save design');

  return card('Invoice design',
    el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'Pick a look, set your colour, then preview it on a sample invoice before you send a real one.'),
      fields,
      advanced,
      el('div', { style: 'display:flex;justify-content:flex-end;gap:8px;margin-top:14px' },
        el('button', { class: 'btn', onclick: preview }, 'Preview'),
        save)));
}

/** Add, rename or retire the categories your spending is filed under. */
function categoryManager(onDone) {
  const host = el('div', {});

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/expense-categories', { all: 1 });
    fill(host, table([
      { key: 'name', label: 'Category', render: r => el('div', {},
          el('strong', {}, r.name),
          el('span', { class: 'sub' }, r.kind === 'income' ? 'money coming in' : 'money going out')) },
      { key: 'entry_count', label: 'Entries', num: true },
      { key: 'total_amount', label: 'Total so far', num: true, render: r => money(r.total_amount) },
      { key: 'is_active', label: 'Status', render: r => Number(r.is_active) ? tag('active') : tag('off') },
      { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
          el('button', { class: 'btn sm', onclick: async () => {
            const name = window.prompt('New name for this category', r.name);
            if (!name) return;
            try { await api.patch('/expense-categories/' + r.id, { name }); toast('Renamed'); load(); }
            catch (e) { fail(e); }
          } }, 'Rename'),
          Number(r.is_active)
            ? el('button', { class: 'btn sm danger', onclick: async () => {
                if (!await confirmAction('Remove ' + r.name,
                  Number(r.entry_count)
                    ? 'It is used by ' + r.entry_count + ' entr(ies), so it will be switched off.'
                    : 'This category will be removed.')) return;
                try { const rr = await api.del('/expense-categories/' + r.id); toast(rr.message); load(); }
                catch (e) { fail(e); }
              } }, 'Remove')
            : el('button', { class: 'btn sm', onclick: async () => {
                try { await api.patch('/expense-categories/' + r.id, { is_active: 1 });
                  toast('Back in use'); load(); } catch (e) { fail(e); }
              } }, 'Restore')) }
    ], res.data, { empty: { title: 'No categories', hint: 'Add one to file your spending under.' } }));
  };

  const nameInput = el('input', { placeholder: 'Office rent, Fuel, Bank charge…' });
  const kindSel = el('select', {},
    el('option', { value: 'expense' }, 'Money going out'),
    el('option', { value: 'income' }, 'Money coming in'));

  const add = el('button', { class: 'btn primary', onclick: async () => {
    if (!nameInput.value.trim()) { toast('Give the category a name', 'err'); return; }
    try {
      await api.post('/expense-categories', { name: nameInput.value.trim(), kind: kindSel.value });
      toast('Category added');
      nameInput.value = '';
      load();
    } catch (e) { fail(e); }
  } }, 'Add');

  const m = modal({
    title: 'Expense categories', wide: true,
    body: el('div', {},
      el('div', { class: 'grid c3', style: 'align-items:end;margin-bottom:16px' },
        el('label', { class: 'field' }, el('span', {}, 'New category'), nameInput),
        el('label', { class: 'field' }, el('span', {}, 'Kind'), kindSel),
        el('div', { style: 'margin-bottom:14px' }, add)),
      host),
    footer: [el('button', { class: 'btn', onclick: () => { m.close(); onDone(); } }, 'Done')]
  });

  load();
}

/** The receipt or bill behind one expense entry. */
function expenseProof(entry) {
  const m = modal({
    title: 'Proof for ' + entry.title,
    body: el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'The shop receipt, the utility bill, the bank slip — whatever shows this money went out.'),
      fileManager(api, 'expense', entry.id)),
    footer: [el('button', { class: 'btn primary', onclick: () => m.close() }, 'Done')]
  });
}

/* ============================================================== accounts */

/**
 * Where the money is. Each account is a real place money sits: the cash
 * box, a bank account, a bKash wallet. Every payment and expense lands in
 * one, so these balances are the answer to "how much do I actually have".
 */
export async function accounts(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  const canManage = ctx.can('settings.manage');
  const canMove = ctx.can('expenses.entry');

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/accounts', { all: 1 });
    const d = res.data;
    const live = d.accounts.filter(a => Number(a.is_active));

    const total = Object.values(d.totals || {}).reduce((s, v) => s + Number(v), 0);
    const kind = { cash: 'Cash', bank: 'Bank', mobile: 'Mobile wallet', card: 'Card', other: 'Other' };

    fill(host, 
      el('div', { class: 'grid c3', style: 'margin-bottom:16px' },
        tile('Money in hand', money(total), live.length + ' account(s)', total >= 0 ? 'in' : 'out'),
        ...Object.entries(d.by_type || {}).slice(0, 2).map(([t, v]) =>
          tile(kind[t] || t, money(v), 'across your ' + (kind[t] || t).toLowerCase() + ' accounts'))
      ),

      card(null, table([
        { key: 'name', label: 'Account', render: r => el('div', {},
            el('strong', {}, r.name),
            el('span', { class: 'sub' }, join(kind[r.type] || r.type, r.bank_name, r.account_number))) },
        { key: 'balance', label: 'Balance', num: true, render: r =>
            el('strong', { style: 'color:' + (Number(r.balance) >= 0 ? 'var(--in)' : 'var(--out)') },
              money(r.balance)) },
        { key: 'opening_balance', label: 'Opened with', num: true, render: r => money(r.opening_balance) },
        { key: 'entry_count', label: 'Entries', num: true },
        { key: 'last_entry', label: 'Last movement', render: r => r.last_entry ? date(r.last_entry) : '—' },
        { key: 'status', label: '', render: r => Number(r.is_active)
            ? (Number(r.is_default) ? el('span', { class: 'tag in' }, 'default') : '')
            : el('span', { class: 'tag' }, 'closed') },
        { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
            el('button', { class: 'btn sm', onclick: () => statement(r) }, 'Statement'),
            canMove ? el('button', { class: 'btn sm', onclick: () => adjustForm(r, load) }, 'Add / take out') : null,
            canManage ? el('button', { class: 'btn sm', onclick: () => accountForm(r, load) }, 'Edit') : null) }
      ], d.accounts, { empty: {
        title: 'No accounts yet',
        hint: 'Add the places your money sits — the cash box, your bank, bKash.'
      } }), null, true)
    );
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Accounts'),
        el('h1', {}, 'Where the money is'),
        el('p', {}, 'Cash, bank and wallets. Every entry you record moves one of these.')),
      el('div', { class: 'page-actions' },
        canMove ? el('button', { class: 'btn', onclick: () => transferForm(load) }, 'Move money') : null,
        canManage ? el('button', { class: 'btn primary', onclick: () => accountForm(null, load) }, 'Add account') : null)),
    host
  );

  load();
  return page;
}

function accountForm(existing, onDone) {
  const fields = formFields([
    { name: 'name', label: 'Account name', required: true, span: 2,
      hint: 'What you call it: "Office cash", "City Bank 4417"' },
    { name: 'type', label: 'Kind', type: 'select', options: [
        { value: 'cash', label: 'Cash in hand' },
        { value: 'bank', label: 'Bank account' },
        { value: 'mobile', label: 'Mobile wallet (bKash, Nagad)' },
        { value: 'card', label: 'Card' },
        { value: 'other', label: 'Other' }] },
    { name: 'account_number', label: 'Account / wallet number' },
    { name: 'bank_name', label: 'Bank name' },
    { name: 'branch', label: 'Branch' },
    { name: 'opening_balance', label: 'Balance when you start', type: 'number',
      hint: 'What is in it today — the system counts from here' },
    { name: 'opening_date', label: 'Counting from', type: 'date', value: today() },
    { name: 'note', label: 'Note', span: 2 }
  ], existing || { type: 'cash', opening_balance: 0 });

  const isDefault = el('input', { type: 'checkbox', style: 'width:auto',
    checked: existing && Number(existing.is_default) ? true : undefined });

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const body = readFields(fields);
      body.is_default = isDefault.checked ? 1 : 0;
      if (existing) {
        await api.patch('/accounts/' + existing.id, body);
        toast('Account updated');
      } else {
        await api.post('/accounts', body);
        toast('Account added');
      }
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, existing ? 'Save changes' : 'Add account');

  const close = existing && Number(existing.is_active)
    ? el('button', { class: 'btn danger', onclick: async () => {
        if (!await confirmAction('Close ' + existing.name,
          'It stays in the books, but you cannot record new entries into it.')) return;
        try { const r = await api.del('/accounts/' + existing.id); toast(r.message); m.close(); onDone(); }
        catch (e) { fail(e); }
      } }, 'Close account')
    : null;

  const m = modal({
    title: existing ? existing.name : 'New account',
    body: el('div', {}, fields,
      el('label', { style: 'display:flex;align-items:center;gap:8px;font-size:13px;margin-top:6px' },
        isDefault, 'Use this one by default on money forms')),
    footer: [close, el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

/** Put money in or take it out by hand: owner money in, a withdrawal out. */
function adjustForm(account, onDone) {
  const fields = formFields([
    { name: 'direction', label: 'Which way', type: 'select', options: [
        { value: 'in', label: 'Money going in' },
        { value: 'out', label: 'Money coming out' }] },
    { name: 'amount', label: 'Amount', type: 'number', required: true },
    { name: 'entry_date', label: 'On', type: 'date', value: today() },
    { name: 'description', label: 'What for', span: 2,
      hint: 'Owner investment, petty cash top-up, bank charge…' }
  ]);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      await api.post('/accounts/' + account.id + '/adjust', readFields(fields));
      toast('Recorded');
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Record');

  const m = modal({
    title: account.name + ' — add or take out',
    body: el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'For money that is not a customer payment or an office expense.'),
      fields),
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

/** Move money between two of your own accounts. */
async function transferForm(onDone) {
  const res = await api.get('/accounts');
  const opts = res.data.accounts.map(a => ({
    value: a.id, label: a.name + ' — ' + money(a.balance)
  }));

  const fields = formFields([
    { name: 'from_account_id', label: 'Out of', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...opts] },
    { name: 'to_account_id', label: 'Into', type: 'select', required: true,
      options: [{ value: '', label: 'Choose…' }, ...opts] },
    { name: 'amount', label: 'Amount', type: 'number', required: true },
    { name: 'entry_date', label: 'On', type: 'date', value: today() },
    { name: 'description', label: 'Note', span: 2, hint: 'Cash deposited to bank, withdrawal…' }
  ]);

  const save = el('button', { class: 'btn primary', onclick: async () => {
    save.disabled = true;
    try {
      const r = await api.post('/account-transfer', readFields(fields));
      toast(r.message);
      m.close(); onDone();
    } catch (e) { fail(e); save.disabled = false; }
  } }, 'Move it');

  const m = modal({
    title: 'Move money between accounts',
    body: fields,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
  });
}

/** The account's own statement, reading like a bank statement. */
async function statement(account) {
  const host = el('div', {}, loading());
  const m = modal({ title: account.name + ' — statement', wide: true, body: host,
    footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Close')] });

  try {
    const res = await api.get('/accounts/' + account.id + '/statement');
    const d = res.data;
    fill(host, 
      el('div', { class: 'grid c4', style: 'margin-bottom:14px' },
        tile('Opening', money(d.opening_balance), date(d.from)),
        tile('In', money(d.money_in), 'received', 'in'),
        tile('Out', money(d.money_out), 'paid', 'out'),
        tile('Closing', money(d.closing_balance), date(d.to),
          Number(d.closing_balance) >= 0 ? 'in' : 'out')),
      table([
        { key: 'entry_date', label: 'Date', render: r => date(r.entry_date) },
        { key: 'description', label: 'Detail', render: r => el('div', {},
            el('strong', {}, first(r.party, r.description)),
            el('span', { class: 'sub' }, join(r.source, r.party ? r.description : null))) },
        { key: 'in', label: 'In', num: true, render: r =>
            r.direction === 'in' ? el('span', { style: 'color:var(--in)' }, money(r.amount)) : '—' },
        { key: 'out', label: 'Out', num: true, render: r =>
            r.direction === 'out' ? el('span', { style: 'color:var(--out)' }, money(r.amount)) : '—' },
        { key: 'running_balance', label: 'Balance', num: true, render: r =>
            el('strong', {}, money(r.running_balance)) }
      ], d.entries, { empty: { title: 'Nothing moved yet', hint: 'Entries will appear as you record money.' } })
    );
  } catch (e) {
    fail(e);
    fill(host, el('div', { class: 'empty' }, 'Could not load the statement.'));
  }
}

/** Accounts as picker options, with their balance so you can see where money is. */
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

/** Prove the mail settings work by sending yourself one message. */
function emailTestCard() {
  const to = el('input', { placeholder: 'your@email.com' });
  const out = el('div', { style: 'margin-top:10px;font-size:13px' });

  const go = el('button', { class: 'btn primary', onclick: async () => {
    go.disabled = true;
    fill(out, loading('Sending…'));
    try {
      const res = await api.post('/settings/test-email', { to: to.value.trim() });
      fill(out, el('span', {
        style: 'color:' + (res.data.success ? 'var(--in)' : 'var(--out)')
      }, res.message));
    } catch (e) {
      fill(out, el('span', { style: 'color:var(--out)' }, e.message || 'Failed'));
    }
    go.disabled = false;
  } }, 'Send a test email');

  return card('Check the email settings',
    el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'Save the settings above first, then send yourself one message. '
        + 'If it arrives, invoices and reminders can go out by email.'),
      el('div', { style: 'display:flex;gap:8px;align-items:flex-end' },
        el('label', { class: 'field', style: 'flex:1;margin:0' },
          el('span', {}, 'Send it to'), to),
        go),
      out));
}

/** How much room the files take, and what belongs to nothing any more. */
async function storageCard() {
  const host = el('div', {});

  const draw = async () => {
    fill(host, loading('…'));
    const res = await api.get('/settings/storage');
    const d = res.data;

    const clean = el('button', { class: 'btn danger', onclick: async () => {
      const goneCount = Number(d.orphan_files) + Number(d.stray_files || 0);
      if (!await confirmAction('Clear ' + goneCount + ' file(s)',
        'These belong to records you deleted. Once cleared they are gone from the server.')) return;
      try {
        const r = await api.post('/settings/storage/clean', {});
        toast(r.message);
        draw();
      } catch (e) { fail(e); }
    } }, 'Clear them');

    fill(host, 
      el('div', { class: 'grid c3', style: 'margin-bottom:14px' },
        tile('Files kept', String(d.total_files), 'scans, receipts, papers'),
        tile('Space used', d.total_size, 'under storage/'),
        tile('Belongs to nothing', String(Number(d.orphan_files) + Number(d.stray_files || 0)),
          (Number(d.orphan_files) + Number(d.stray_files || 0))
            ? 'can be cleared'
            : 'nothing to clear',
          (Number(d.orphan_files) + Number(d.stray_files || 0)) ? 'hold' : 'in')),

      table([
        { key: 'entity_type', label: 'Attached to', render: r => r.entity_type.replace(/_/g, ' ') },
        { key: 'files', label: 'Files', num: true },
        { key: 'size', label: 'Space', num: true }
      ], d.by_type, { empty: { title: 'No files yet', hint: 'Scans and receipts will collect here.' } }),

      (Number(d.orphan_files) + Number(d.stray_files || 0))
        ? el('div', { style: 'margin-top:14px' },
            el('p', { style: 'color:var(--muted);font-size:13px' },
              d.orphan_files
                ? 'Some of these were attached to records you have since deleted. '
                  + 'They are kept until you say otherwise, in case the delete was a mistake.'
                : 'These are files on the server that nothing points at any more.'),
            clean)
        : null
    );
  };

  await draw();
  return card('Files and space', host);
}

/**
 * A full copy of the code and the database, made by hand or automatically
 * right before every update and rollback - the thing that makes both of
 * those safe to try.
 */
async function backupsCard() {
  const host = el('div', {});

  const bytes = n => n > 1024 * 1024 ? (n / 1024 / 1024).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
  const when = iso => new Date(iso).toLocaleString();

  const draw = async () => {
    fill(host, loading('…'));
    const res = await api.get('/backups');
    const rows = res.data;

    fill(host,
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'A backup holds the database and every file in storage/ - your customers, invoices and '
        + 'documents, not just the code. One is made automatically right before every update or '
        + 'roll back below, so you rarely need to press this yourself.'),
      el('button', { class: 'btn sm', onclick: async e => {
        e.target.disabled = true;
        try { await api.post('/backups', {}); toast('Backup made'); draw(); }
        catch (err) { fail(err); e.target.disabled = false; }
      } }, 'Make a backup now'),

      table([
        { key: 'name', label: 'Backup' },
        { key: 'created_at', label: 'Made', render: r => when(r.created_at) },
        { key: 'size', label: 'Size', num: true, render: r => bytes(r.size) },
        { key: 'actions', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
            el('a', { class: 'btn sm', href: api.base + '/backups/' + encodeURIComponent(r.name) + '/download',
                      onclick: e => { e.preventDefault(); downloadWithAuth(r.name); } }, 'Download'),
            el('button', { class: 'btn sm danger', onclick: async () => {
              if (!await confirmAction('Delete this backup', r.name + ' will be gone for good.')) return;
              try { await api.del('/backups/' + encodeURIComponent(r.name)); toast('Removed'); draw(); }
              catch (e) { fail(e); }
            } }, 'Delete')) }
      ], rows, { empty: { title: 'No backups yet', hint: 'Make one, or update the system below and one is made for you.' } })
    );
  };

  async function downloadWithAuth(name) {
    try {
      const r = await fetch(api.base + '/backups/' + encodeURIComponent(name) + '/download',
        { headers: { Authorization: 'Bearer ' + api.store.access } });
      if (!r.ok) throw new Error('Could not download');
      const blob = await r.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = name; a.click();
      URL.revokeObjectURL(url);
    } catch (e) { toast(e.message || 'Could not download', 'err'); }
  }

  await draw();
  return card('Backups', host);
}

/**
 * Applying a new zip, and rolling back to an earlier one. Both ask for the
 * password again - this is the one place in Settings that rewrites the
 * app's own code, and both make their own backup first regardless.
 */
async function updateCard() {
  const host = el('div', {});

  const fileInput = el('input', { type: 'file', accept: '.zip' });
  const pw1 = el('input', { type: 'password', placeholder: 'Your password' });
  const updateResult = el('div', { style: 'margin-top:10px' });

  const rollbackSelect = el('select', {},
    el('option', { value: '' }, 'Choose a backup…'));
  const pw2 = el('input', { type: 'password', placeholder: 'Your password' });
  const rollbackResult = el('div', { style: 'margin-top:10px' });

  (async () => {
    try {
      const res = await api.get('/backups');
      fill(rollbackSelect,
        el('option', { value: '' }, 'Choose a backup…'),
        ...res.data.map(b => el('option', { value: b.name },
          b.name + ' — ' + new Date(b.created_at).toLocaleString())));
    } catch { /* the select just stays empty */ }
  })();

  const reportList = steps => el('ul', { style: 'margin:8px 0 0;padding-left:18px;font-size:12.5px;color:var(--muted)' },
    ...steps.filter(s => s.status !== 'there').map(s => el('li', {
      style: s.status === 'failed' ? 'color:var(--out)' : 'color:var(--in)'
    }, s.label + (s.status === 'failed' ? ' — ' + s.error : ''))));

  fill(host,
    el('p', { style: 'color:var(--muted);margin-top:0' },
      'Upload the new AH5 Office zip you were given. A backup of the current code and database is '
      + 'made automatically first. Your storage/ folder and this server\'s own settings '
      + '(core/config.local.php) are never touched by this, whatever the zip contains.'),

    el('div', { class: 'grid c2', style: 'gap:10px;max-width:480px' }, fileInput, pw1),
    el('button', { class: 'btn primary sm', style: 'margin-top:10px', onclick: async e => {
      const btn = e.target;
      if (!fileInput.files[0]) { toast('Choose the update zip first', 'err'); return; }
      if (!pw1.value) { toast('Your password is needed to update', 'err'); return; }
      if (!await confirmAction('Update AH5 Office',
        'A backup is made first, but this changes the running code. Continue?', 'Update')) return;
      btn.disabled = true;
      fill(updateResult, loading('Updating…'));
      try {
        const fd = new FormData();
        fd.append('file', fileInput.files[0]);
        fd.append('password', pw1.value);
        const r = await fetch(api.base + '/system/update', {
          method: 'POST', headers: { Authorization: 'Bearer ' + api.store.access }, body: fd
        });
        const data = await r.json();
        if (!data.success) throw new Error(data.message);
        pw1.value = '';
        fill(updateResult,
          el('p', { style: 'font-weight:550;margin:0;color:var(--in)' }, data.message),
          el('p', { style: 'font-size:12.5px;color:var(--muted);margin:6px 0 0' },
            'Backed up first as ' + data.data.backup + '.'),
          reportList(data.data.schema));
      } catch (err) {
        fill(updateResult, el('p', { style: 'color:var(--out);margin:0' }, err.message || 'Could not update'));
      }
      btn.disabled = false;
    } }, 'Update now'),
    updateResult,

    el('div', { style: 'margin-top:22px;padding-top:16px;border-top:1px solid var(--rule-soft)' },
      el('p', { style: 'font-weight:600;margin:0 0 6px' }, 'Roll back'),
      el('p', { style: 'color:var(--muted);margin:0 0 10px;font-size:13px' },
        'Puts the code back exactly as it was in a chosen backup. The database is left alone - a '
        + 'rollback only ever undoes a code change, never your work.'),
      el('div', { class: 'grid c2', style: 'gap:10px;max-width:480px' }, rollbackSelect, pw2),
      el('button', { class: 'btn sm', style: 'margin-top:10px', onclick: async e => {
        const btn = e.target;
        if (!rollbackSelect.value) { toast('Choose a backup to roll back to', 'err'); return; }
        if (!pw2.value) { toast('Your password is needed to roll back', 'err'); return; }
        if (!await confirmAction('Roll back the code',
          'A backup of what is running now is made first. Continue?', 'Roll back')) return;
        btn.disabled = true;
        fill(rollbackResult, loading('Rolling back…'));
        try {
          const r = await api.post('/system/rollback', { name: rollbackSelect.value, password: pw2.value });
          pw2.value = '';
          fill(rollbackResult, el('p', { style: 'font-weight:550;margin:0;color:var(--in)' }, r.message));
        } catch (err) {
          fill(rollbackResult, el('p', { style: 'color:var(--out);margin:0' }, err.message || 'Could not roll back'));
        }
        btn.disabled = false;
      } }, 'Roll back'),
      rollbackResult)
  );

  return card('Update the system', host);
}

/**
 * All documents, gathered under the customer they belong to.
 * You almost never ask "which documents exist" - you ask "what do I hold
 * for Rahim Traders, and when does it run out".
 */
function documentsByCustomer(rows, actions, picker) {
  if (!rows.length) {
    return [card('All documents',
      el('div', { class: 'empty' },
        el('strong', {}, 'No documents yet'),
        el('div', {}, 'Add a passport, licence or domain to be reminded before it expires.')))];
  }

  const groups = new Map();
  for (const r of rows) {
    const key = r.customer_id ?? 'none';
    if (!groups.has(key)) {
      groups.set(key, { name: first(r.company_name, r.customer_name), id: r.customer_id, rows: [] });
    }
    groups.get(key).rows.push(r);
  }

  const soonest = g => g.rows.reduce((min, r) =>
    r.days_left !== null && (min === null || Number(r.days_left) < min) ? Number(r.days_left) : min, null);

  const ordered = [...groups.values()].sort((a, b) => {
    const x = soonest(a), y = soonest(b);
    if (x === null) return 1;
    if (y === null) return -1;
    return x - y;
  });

  return ordered.map(g => {
    const next = soonest(g);
    return card(null,
      el('div', {},
        el('div', {
          style: 'display:flex;justify-content:space-between;align-items:center;gap:12px;'
               + 'padding:12px 16px;border-bottom:1px solid var(--rule-soft)'
        },
          el('div', {},
            el('strong', { style: 'font-size:14.5px' }, g.name),
            el('span', { class: 'sub' },
              g.rows.length + ' document' + (g.rows.length === 1 ? '' : 's')
              + (next === null ? '' : ' · next ' + daysPhrase(next)))),
          g.id
            ? el('a', { class: 'btn sm', href: '#/customers/' + g.id }, 'Open customer')
            : null),
        table([
          ...(picker ? [picker.column] : []),
          { key: 'title', label: 'Document', render: r => el('div', {},
              el('strong', {}, r.title),
              el('span', { class: 'sub' }, r.doc_type_name || '')) },
          { key: 'file_count', label: 'Files', render: r => Number(r.file_count)
              ? el('span', { class: 'clip' }, '📎 ' + r.file_count) : '—' },
          { key: 'expiry_date', label: 'Expires', render: r => el('div', {},
              date(r.expiry_date),
              el('span', {
                class: 'sub',
                style: Number(r.days_left) < 30 ? 'color:var(--out)' : ''
              }, daysPhrase(r.days_left))) },
          { key: 'status', label: 'Status', render: r => tag(r.status) },
          { key: 'act', label: '', render: actions }
        ], g.rows)),
      null, true);
  });
}

/** The ways money changes hands, as listed in Settings. */
async function methodChoices() {
  try {
    const res = await api.get('/payment-methods');
    return res.data.map(m => ({ value: m.name, label: m.name }));
  } catch {
    return [{ value: 'Cash', label: 'Cash' }];
  }
}

/** The ways money changes hands - your words, your list. */
async function methodsCard() {
  const host = el('div', {});

  const draw = async () => {
    fill(host, loading('…'));
    const res = await api.get('/payment-methods', { all: 1 });
    fill(host, table([
      { key: 'name', label: 'Way of paying', render: r => el('strong', {}, r.name) },
      { key: 'used_count', label: 'Used by', num: true, render: r => (r.used_count || 0) + ' entries' },
      { key: 'is_active', label: '', render: r => Number(r.is_active) ? '' : el('span', { class: 'tag' }, 'off') },
      { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
          el('button', { class: 'btn sm', onclick: async () => {
            const name = window.prompt('What should it be called?', r.name);
            if (!name || name === r.name) return;
            try {
              await api.patch('/payment-methods/' + r.id, { name });
              toast('Renamed — past entries updated too');
              draw();
            } catch (e) { fail(e); }
          } }, 'Rename'),
          Number(r.is_active)
            ? el('button', { class: 'btn sm danger', onclick: async () => {
                if (!await confirmAction('Remove ' + r.name,
                  Number(r.used_count)
                    ? 'It is on ' + r.used_count + ' entr(ies), so it will be switched off.'
                    : 'It will be removed from the list.')) return;
                try { const rr = await api.del('/payment-methods/' + r.id); toast(rr.message); draw(); }
                catch (e) { fail(e); }
              } }, 'Remove')
            : el('button', { class: 'btn sm', onclick: async () => {
                try { await api.patch('/payment-methods/' + r.id, { is_active: 1 }); toast('Back in use'); draw(); }
                catch (e) { fail(e); }
              } }, 'Restore')) }
    ], res.data, { empty: { title: 'No ways listed', hint: 'Add how you take and give money.' } }));
  };

  const nameInput = el('input', { placeholder: 'Cash, bKash, Cheque, Bank transfer…' });
  const add = el('button', { class: 'btn primary', onclick: async () => {
    if (!nameInput.value.trim()) { toast('Give it a name', 'err'); return; }
    try {
      await api.post('/payment-methods', { name: nameInput.value.trim() });
      toast('Added');
      nameInput.value = '';
      draw();
    } catch (e) { fail(e); }
  } }, 'Add');

  await draw();
  return card('How money changes hands',
    el('div', {},
      el('p', { style: 'color:var(--muted);margin-top:0' },
        'This is the list you see on every payment and expense. Rename one and '
        + 'every past entry follows, so old records keep making sense.'),
      el('div', { style: 'display:flex;gap:8px;align-items:flex-end;margin-bottom:14px' },
        el('label', { class: 'field', style: 'flex:1;margin:0' }, el('span', {}, 'Add a way'), nameInput),
        add),
      host));
}

/**
 * Clear what the browser is holding, and show which build is running.
 * A browser will happily keep yesterday's JavaScript after you upload new
 * files, which makes a finished change look like it never happened. This
 * card tells you what you are actually running and forces a fresh copy.
 */
function cacheCard() {
  const out = el('div', { style: 'margin-top:10px;font-size:13px;color:var(--muted)' });
  const build = el('span', { class: 'num' }, '…');

  (async () => {
    try {
      const res = await api.get('/ping');
      build.textContent = res.data.build || 'unknown';
    } catch {
      build.textContent = 'could not read';
    }
  })();

  const clear = el('button', { class: 'btn primary', onclick: async () => {
    clear.disabled = true;
    try {
      work.clearCaches();
      if (window.caches && window.caches.keys) {
        const names = await window.caches.keys();
        await Promise.all(names.map(n => window.caches.delete(n)));
      }
      fill(out, el('span', { style: 'color:var(--in)' },
        'Cleared. Loading a fresh copy from the server…'));
      toast('Cache cleared');
      // a plain reload can still come from cache; a changing address cannot
      setTimeout(() => {
        const hash = window.location.hash || '#/settings';
        window.location.href = window.location.pathname + '?fresh=' + Date.now() + hash;
      }, 700);
    } catch (e) {
      fill(out, el('span', { style: 'color:var(--out)' }, e.message || 'Could not clear'));
      clear.disabled = false;
    }
  } }, 'Clear the cache and load fresh');

  return card('Cache and version',
    el('div', {},
      el('div', {
        style: 'display:flex;justify-content:space-between;align-items:baseline;'
             + 'gap:12px;padding:10px 0 14px;border-bottom:1px solid var(--rule-soft);margin-bottom:14px'
      },
        el('span', {}, 'Build running right now',
          el('span', { class: 'sub' }, 'compare this with the build you uploaded')),
        el('strong', { style: 'font-size:15px' }, build)),

      el('p', { style: 'color:var(--muted);margin-top:0' },
        'The panel keeps customer, supplier and service lists in memory so pages open fast, '
        + 'and your browser keeps a copy of the panel itself. If something looks out of date — '
        + 'a change you know was made but cannot see — clear it here.'),
      el('p', { style: 'color:var(--faint);font-size:12.5px;margin:0 0 12px' },
        'This only empties what the browser is holding. Nothing in your books, files or '
        + 'settings is touched, and you stay signed in.'),
      clear,
      out,
      el('div', {
        style: 'margin-top:18px;padding-top:14px;border-top:1px solid var(--rule-soft)'
      },
        el('p', { style: 'color:var(--muted);margin:0 0 8px' },
          'After you upload new files for an update, a change here and there needs a '
          + 'matching change in the database — a new column, a wider choice of options. '
          + 'This adds only what is missing; it never touches your customers, invoices '
          + 'or files, and running it twice does nothing the second time.'),
        el('button', { class: 'btn sm', onclick: async e => {
          const btn = e.target;
          btn.disabled = true;
          const box = btn.nextSibling;
          fill(box, loading('Checking…'));
          try {
            const res = await api.post('/settings/upgrade-schema', {});
            fill(box,
              el('p', { style: 'font-weight:550;margin:10px 0 6px;'
                             + 'color:' + (res.data.added ? 'var(--in)' : 'var(--muted)') }, res.message),
              el('ul', { style: 'margin:0;padding-left:18px;font-size:12.5px;color:var(--muted)' },
                ...res.data.steps
                  .filter(s => s.status !== 'there')
                  .map(s => el('li', {
                    style: s.status === 'failed' ? 'color:var(--out)' : 'color:var(--in)'
                  }, s.label + (s.status === 'failed' ? ' — ' + s.error : '')))));
          } catch (err) {
            fill(box, el('p', { style: 'color:var(--out);margin:10px 0 0' }, err.message || 'Could not check'));
          }
          btn.disabled = false;
        } }, 'Check the database is up to date'),
        el('div', { style: 'margin-top:4px' }),
        el('button', { class: 'btn sm', style: 'margin-top:8px', onclick: async e => {
          const btn = e.target;
          btn.disabled = true;
          try {
            const res = await api.post('/settings/clear-opcache', {});
            toast(res.message);
          } catch (err) { fail(err); }
          btn.disabled = false;
        } }, 'Clear the server\'s code cache'))));
}

/* ============================================================== website */

/**
 * The public home page, written from here. Each section is a block on the
 * page: turn it off, reorder it, change its words in English and Bengali,
 * and fill it with rows — either your own services or whatever you type.
 */
export async function website(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let services = [];

  const load = async () => {
    fill(host, loading());
    const [res, svc] = await Promise.all([
      api.get('/site'),
      api.get('/services', { per_page: 200 }).catch(() => ({ data: [] }))
    ]);
    services = svc.data;
    const d = res.data;

    fill(host,
      card('How the site looks',
        el('div', {},
          (() => {
            // captured directly, so Save never has to go hunting through the
            // DOM for a ".fields" class that was never actually applied -
            // that lookup silently found nothing and the button did nothing
            const lookFields = formFields([
            { name: 'site_enabled', label: 'Show the site at all', type: 'select',
              options: [{ value: '1', label: 'Yes — visitors see the home page' },
                        { value: '0', label: 'No — send everyone to the sign-in page' }] },
            { name: 'site_default_lang', label: 'Open in', type: 'select',
              options: [{ value: 'en', label: 'English' }, { value: 'bn', label: 'Bengali' }],
              hint: 'Visitors can switch with one tap either way' },
            { name: 'site_theme', label: 'Look', type: 'select',
              options: Object.entries(d.themes).map(([v, l]) => ({ value: v, label: l })), span: 2 },
            { name: 'site_accent', label: 'Accent colour', attrs: { type: 'color' } },
            { name: 'site_notify_whatsapp', label: 'WhatsApp me each message', type: 'select',
              options: [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }] },
            { name: 'site_tagline_en', label: 'One-line tagline (English)',
              hint: 'Shown under the logo and in the footer' },
            { name: 'site_tagline_bn', label: 'One-line tagline (Bengali)' },
            { name: 'site_meta_description', label: 'One line for Google', type: 'textarea', span: 2,
              hint: 'What shows under your name in search results' }
            ], d.settings);
            return el('div', {}, lookFields,
              el('div', { style: 'display:flex;gap:8px;justify-content:flex-end;margin-top:6px' },
                el('a', { class: 'btn', href: '../index.php', target: '_blank', rel: 'noopener' },
                  'Open the site'),
                el('button', { class: 'btn primary', onclick: async () => {
                  try { await api.patch('/settings', readFields(lookFields)); toast('Saved'); load(); }
                  catch (err) { fail(err); }
                } }, 'Save')));
          })())),

      card('Social and the app',
        el('div', {},
          el('p', { style: 'color:var(--muted);margin-top:0' },
            'Leave any of these blank and that icon or box simply does not show on the site.'),
          (() => {
            const socialFields = formFields([
            { name: 'site_social_facebook', label: 'Facebook page', span: 2, attrs: { type: 'url' },
              hint: 'The full link, e.g. https://facebook.com/yourpage' },
            { name: 'site_social_instagram', label: 'Instagram', span: 2, attrs: { type: 'url' } },
            { name: 'site_social_youtube', label: 'YouTube', span: 2, attrs: { type: 'url' } },
            { name: 'site_app_playstore', label: 'Google Play link', span: 2, attrs: { type: 'url' },
              hint: 'Shows a "Get the app" band above the footer' },
            { name: 'site_app_appstore', label: 'App Store link', span: 2, attrs: { type: 'url' } }
            ], d.settings);
            return el('div', {}, socialFields,
              el('div', { style: 'display:flex;justify-content:flex-end;margin-top:6px' },
                el('button', { class: 'btn primary', onclick: async () => {
                  try { await api.patch('/settings', readFields(socialFields)); toast('Saved'); load(); }
                  catch (err) { fail(err); }
                } }, 'Save')));
          })())),

      ...d.sections.map(sec => sectionCard(sec, d.kinds, load)),

      card('Add a section',
        el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' },
          ...Object.entries(d.kinds).map(([kind, what]) =>
            el('button', {
              class: 'btn sm',
              title: what,
              onclick: async () => {
                try { await api.post('/site/sections', { kind }); toast('Added'); load(); }
                catch (e) { fail(e); }
              }
            }, kind))))
    );
  };

  /** One block of the page, with its rows. */
  function sectionCard(sec, kinds, onDone) {
    const on = Number(sec.is_active) === 1;
    // every kind except the two whose rows already are the whole content
    const takesButton = sec.kind !== 'ticker' && sec.kind !== 'stats';

    // One language at a time. Two columns of near-identical boxes was a wall
    // of text; a tab keeps the eye on the sentence being written.
    const english = formFields([
      { name: 'eyebrow_en', label: 'Small line above', span: 2 },
      { name: 'heading_en', label: 'Heading', span: 2 },
      { name: 'body_en', label: 'Words', type: 'textarea', span: 2 },
      ...(takesButton ? [
        { name: 'cta_label_en', label: 'Button text',
          hint: sec.kind === 'banner' ? '' : 'Leave blank for no button' },
        { name: 'cta_link', label: 'Button goes to', hint: '#contact, or a full address' }
      ] : [])
    ], sec);

    const bangla = formFields([
      { name: 'eyebrow_bn', label: 'উপরের ছোট লাইন', span: 2 },
      { name: 'heading_bn', label: 'শিরোনাম', span: 2 },
      { name: 'body_bn', label: 'লেখা', type: 'textarea', span: 2 },
      ...(takesButton ? [
        { name: 'cta_label_bn', label: 'বোতামের লেখা', span: 2 }
      ] : [])
    ], sec);
    bangla.style.display = 'none';

    const tabs = el('div', { class: 'tab-strip', style: 'margin-bottom:12px' },
      el('button', { 'aria-pressed': 'true', onclick: e => {
        english.style.display = ''; bangla.style.display = 'none';
        e.target.setAttribute('aria-pressed', 'true');
        e.target.nextSibling.setAttribute('aria-pressed', 'false');
      } }, 'English'),
      el('button', { 'aria-pressed': 'false', onclick: e => {
        english.style.display = 'none'; bangla.style.display = '';
        e.target.setAttribute('aria-pressed', 'true');
        e.target.previousSibling.setAttribute('aria-pressed', 'false');
      } }, 'বাংলা'));

    const fields = el('div', {}, tabs, english, bangla);

    const rows = el('div', {});
    const drawRows = () => fill(rows, ...(sec.items || []).map(it => itemRow(it, sec.kind, onDone)));
    drawRows();

    const move = async dir => {
      const ids = [...document.querySelectorAll('[data-section-id]')]
        .map(n => Number(n.dataset.sectionId));
      const at = ids.indexOf(Number(sec.id));
      const to = at + dir;
      if (at < 0 || to < 0 || to >= ids.length) return;
      ids.splice(to, 0, ids.splice(at, 1)[0]);
      try { await api.post('/site/reorder', { order: ids }); onDone(); }
      catch (e) { fail(e); }
    };

    const summary = el('summary', {
      style: 'cursor:pointer;padding:14px 16px;display:flex;align-items:center;'
           + 'justify-content:space-between;gap:12px;list-style:none'
    },
      el('span', {},
        el('strong', { style: 'font-size:14.5px' },
          sec.heading_en || kinds[sec.kind] || sec.kind),
        el('span', { class: 'sub' },
          join(kinds[sec.kind],
            (sec.items || []).length ? (sec.items || []).length + ' rows' : null,
            on ? null : 'hidden'))),
      el('span', { class: 'tag ' + (on ? 'in' : '') }, on ? 'showing' : 'hidden'));

    return card(
      null,
      el('details', { dataset: { sectionId: sec.id }, style: on ? '' : 'opacity:.6' },
        summary,
        el('div', { style: 'padding:0 16px 16px' },
        fields,
        el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 16px' },
          el('button', { class: 'btn primary sm', onclick: async () => {
            try {
              await api.patch('/site/sections/' + sec.id,
                { ...readFields(english), ...readFields(bangla) });
              toast('Saved');
              onDone();
            } catch (e) { fail(e); }
          } }, 'Save this section'),
          el('button', { class: 'btn sm', onclick: () => move(-1) }, 'Move up'),
          el('button', { class: 'btn sm', onclick: () => move(1) }, 'Move down'),
          el('button', { class: 'btn sm', onclick: async () => {
            try {
              await api.patch('/site/sections/' + sec.id, { is_active: on ? 0 : 1 });
              toast(on ? 'Hidden' : 'Showing'); onDone();
            } catch (e) { fail(e); }
          } }, on ? 'Hide' : 'Show'),
          el('button', { class: 'btn sm danger', onclick: async () => {
            if (!await confirmAction('Remove this section',
              'It disappears from the site. Your services and other records are untouched.')) return;
            try { await api.del('/site/sections/' + sec.id); toast('Removed'); onDone(); }
            catch (e) { fail(e); }
          } }, 'Remove')),

        sec.kind === 'services' && !(sec.items || []).length
          ? el('p', { style: 'color:var(--muted);font-size:13px;margin:0 0 10px' },
              'Nothing added here yet, so the site is listing your services automatically. '
              + 'Add a row below to take over and write them yourself.')
          : null,

        el('h3', { style: 'font-size:12px;letter-spacing:.1em;text-transform:uppercase;'
                        + 'color:var(--muted);margin:14px 0 8px' }, 'Rows'),
        rows,
        el('div', { style: 'display:flex;gap:8px;margin-top:10px' },
          el('button', { class: 'btn sm', onclick: () => addRow(sec, null, onDone) }, 'Write a row'),
          el('button', { class: 'btn sm', onclick: () => addRow(sec, 'service', onDone) },
            'Add one of my services')))),
      null, true);
  }

  /** A single row: a service, a problem you solve, a step. */
  function itemRow(it, kind, onDone) {
    const isSlideOrLine = kind === 'banner' || kind === 'ticker';
    const title = el('input', {
      name: 'title_en',
      value: it.title_en || '',
      placeholder: it.service_name || (kind === 'ticker' ? 'The notice text' : 'Title')
    });
    const titleBn = el('input', { name: 'title_bn', value: it.title_bn || '', placeholder: 'বাংলা (ঐচ্ছিক)' });
    const body = el('textarea', {
      name: 'body_en', rows: 2, value: it.body_en || '',
      placeholder: kind === 'stats' ? 'The label under the number, e.g. "Projects delivered"' : ''
    });
    const bodyBn = el('textarea', { name: 'body_bn', rows: 2, value: it.body_bn || '', placeholder: 'বাংলা (ঐচ্ছিক)' });

    // a hero slide's own button, or a ticker line's own link — nothing
    // else needs one, so this only appears for those two kinds
    const ctaLabel = el('input', { value: it.cta_label_en || '', placeholder: 'Button text' });
    const ctaLabelBn = el('input', { value: it.cta_label_bn || '', placeholder: 'বাংলা (ঐচ্ছিক)' });
    const ctaLink = el('input', {
      value: it.cta_link || '',
      placeholder: kind === 'ticker' ? 'Where it links to (optional)' : '#contact, or a full address'
    });
    const icon = el('input', {
      value: it.icon || '', maxlength: 4,
      placeholder: 'One emoji, e.g. 🌐'
    });

    const picture = el('div', {});
    const drawPicture = () => fill(picture,
      it.image_url
        ? el('div', { style: 'display:flex;align-items:center;gap:8px' },
            el('img', { src: it.image_url, alt: '',
              style: 'height:44px;border-radius:6px;border:1px solid var(--rule)' }),
            el('button', { class: 'btn sm danger', onclick: async () => {
              try { await api.del('/site/image/item/' + it.id); it.image_url = null; drawPicture(); }
              catch (e) { fail(e); }
            } }, 'Remove picture'))
        : el('input', {
            type: 'file', accept: '.jpg,.jpeg,.png,.webp,.gif',
            onchange: async e => {
              const file = e.target.files && e.target.files[0];
              if (!file) return;
              const fd = new FormData();
              fd.append('file', file);
              try {
                const r = await fetch(api.base + '/site/image/item/' + it.id, {
                  method: 'POST',
                  headers: { Authorization: 'Bearer ' + api.store.access },
                  body: fd
                });
                const data = await r.json();
                if (!data.success) throw new Error(data.message);
                it.image_url = data.data.image_url;
                drawPicture();
                toast('Picture added');
              } catch (err) { toast(err.message || 'Could not upload', 'err'); }
            }
          }));
    drawPicture();

    return el('div', {
      style: 'border:1px solid var(--rule);border-radius:var(--radius-sm);'
           + 'padding:12px;margin-bottom:10px'
    },
      it.service_name
        ? el('span', { class: 'tag in', style: 'margin-bottom:8px;display:inline-block' },
            'from your services: ' + it.service_name)
        : null,
      el('div', { class: 'grid c2', style: 'gap:10px' }, title, titleBn),
      el('div', { class: 'grid c2', style: 'gap:10px;margin-top:8px' }, body, bodyBn),
      isSlideOrLine
        ? el('div', { class: 'grid c2', style: 'gap:10px;margin-top:8px' }, ctaLabel, ctaLabelBn, ctaLink)
        : null,
      (kind === 'services' || kind === 'forms')
        ? el('div', { style: 'margin-top:8px;max-width:160px' }, icon)
        : null,
      kind === 'ticker' ? null : el('div', { style: 'margin-top:10px' }, picture),
      el('div', { style: 'display:flex;gap:8px;margin-top:10px' },
        el('button', { class: 'btn sm primary', onclick: async () => {
          try {
            await api.patch('/site/items/' + it.id, {
              title_en: title.value, title_bn: titleBn.value,
              body_en: body.value, body_bn: bodyBn.value,
              ...(isSlideOrLine ? {
                cta_label_en: ctaLabel.value, cta_label_bn: ctaLabelBn.value,
                cta_link: ctaLink.value
              } : {}),
              ...((kind === 'services' || kind === 'forms') ? { icon: icon.value } : {})
            });
            toast('Saved');
          } catch (e) { fail(e); }
        } }, 'Save row'),
        el('button', { class: 'btn sm danger', onclick: async () => {
          try { await api.del('/site/items/' + it.id); toast('Removed'); onDone(); }
          catch (e) { fail(e); }
        } }, 'Remove row')));
  }

  async function addRow(sec, from, onDone) {
    if (from === 'service') {
      const fields = formFields([
        { name: 'service_id', label: 'Which service', type: 'select', span: 2,
          options: [{ value: '', label: 'Choose…' },
            ...services.map(s => ({ value: s.id, label: s.name }))] }
      ]);
      const save = el('button', { class: 'btn primary', onclick: async () => {
        const body = readFields(fields);
        if (!body.service_id) { toast('Pick a service', 'err'); return; }
        try {
          await api.post('/site/sections/' + sec.id + '/items', body);
          toast('Added'); m.close(); onDone();
        } catch (e) { fail(e); }
      } }, 'Add it');
      const m = modal({
        title: 'Add one of your services',
        body: el('div', {},
          el('p', { style: 'color:var(--muted);margin-top:0' },
            'The name and description come from your service list. Nothing about '
            + 'cost or price is ever shown on the site.'),
          fields),
        footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
      });
      return;
    }

    const fields = formFields([
      { name: 'title_en', label: 'Title', required: true, span: 2 },
      { name: 'body_en', label: 'A line or two', type: 'textarea', span: 2 }
    ]);
    const save = el('button', { class: 'btn primary', onclick: async () => {
      try {
        await api.post('/site/sections/' + sec.id + '/items', readFields(fields));
        toast('Added'); m.close(); onDone();
      } catch (e) { fail(e); }
    } }, 'Add it');
    const m = modal({
      title: 'Write a row',
      body: fields,
      footer: [el('button', { class: 'btn', onclick: () => m.close() }, 'Cancel'), save]
    });
  }

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Settings'),
        el('h1', {}, 'Website'),
        el('p', {}, 'What people see at your own address, before they sign in.')),
      el('div', { class: 'page-actions' },
        el('a', { class: 'btn primary', href: '../index.php', target: '_blank', rel: 'noopener' },
          'Open the site'))),
    host
  );

  load();
  return page;
}

/* ============================================================ enquiries */

/** Messages people sent from the website. */
export async function enquiries(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let status = '';

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/enquiries', { status: status || undefined, per_page: 50 });

    fill(host, card(null, table([
      { key: 'name', label: 'From', render: r => el('div', {},
          el('strong', { style: r.status === 'new' ? 'color:var(--ink)' : '' }, r.name),
          el('span', { class: 'sub' }, join(r.phone, r.email))) },
      { key: 'message', label: 'Message', render: r => el('div', {},
          r.subject ? el('strong', {}, r.subject) : null,
          el('span', { class: 'sub' }, String(r.message || '').slice(0, 90))) },
      { key: 'created_at', label: 'When', render: r => date(r.created_at) },
      { key: 'status', label: '', render: r => r.customer_name
          ? el('span', { class: 'tag in' }, r.customer_name)
          : tag(r.status) },
      { key: 'act', label: '', render: r => el('div', { style: 'display:flex;gap:6px' },
          el('button', { class: 'btn sm', onclick: () => open(r.id) }, 'Read'),
          r.phone && ctx.can('messages.send')
            ? el('a', { class: 'btn sm', target: '_blank', rel: 'noopener',
                href: 'https://wa.me/' + String(r.phone).replace(/[^0-9]/g, '') }, 'Reply')
            : null,
          ctx.can('customers.delete')
            ? el('button', { class: 'btn sm danger', onclick: async () => {
                if (!await confirmAction('Delete the message from ' + r.name,
                  'It goes for good.')) return;
                try { await api.del('/enquiries/' + r.id); toast('Deleted'); load(); }
                catch (e) { fail(e); }
              } }, 'Delete')
            : null) }
    ], res.data, {
      empty: {
        title: 'No messages yet',
        hint: 'When someone fills in the form on your site, it lands here.'
      }
    }), null, true));
  };

  const open = async id => {
    const res = await api.get('/enquiries/' + id);
    const r = res.data;

    const m = modal({
      title: r.name,
      body: el('div', {},
        el('p', { style: 'color:var(--muted);margin-top:0' },
          join(r.phone, r.email, date(r.created_at))),
        r.subject ? el('h3', { style: 'margin:0 0 8px;font-size:15px' }, r.subject) : null,
        el('p', { style: 'white-space:pre-wrap;background:var(--rule-soft);'
                       + 'padding:14px;border-radius:6px' }, r.message || ''),
        r.customer_name
          ? el('p', { style: 'color:var(--in)' }, 'Already a customer: ' + r.customer_name)
          : null),
      footer: [
        el('button', { class: 'btn', onclick: () => { m.close(); load(); } }, 'Close'),
        !r.customer_id && ctx.can('customers.edit')
          ? el('button', { class: 'btn primary', onclick: async () => {
              try {
                const out = await api.post('/enquiries/' + id + '/convert', {});
                toast(out.message);
                m.close(); load();
              } catch (e) { fail(e); }
            } }, 'Make them a customer')
          : null,
        ctx.can('customers.edit')
          ? el('button', { class: 'btn', onclick: async () => {
              try { await api.patch('/enquiries/' + id, { status: 'spam' }); toast('Marked as spam'); m.close(); load(); }
              catch (e) { fail(e); }
            } }, 'Spam')
          : null,
        ctx.can('customers.delete')
          ? el('button', { class: 'btn danger', onclick: async () => {
              if (!await confirmAction('Delete this message',
                'It goes for good. If they are already a customer, that record stays.')) return;
              try { await api.del('/enquiries/' + id); toast('Deleted'); m.close(); load(); }
              catch (e) { fail(e); }
            } }, 'Delete')
          : null
      ]
    });
  };

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Outbox'),
        el('h1', {}, 'Enquiries'),
        el('p', {}, 'Messages from the form on your website.')),
      el('div', { class: 'page-actions' },
        el('select', { onchange: e => { status = e.target.value; load(); } },
          el('option', { value: '' }, 'All'),
          el('option', { value: 'new' }, 'Unread'),
          el('option', { value: 'replied' }, 'Replied'),
          el('option', { value: 'spam' }, 'Spam')))),
    host
  );

  load();
  return page;
}

/** One picture in Settings: show it, replace it, remove it. */
function brandImageCard(kind, title, explain) {
  const host = el('div', {});

  const draw = async () => {
    fill(host, loading('…'));
    try {
      const res = await api.get('/settings');
      const has = !!res.data[kind];
      const src = '../asset.php?key=' + kind + '&v=' + Date.now();

      const picker = el('input', {
        type: 'file', accept: '.jpg,.jpeg,.png,.webp',
        onchange: async e => {
          const file = e.target.files && e.target.files[0];
          if (!file) return;
          const fd = new FormData();
          fd.append('file', file);
          fd.append('kind', kind);
          try {
            const r = await fetch(api.base + '/settings/image', {
              method: 'POST',
              headers: { Authorization: 'Bearer ' + api.store.access },
              body: fd
            });
            const data = await r.json();
            if (!data.success) throw new Error(data.message);
            toast('Saved — reload to see it in the sidebar');
            draw();
          } catch (err) { toast(err.message || 'Could not upload', 'err'); }
        }
      });

      fill(host,
        el('p', { style: 'color:var(--muted);margin-top:0' }, explain),
        has
          ? el('div', { style: 'display:flex;align-items:center;gap:14px;margin-bottom:12px' },
              el('img', { src, alt: '',
                style: 'max-height:56px;border:1px solid var(--rule);'
                     + 'border-radius:6px;padding:6px;background:#fff' }),
              el('button', { class: 'btn sm danger', onclick: async () => {
                try { await api.del('/settings/image/' + kind); toast('Removed'); draw(); }
                catch (e) { fail(e); }
              } }, 'Remove'))
          : el('p', { style: 'color:var(--faint);font-size:13px' }, 'Nothing set yet.'),
        picker);
    } catch (e) {
      fill(host, el('p', { style: 'color:var(--out)' }, 'Could not load'));
    }
  };

  draw();
  return card(title, host);
}

/**
 * What the office spends, by category, across days, weeks or months.
 * Categories down the side, periods across the top — so you can see both
 * where the money goes and whether it is creeping up.
 */
export async function expenseReport(ctx) {
  const page = el('div', { class: 'page' });
  const host = el('div', {});
  let grain = 'monthly';
  const filter = { category_id: '', from: '', to: '' };
  const cats = (await api.get('/expense-categories', { kind: 'expense' })).data;

  const load = async () => {
    fill(host, loading());
    const res = await api.get('/reports/expenses', {
      grain,
      category_id: filter.category_id || undefined,
      from: filter.from || undefined,
      to: filter.to || undefined
    });
    const d = res.data;

    if (!d.categories.length) {
      fill(host, card(null, el('div', { class: 'empty' },
        el('strong', {}, 'Nothing spent in this window'),
        el('div', {}, 'Record some office expenses and they will break down here.'))));
      return;
    }

    const columns = [
      { key: 'category', label: 'Category', render: r => el('strong', {}, r.category) },
      ...d.buckets.map(b => ({
        key: b.key, label: b.label, num: true,
        render: r => Number(r.cells[b.key])
          ? money(r.cells[b.key])
          : el('span', { style: 'color:var(--faint)' }, '—')
      })),
      { key: 'total', label: 'Total', num: true,
        render: r => el('strong', {}, money(r.total)) }
    ];

    const footer = {
      category: 'All categories',
      cells: d.column_totals,
      total: d.total
    };

    fill(host,
      el('div', { class: 'grid c3', style: 'margin-bottom:16px' },
        tile('Spent in this window', money(d.total),
          date(d.from) + ' – ' + date(d.to), 'out'),
        tile('Biggest category', d.busiest || '—',
          d.categories[0] ? money(d.categories[0].total) : ''),
        tile('Categories in use', String(d.categories.length),
          d.buckets.length + ' ' + d.grain_label.toLowerCase() + ' column(s)')),

      card('By category, by ' + d.grain.replace('ly', ''),
        table(columns, [...d.categories, footer]), null, true),

      el('p', { style: 'color:var(--faint);font-size:12.5px;margin-top:10px' },
        'The last row is the total for each ' + d.grain.replace('ly', '') + '.')
    );
  };

  const tab = (label, value) => el('button', {
    'aria-pressed': String(grain === value),
    onclick: e => {
      grain = value;
      page.querySelectorAll('.tab-strip button').forEach(b =>
        b.setAttribute('aria-pressed', String(b === e.target)));
      load();
    }
  }, label);

  page.append(
    el('div', { class: 'page-head' },
      el('div', {},
        el('span', { class: 'eyebrow' }, 'Overview'),
        el('h1', {}, 'Expense report'),
        el('p', {}, 'Where the office money goes, and whether it is going up.')),
      el('div', { class: 'page-actions' },
        moneyFilters(cats, filter, load),
        el('div', { class: 'tab-strip' },
          tab('Daily', 'daily'), tab('Weekly', 'weekly'), tab('Monthly', 'monthly')))),
    host
  );

  load();
  return page;
}
