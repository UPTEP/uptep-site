/* Keep a visual copy of the column labels in the viewport while the table scrolls.
 * The original header remains the accessible header for the table. */
(() => {
  const entries = [];
  for (const scroller of document.querySelectorAll('.convoy-page .users-scroll')) {
    const table = scroller.querySelector('table');
    if (!table?.tHead || !table.tBodies[0]?.rows.length) continue;
    const overlay = document.createElement('div');
    overlay.className = 'convoy-floating-head';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.inert = true;
    overlay.hidden = true;
    const copy = document.createElement('table');
    copy.className = 'users-table';
    overlay.append(copy);
    document.body.append(overlay);
    entries.push({scroller, table, overlay, copy});
  }
  if (!entries.length) return;
  let pending = false;
  function position() {
    pending = false;
    for (const {scroller, table, overlay, copy} of entries) {
      const bounds = table.getBoundingClientRect();
      const head = table.tHead.getBoundingClientRect();
      const viewport = scroller.getBoundingClientRect();
      const left = Math.max(0, viewport.left);
      const right = Math.min(document.documentElement.clientWidth, viewport.right);
      overlay.hidden = head.top >= 0 || bounds.bottom <= 0 || right <= left;
      if (overlay.hidden) continue;
      overlay.style.left = `${left}px`;
      overlay.style.width = `${right - left}px`;
      overlay.style.top = `${Math.min(0, bounds.bottom - head.height)}px`;
      copy.style.transform = `translateX(${bounds.left - left}px)`;
    }
  }
  function schedule() {
    if (pending) return;
    pending = true;
    requestAnimationFrame(position);
  }
  function measure() {
    for (const {table, copy} of entries) {
      const columns = document.createElement('colgroup');
      for (const cell of table.tHead.rows[0].cells) {
        const col = document.createElement('col');
        col.style.width = `${cell.getBoundingClientRect().width}px`;
        columns.append(col);
      }
      copy.style.width = `${table.getBoundingClientRect().width}px`;
      copy.replaceChildren(columns, table.tHead.cloneNode(true));
    }
    schedule();
  }
  // Capture also receives horizontal scroll events from each table wrapper.
  document.addEventListener('scroll', schedule, {capture: true, passive: true});
  window.addEventListener('resize', measure, {passive: true});
  if ('ResizeObserver' in window) {
    const observer = new ResizeObserver(measure);
    for (const {table} of entries) observer.observe(table);
  }
  document.fonts?.ready.then(measure);
  measure();
})();
