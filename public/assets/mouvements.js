(() => {
  const page = document.querySelector('.moves-page');
  if (!page) return;
  const me = page.dataset.me;
  const filters = [...page.querySelectorAll('.moves-filter')];
  const items = [...page.querySelectorAll('.moves-item')];
  const none = page.querySelector('[data-moves-none]');
  const status = page.querySelector('[data-moves-status]');
  // "Mes cibles": chain players below the viewer, each with its level (1 = next position down).
  const targetsPanel = page.querySelector('[data-moves-targets]');
  const targetRows = targetsPanel ? [...targetsPanel.querySelectorAll('li[data-player]')] : [];
  const depthSelect = targetsPanel?.querySelector('[data-targets-depth]');
  const depth = () => Number(depthSelect?.value || 4);
  const targetSet = () => new Set(targetRows.filter(r => Number(r.dataset.level) <= depth()).map(r => r.dataset.player));
  const pressed = name => filters.some(b => b.dataset.filter === name && b.getAttribute('aria-pressed') === 'true');
  // Filters are saved on the account, shortly after each change.
  let saveTimer = null;
  const post = fields => { const body = new FormData(); body.set('csrf', page.dataset.csrf || ''); body.set('action', 'preferences'); for (const [k, v] of Object.entries(fields)) body.set(k, v); return fetch(location.pathname, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' }).catch(() => {}); };
  const save = () => {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => post({ prefs: JSON.stringify({ types: filters.filter(b => ['flood', 'convoy', 'hunt'].includes(b.dataset.filter) && b.getAttribute('aria-pressed') === 'true').map(b => b.dataset.filter), me: pressed('me'), targets: pressed('targets'), depth: depth(), members: memberChecks.filter(c => c.checked).map(c => c.value) }) }), 600);
  };
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
  // Member filter (one or several): a movement stays when one of its members is selected.
  const membersBox = page.querySelector('[data-moves-members]');
  const memberChecks = membersBox ? [...membersBox.querySelectorAll('input[type=checkbox]')] : [];
  const selectedMembers = () => new Set(memberChecks.filter(c => c.checked).map(c => c.value));
  const syncMembers = () => {
    const chosen = memberChecks.filter(c => c.checked);
    const label = membersBox?.querySelector('[data-members-label]');
    if (label) label.textContent = !chosen.length ? 'tous' : chosen.length <= 3 ? chosen.map(c => c.parentNode.querySelector('span').textContent).join(', ') : `${chosen.length} membres`;
    // Keep the selection in the address and in the day navigation, so it follows from day to day.
    const ids = chosen.map(c => c.value).join(',');
    const withMembers = href => { const url = new URL(href, location.href); if (ids) url.searchParams.set('membres', ids); else url.searchParams.delete('membres'); return url.pathname + url.search; };
    try { history.replaceState(null, '', withMembers(location.href)); } catch {}
    for (const link of page.querySelectorAll('.moves-nav a')) { try { link.setAttribute('href', withMembers(link.getAttribute('href'))); } catch {} }
    const form = page.querySelector('.moves-nav-form');
    if (form) { let hidden = form.querySelector('input[name=membres]'); if (!hidden) { hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'membres'; form.append(hidden); } hidden.value = ids; hidden.disabled = !ids; }
  };
  if (membersBox) {
    membersBox.addEventListener('change', () => { syncMembers(); apply(); save(); });
    membersBox.querySelector('[data-members-clear]')?.addEventListener('click', () => { for (const c of memberChecks) c.checked = false; syncMembers(); apply(); save(); });
    const search = membersBox.querySelector('[data-members-search]');
    search?.addEventListener('input', () => { const q = search.value.trim().toLowerCase(); for (const c of memberChecks) c.closest('li').hidden = !!q && !c.parentNode.textContent.toLowerCase().includes(q); });
    syncMembers();
    // A click or Escape outside the list closes it.
    document.addEventListener('click', e => { if (membersBox.open && !membersBox.contains(e.target)) membersBox.open = false; });
    window.addEventListener('blur', () => { membersBox.open = false; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && membersBox.open) { membersBox.open = false; membersBox.querySelector('summary').focus(); } });
  }
  const apply = () => {
    const shown = new Set(filters.filter(b => !['me', 'targets'].includes(b.dataset.filter) && b.getAttribute('aria-pressed') === 'true').map(b => b.dataset.filter));
    const onlyMe = pressed('me'), withTargets = !!targetsPanel && pressed('targets');
    let visible = 0;const members = selectedMembers(), targets = withTargets ? targetSet() : new Set();
    if (targetsPanel) { targetsPanel.hidden = !withTargets; for (const row of targetRows) row.hidden = Number(row.dataset.level) > depth(); }
    for (const item of items) {
      const players = item.dataset.players.split(',');
      // "Mes cibles" (floods and hunts of the targets) and chosen members add up; "Seulement moi" narrows both.
      const byTargets = withTargets && item.dataset.type !== 'convoy' && players.some(p => targets.has(p));
      const byMembers = members.size > 0 && players.some(p => members.has(p));
      const ok = shown.has(item.dataset.type) && (!onlyMe || players.includes(me)) && (withTargets || members.size ? byTargets || byMembers : true);
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
    apply(); save();
  });
  depthSelect?.addEventListener('change', () => { apply(); save(); });
  // Back to the default view, and the saved filters are forgotten.
  page.querySelector('[data-moves-reset]')?.addEventListener('click', () => {
    clearTimeout(saveTimer);
    for (const button of filters) button.setAttribute('aria-pressed', ['me', 'targets'].includes(button.dataset.filter) ? 'false' : 'true');
    if (depthSelect) depthSelect.value = '4';
    for (const c of memberChecks) c.checked = false;
    if (membersBox) syncMembers();
    apply(); post({ reset: '1' });
    if (status) status.textContent = 'Filtres réinitialisés.';
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
