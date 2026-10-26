(() => {
  const table = document.getElementById('hc-table');
  if (!table) return;
  const body = table.tBodies[0];
  const status = document.querySelector('[data-hc-sort-status]');
  const buttons = [...table.querySelectorAll('[data-hc-sort]')];
  const compareValues = (a, b, type) => {
    if (type === 'text') return a.localeCompare(b, 'fr', { numeric: true, sensitivity: 'base' });
    const x = BigInt(a), y = BigInt(b);
    return x < y ? -1 : x > y ? 1 : 0;
  };
  const sortBy = (button, direction) => {
    const key = button.dataset.hcSort, type = button.dataset.type;
    const rows = [...body.rows];
    rows.sort((a, b) => {
      const x = a.dataset[key] ?? '', y = b.dataset[key] ?? '';
      // Rows without data stay at the bottom whatever the direction.
      if (x === '' || y === '') return x === y ? 0 : x === '' ? 1 : -1;
      return direction * compareValues(x, y, type) || (a.dataset.name || '').localeCompare(b.dataset.name || '', 'fr', { sensitivity: 'base' });
    });
    rows.forEach(row => body.appendChild(row));
    buttons.forEach(other => other.closest('th').setAttribute('aria-sort', other === button ? (direction === 1 ? 'ascending' : 'descending') : 'none'));
    if (status) status.textContent = `Tableau trié par ${button.textContent.trim()}, ordre ${direction === 1 ? 'croissant' : 'décroissant'}.`;
  };
  buttons.forEach(button => button.addEventListener('click', () => {
    const current = button.closest('th').getAttribute('aria-sort');
    // Names start A→Z; numbers and dates start with the highest or most recent value.
    const first = button.dataset.type === 'text' || button.dataset.first === 'asc' ? 1 : -1;
    sortBy(button, current === 'none' ? first : (current === 'ascending' ? -1 : 1));
  }));

  // Column picker: the choice is a per-browser convenience, the defaults work without storage.
  const picker = document.getElementById('hc-columns');
  if (picker) {
    const storageKey = 'uptep-hc-columns';
    const boxes = [...picker.querySelectorAll('input[type=checkbox]')];
    const cells = key => table.querySelectorAll(`[data-col="${key}"]`);
    const count = picker.querySelector('[data-hc-columns-count]');
    const apply = () => {
      boxes.forEach(box => cells(box.value).forEach(cell => { cell.hidden = !box.checked; }));
      count.textContent = `${boxes.filter(box => box.checked).length}/${boxes.length}`;
    };
    const save = () => {
      try { localStorage.setItem(storageKey, JSON.stringify(boxes.filter(box => box.checked).map(box => box.value))); } catch {}
    };
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
      // Unknown keys (renamed columns) are ignored.
      if (Array.isArray(saved)) boxes.forEach(box => { box.checked = saved.includes(box.value); });
    } catch {}
    apply();
    boxes.forEach(box => box.addEventListener('change', () => { apply(); save(); }));
    picker.querySelector('[data-hc-columns-reset]').addEventListener('click', () => {
      boxes.forEach(box => { box.checked = box.dataset.default === '1'; });
      apply();
      try { localStorage.removeItem(storageKey); } catch {}
    });
    const search = document.getElementById('hc-columns-search');
    const empty = picker.querySelector('[data-hc-columns-empty]');
    const fold = text => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    search.addEventListener('input', () => {
      const query = fold(search.value.trim());
      picker.querySelectorAll('.hc-columns-group').forEach(group => {
        let visible = 0;
        group.querySelectorAll('label').forEach(label => {
          label.hidden = query !== '' && !fold(label.textContent).includes(query);
          if (!label.hidden) visible++;
        });
        group.hidden = visible === 0;
      });
      empty.hidden = [...picker.querySelectorAll('.hc-columns-group')].some(group => !group.hidden);
    });
    // Close on a click outside or Escape, like a menu.
    document.addEventListener('click', event => { if (picker.open && !picker.contains(event.target)) picker.open = false; });
    picker.addEventListener('keydown', event => {
      if (event.key === 'Escape' && picker.open) { picker.open = false; picker.querySelector('summary').focus(); }
    });
    picker.addEventListener('toggle', () => { if (picker.open) search.focus({ preventScroll: true }); });
  }

  // Member detail modal: opened from a row click or from the pseudo button.
  const modal = document.getElementById('hc-modal');
  if (!modal || typeof modal.showModal !== 'function') return;
  const title = document.getElementById('hc-modal-title');
  const content = document.getElementById('hc-modal-body');
  let opener = null;
  const openDetail = row => {
    const template = document.getElementById('hc-detail-' + row.dataset.playerId);
    if (!template) return;
    opener = row.querySelector('.hc-open');
    title.textContent = row.dataset.name;
    content.replaceChildren(template.content.cloneNode(true));
    content.scrollTop = 0;
    modal.showModal();
    content.focus({ preventScroll: true });
  };
  body.addEventListener('click', event => {
    const row = event.target.closest('tr.hc-row');
    if (row && !event.target.closest('a')) openDetail(row);
  });
  modal.addEventListener('click', event => {
    // A click on the backdrop lands on the dialog element itself.
    if (event.target === modal || event.target.closest('[data-hc-close]')) modal.close();
  });
  modal.addEventListener('close', () => { if (opener) opener.focus({ preventScroll: true }); });
})();
