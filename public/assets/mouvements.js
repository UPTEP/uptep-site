(() => {
  const page = document.querySelector('.moves-page');
  if (!page) return;
  const me = page.dataset.me;
  const filters = [...page.querySelectorAll('.moves-filter')];
  const items = [...page.querySelectorAll('.moves-item')];
  const none = page.querySelector('[data-moves-none]');
  const status = page.querySelector('[data-moves-status]');
  const left = seconds => {
    if (seconds <= 0) return 'arrivé';
    const d = Math.floor(seconds / 86400), h = Math.floor(seconds % 86400 / 3600), m = Math.floor(seconds % 3600 / 60);
    if (d) return `dans ${d} j ${h} h`;
    return h ? `dans ${h} h ${String(m).padStart(2, '0')}` : `dans ${m} min`;
  };
  const apply = () => {
    const shown = new Set(filters.filter(b => b.dataset.filter !== 'me' && b.getAttribute('aria-pressed') === 'true').map(b => b.dataset.filter));
    const onlyMe = filters.some(b => b.dataset.filter === 'me' && b.getAttribute('aria-pressed') === 'true');
    let visible = 0;
    for (const item of items) {
      const ok = shown.has(item.dataset.type) && (!onlyMe || item.dataset.players.split(',').includes(me));
      item.hidden = !ok; if (ok) visible++;
    }
    // Hide hours and days left empty by the filters.
    for (const hour of page.querySelectorAll('.moves-hour')) hour.hidden = ![...hour.querySelectorAll('.moves-item')].some(i => !i.hidden);
    for (const day of page.querySelectorAll('.moves-day')) day.hidden = ![...day.querySelectorAll('.moves-hour')].some(h => !h.hidden);
    if (none) none.hidden = visible > 0 || !items.length;
    if (status) status.textContent = `${visible} mouvement${visible > 1 ? 's' : ''} affiché${visible > 1 ? 's' : ''}.`;
  };
  // Remaining times and arrived items stay current while the page is open.
  const tick = () => {
    const now = Date.now() / 1000;
    for (const node of page.querySelectorAll('.moves-left')) node.textContent = left(Number(node.dataset.at) - now);
    // Only today's page dims arrived items; past days stay fully readable.
    if (page.dataset.today === '1') for (const item of items) item.classList.toggle('is-past', Number(item.dataset.at) <= now);
  };
  for (const button of filters) button.addEventListener('click', () => {
    button.setAttribute('aria-pressed', button.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
    apply();
  });
  // Items are focusable: a tap or Tab shows the tooltip, Escape hides it.
  for (const item of items) item.addEventListener('keydown', e => { if (e.key === 'Escape') item.blur(); });
  // Day navigation: choosing a date opens it, and the arrow keys move to the previous or next day.
  const dayInput = document.getElementById('moves-day');
  if (dayInput) dayInput.addEventListener('change', () => { if (dayInput.value) dayInput.form.submit(); });
  document.addEventListener('keydown', e => {
    if (e.altKey || e.ctrlKey || e.metaKey || e.target.closest('input,textarea,select')) return;
    const link = document.querySelector(e.key === 'ArrowLeft' ? '.moves-nav a[rel=prev]' : e.key === 'ArrowRight' ? '.moves-nav a[rel=next]' : null);
    if (link) location.href = link.href;
  });
  tick(); apply(); setInterval(tick, 30000);
})();
