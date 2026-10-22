(() => {
  const page = document.querySelector('.moves-page');
  if (!page) return;
  const me = page.dataset.me;
  const filters = [...page.querySelectorAll('.moves-filter')];
  const items = [...page.querySelectorAll('.moves-item')];
  const none = page.querySelector('[data-moves-none]');
  const status = page.querySelector('[data-moves-status]');
  // Keep a tooltip inside the window: shift it left at the right edge, show it above when there is no room below.
  const fitTip = (item, tip) => {
    tip.style.left = ''; tip.style.top = ''; tip.style.bottom = '';
    const box = tip.getBoundingClientRect(), width = document.documentElement.clientWidth, height = document.documentElement.clientHeight;
    if (box.right > width - 6) tip.style.left = Math.min(0, width - 6 - box.right) + 'px';
    if (box.bottom > height - 6 && item.getBoundingClientRect().top > box.height + 12) { tip.style.top = 'auto'; tip.style.bottom = 'calc(100% + 6px)'; }
  };
  for (const item of page.querySelectorAll('.moves-item')) { const tip = item.querySelector('.moves-tip'); if (tip) for (const type of ['mouseenter', 'focus']) item.addEventListener(type, () => fitTip(item, tip)); }
  const left = seconds => {
    if (seconds <= 0) return 'arrivé';
    const d = Math.floor(seconds / 86400), h = Math.floor(seconds % 86400 / 3600), m = Math.floor(seconds % 3600 / 60);
    if (d) return `dans ${d} j ${h} h`;
    return h ? `dans ${h} h ${String(m).padStart(2, '0')}` : `dans ${m} min`;
  };
  // Today: hours already past are folded behind a button (shown again on demand).
  const isToday = page.dataset.today === '1';
  const earlierButton = page.querySelector('[data-moves-earlier]');
  let showEarlier = false;
  const parisHour = () => parseInt(new Intl.DateTimeFormat('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', hourCycle: 'h23' }).format(new Date()), 10);
  if (earlierButton) earlierButton.addEventListener('click', () => { showEarlier = !showEarlier; apply(); });
  const apply = () => {
    const shown = new Set(filters.filter(b => b.dataset.filter !== 'me' && b.getAttribute('aria-pressed') === 'true').map(b => b.dataset.filter));
    const onlyMe = filters.some(b => b.dataset.filter === 'me' && b.getAttribute('aria-pressed') === 'true');
    let visible = 0;
    for (const item of items) {
      const ok = shown.has(item.dataset.type) && (!onlyMe || item.dataset.players.split(',').includes(me));
      item.hidden = !ok; if (ok) visible++;
    }
    // Hide hours and days left empty by the filters.
    const current = isToday ? parisHour() : -1;let folded = 0, foldedHours = 0;
    for (const hour of page.querySelectorAll('.moves-hour')) {
      const count = [...hour.querySelectorAll('.moves-item')].filter(i => !i.hidden).length;
      const earlier = Number(hour.dataset.hour) < current;
      if (earlier && count) { folded += count; foldedHours++; }
      hour.hidden = !count || (earlier && !showEarlier);
      if (earlier && !showEarlier) visible -= count;
    }
    if (earlierButton) {
      earlierButton.hidden = !folded;
      earlierButton.setAttribute('aria-expanded', String(showEarlier));
      earlierButton.textContent = showEarlier ? `Masquer les heures précédentes (${folded} mouvement${folded > 1 ? 's' : ''})` : `Afficher les heures précédentes · ${foldedHours} heure${foldedHours > 1 ? 's' : ''}, ${folded} mouvement${folded > 1 ? 's' : ''}`;
    }
    for (const day of page.querySelectorAll('.moves-day')) day.hidden = ![...day.querySelectorAll('.moves-hour')].some(h => !h.hidden);
    if (none) { none.hidden = visible > 0 || !items.length; none.textContent = folded && !showEarlier ? 'Plus aucune arrivée prévue pour le reste de la journée.' : 'Aucun mouvement pour ces filtres.'; }
    if (status) status.textContent = `${visible} mouvement${visible > 1 ? 's' : ''} affiché${visible > 1 ? 's' : ''}.`;
  };
  // Remaining times and arrived items stay current while the page is open.
  const tick = () => {
    const now = Date.now() / 1000;
    for (const node of page.querySelectorAll('.moves-left')) node.textContent = left(Number(node.dataset.at) - now);
    // Only today's page dims arrived items; past days stay fully readable.
    if (page.dataset.today === '1') for (const item of items) item.classList.toggle('is-past', Number(item.dataset.at) <= now);
    // A new hour starts: the hour just ended joins the folded ones.
    if (isToday && parisHour() !== lastHour) { lastHour = parisHour(); apply(); }
  };
  let lastHour = isToday ? parisHour() : null;
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
