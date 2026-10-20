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
