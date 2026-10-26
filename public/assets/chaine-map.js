(() => {
  const root = document.querySelector('[data-chaine-map]');
  const source = document.getElementById('chaine-map-data');
  if (!root || !source) return;
  const { players, grades } = JSON.parse(source.textContent);
  const NS = 'http://www.w3.org/2000/svg';
  const form = root.querySelector('[data-map-filters]');
  const svg = root.querySelector('[data-map-svg]');
  const surface = root.querySelector('[data-map-surface]');
  const tip = root.querySelector('[data-map-tip]');
  const tags = root.querySelector('[data-map-labels]');
  const legend = root.querySelector('[data-map-legend]');
  const count = document.querySelector('[data-map-count]');
  const number = n => new Intl.NumberFormat('fr-FR').format(n);
  const el = (tag, attrs = {}, text) => {
    const node = document.createElementNS(NS, tag);
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const html = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  // Same hues as the grade badges of the tables, saturated enough for a dot.
  const colors = { Grenier: '#d39b16', Passeur: '#2f7fb5', Chasseur: '#7d55b8', 'A flooder': '#d06a24', 'Ne pas toucher': '#c0405a', 'Hors chaîne': '#5f7584', '': '#9aa39e' };
  const gradeName = grade => grade || 'Non attribué';
  const gradeOptions = [...grades, ''].filter(g => players.some(p => (p.grade || '') === g));

  // Grade chips: every grade starts checked, with the number of members it covers.
  const chips = form.querySelector('[data-map-options="grade"]');
  for (const grade of gradeOptions) {
    const chip = html('label', 'chaine-map-chip');
    const input = html('input');
    Object.assign(input, { type: 'checkbox', name: 'grade', value: grade, checked: true, defaultChecked: true });
    const dot = html('span', 'chaine-map-dot');
    dot.dataset.grade = grade;
    chip.append(input, dot, `${gradeName(grade)} `, html('small', '', String(players.filter(p => (p.grade || '') === grade).length)));
    chips.append(chip);
  }
  // Grade badge as in the tables: grade name and chain order.
  const gradeBadge = p => {
    const badge = html('span', 'chaine-badge' + (p.grade ? '' : ' is-unassigned'), gradeName(p.grade));
    badge.dataset.grade = p.grade || '';
    if (p.order !== null) badge.append(html('span', 'chaine-badge-order', ` · ${p.order}`));
    return badge;
  };
  // Badges crowd a phone-sized map: default to pseudos only there (reset keeps that default).
  if (surface.clientWidth < 520) { const option = form.elements.labels.querySelector('option[value="pseudo"]'); option.defaultSelected = option.selected = true; }

  const readFilters = () => {
    const data = new FormData(form);
    return { grade: new Set(data.getAll('grade')), labels: data.get('labels'), sized: data.get('sized') === 'on' };
  };

  // Camera in game coordinates; y grows upwards like on the portal map.
  let width = 0, height = 0, view = null, filters = readFilters(), shown = players;
  const fit = targets => {
    const set = targets.length ? targets : players;
    const xs = set.map(p => p.x), ys = set.map(p => p.y);
    const spanX = Math.max(10, Math.max(...xs) - Math.min(...xs)), spanY = Math.max(10, Math.max(...ys) - Math.min(...ys));
    // Room for the labels, smaller on phones.
    const padX = Math.min(90, width * 0.16), padY = Math.min(70, height * 0.14);
    const scale = Math.min((width - padX) / spanX, (height - padY) / spanY, 60);
    view = { x: (Math.min(...xs) + Math.max(...xs)) / 2, y: (Math.min(...ys) + Math.max(...ys)) / 2, scale: Math.max(2, scale) };
  };
  const screen = p => ({ x: width / 2 + (p.x - view.x) * view.scale, y: height / 2 - (p.y - view.y) * view.scale });
  const world = (x, y) => ({ x: view.x + (x - width / 2) / view.scale, y: view.y - (y - height / 2) / view.scale });
  const fieldMax = Math.max(...players.map(p => p.field || 0), 1);
  const radius = p => (filters.sized && p.field ? 5 + 9 * Math.sqrt(p.field / fieldMax) : 7);

  const draw = () => {
    if (!width || !view) return;
    svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
    svg.replaceChildren();
    tags.replaceChildren();
    // Grid every 1, 2, 5, 10… game units depending on the zoom.
    const grid = el('g', { class: 'cm-grid' });
    const step = [1, 2, 5, 10, 20, 50].find(s => s * view.scale >= 45) || 100;
    const a = world(0, height), b = world(width, 0);
    for (let x = Math.ceil(a.x / step) * step; x <= b.x; x += step) {
      const px = screen({ x, y: 0 }).x;
      grid.append(el('line', { x1: px, x2: px, y1: 0, y2: height }), el('text', { x: px, y: height - 6, 'text-anchor': 'middle' }, String(x)));
    }
    for (let y = Math.ceil(a.y / step) * step; y <= b.y; y += step) {
      const py = screen({ x: 0, y }).y;
      grid.append(el('line', { x1: 0, x2: width, y1: py, y2: py }), el('text', { x: 6, y: py - 4 }, String(y)));
    }
    svg.append(grid);
    const layer = el('g');
    for (const p of shown) {
      const s = screen(p), r = radius(p);
      if (s.x < -60 || s.x > width + 60 || s.y < -30 || s.y > height + 30) continue;
      const g = el('g', { class: 'cm-member', 'data-id': p.id });
      if (p.self) g.append(el('circle', { cx: s.x, cy: s.y, r: r + 5, class: 'cm-ring-self' }));
      g.append(el('circle', { cx: s.x, cy: s.y, r, fill: colors[p.grade || ''], class: 'cm-dot' }));
      layer.append(g);
      // Labels are HTML on top of the map so they can reuse the table badges.
      if (filters.labels !== 'none') {
        const tag = html('span', 'cm-tag');
        tag.append(html('span', 'cm-tag-name', p.pseudo));
        if (filters.labels === 'grade') tag.append(gradeBadge(p));
        const left = s.x > width - 190;
        tag.classList.toggle('is-left', left);
        tag.style.left = (left ? s.x - r - 6 : s.x + r + 6) + 'px';
        tag.style.top = s.y + 'px';
        tags.append(tag);
      }
    }
    svg.append(layer);
  };

  const update = () => {
    filters = readFilters();
    shown = players.filter(p => filters.grade.has(p.grade || ''));
    count.textContent = shown.length === players.length ? `${players.length} membres` : `${shown.length} / ${players.length} membres`;
    legend.replaceChildren(...gradeOptions.map(grade => {
      const n = shown.filter(p => (p.grade || '') === grade).length;
      const item = html('span', n ? '' : 'is-empty');
      const dot = html('span', 'chaine-map-dot');
      dot.dataset.grade = grade;
      item.append(dot, `${gradeName(grade)} (${n})`);
      return item;
    }));
    hideTip(); draw();
  };
  form.addEventListener('input', update);
  form.addEventListener('change', update);
  form.addEventListener('submit', event => event.preventDefault());
  form.addEventListener('reset', () => setTimeout(() => { update(); fit(shown); draw(); }));

  // Tooltip: on hover with a mouse, on tap with a finger.
  const local = event => { const r = svg.getBoundingClientRect(); return { x: event.clientX - r.left, y: event.clientY - r.top }; };
  const memberAt = target => { const g = target.closest && target.closest('.cm-member'); return g ? players.find(p => String(p.id) === g.dataset.id) : null; };
  const hideTip = () => { tip.hidden = true; };
  const showTip = (member, at) => {
    tip.replaceChildren(html('strong', '', member.pseudo), html('span', '', `${gradeName(member.grade)}${member.order !== null ? ' · ' + member.order : ''} · ${member.field === null ? '—' : number(member.field)} TDC · X ${member.x}, Y ${member.y}`));
    tip.hidden = false;
    tip.style.left = Math.max(6, Math.min(width - tip.offsetWidth - 6, at.x + 14)) + 'px';
    tip.style.top = Math.max(6, at.y - tip.offsetHeight - 10) + 'px';
  };

  // Zoom and pan.
  const zoom = (factor, x = width / 2, y = height / 2) => {
    const p = world(x, y);
    view.scale = Math.max(2, Math.min(200, view.scale * factor));
    view.x = p.x - (x - width / 2) / view.scale;
    view.y = p.y + (y - height / 2) / view.scale;
    hideTip(); draw();
  };
  root.querySelectorAll('[data-map-zoom]').forEach(button => button.addEventListener('click', () => {
    const kind = button.dataset.mapZoom;
    if (kind === 'fit') { fit(shown); hideTip(); draw(); } else zoom(kind === 'in' ? 1.5 : 1 / 1.5);
  }));
  svg.addEventListener('wheel', event => { event.preventDefault(); const p = local(event); zoom(Math.exp(-Math.max(-150, Math.min(150, event.deltaY)) * 0.003), p.x, p.y); }, { passive: false });
  let drag = null;
  svg.addEventListener('pointerdown', event => {
    if (event.button !== 0) return;
    const p = local(event);
    drag = { id: event.pointerId, x: p.x, y: p.y, vx: view.x, vy: view.y, moved: false, member: memberAt(event.target) };
    svg.setPointerCapture(event.pointerId);
  });
  svg.addEventListener('pointermove', event => {
    const p = local(event);
    if (drag && drag.id === event.pointerId) {
      const dx = p.x - drag.x, dy = p.y - drag.y;
      if (Math.hypot(dx, dy) > 4) drag.moved = true;
      if (drag.moved) { view.x = drag.vx - dx / view.scale; view.y = drag.vy + dy / view.scale; hideTip(); draw(); }
      return;
    }
    if (event.pointerType !== 'mouse') return;
    const member = memberAt(event.target);
    if (member) showTip(member, p); else hideTip();
  });
  svg.addEventListener('pointerup', event => {
    if (!drag || drag.id !== event.pointerId) return;
    if (!drag.moved) { if (drag.member) showTip(drag.member, local(event)); else hideTip(); }
    drag = null;
  });
  for (const type of ['pointercancel', 'lostpointercapture']) svg.addEventListener(type, () => { drag = null; });
  svg.addEventListener('pointerleave', event => { if (event.pointerType === 'mouse') hideTip(); });
  svg.addEventListener('keydown', event => {
    const step = 60 / view.scale;
    const pan = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] }[event.key];
    if (pan) { view.x += pan[0]; view.y += pan[1]; hideTip(); draw(); }
    else if (event.key === '+' || event.key === '=') zoom(1.5);
    else if (event.key === '-') zoom(1 / 1.5);
    else return;
    event.preventDefault();
  });

  // The map is as tall as the window below the site menu; the menu height changes once its font loads.
  const topbar = document.querySelector('.topbar');
  const setTop = () => root.style.setProperty('--cm-top', (topbar ? topbar.offsetHeight : 0) + 'px');
  setTop();
  if (topbar) new ResizeObserver(setTop).observe(topbar);

  // Full screen: the browser API when it exists, a fixed overlay otherwise (iPhone).
  const fullButton = root.querySelector('[data-map-fullscreen]');
  let overlay = false, refit = false;
  const isFull = () => overlay || document.fullscreenElement === root;
  const syncFull = () => {
    const full = isFull();
    root.classList.toggle('is-fullscreen', full);
    document.documentElement.classList.toggle('chaine-map-locked', overlay);
    fullButton.textContent = full ? 'Quitter le plein écran' : 'Plein écran';
    fullButton.setAttribute('aria-pressed', String(full));
    refit = true;
  };
  fullButton.addEventListener('click', () => {
    if (isFull()) {
      if (overlay) { overlay = false; syncFull(); } else document.exitFullscreen().catch(() => {});
    } else if (root.requestFullscreen && document.fullscreenEnabled) {
      root.requestFullscreen().catch(() => { overlay = true; syncFull(); });
    } else { overlay = true; syncFull(); }
  });
  document.addEventListener('fullscreenchange', syncFull);
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && overlay) { overlay = false; syncFull(); fullButton.focus(); } });

  new ResizeObserver(() => {
    const ready = width > 0;
    width = surface.clientWidth; height = surface.clientHeight;
    if (!width || !height) return;
    if (!ready || refit) { fit(shown); refit = false; }
    hideTip(); draw();
  }).observe(surface);
  update();
})();
