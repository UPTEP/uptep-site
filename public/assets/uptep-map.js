// Shared player map (chaine page, Trajets tool): coloured groups, zoom, full screen and distance measures.
window.uptepMap = (root, cfg) => {
  const { players, groups } = cfg;
  const NS = 'http://www.w3.org/2000/svg';
  const form = root.querySelector('[data-map-filters]');
  const svg = root.querySelector('[data-map-svg]');
  const surface = root.querySelector('[data-map-surface]');
  const tip = root.querySelector('[data-map-tip]');
  const tags = root.querySelector('[data-map-labels]');
  const legend = root.querySelector('[data-map-legend]');
  const count = cfg.count || null;
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
  const groupOf = cfg.groupOf;
  const colorOf = Object.fromEntries(groups.map(g => [g.key, g.color]));
  const dot = key => { const node = html('span', 'chaine-map-dot'); node.style.background = colorOf[key]; return node; };

  // Group chips: every group starts checked, with the number of points it covers.
  const chips = form.querySelector('[data-map-options]');
  for (const group of groups) {
    const chip = html('label', 'chaine-map-chip');
    const input = html('input');
    Object.assign(input, { type: 'checkbox', name: 'group', value: group.key, checked: true, defaultChecked: true });
    chip.append(input, dot(group.key), `${group.label} `, html('small', '', String(players.filter(p => groupOf(p) === group.key).length)));
    chips.append(chip);
  }
  // Badges crowd a phone-sized or busy map: pseudos only there (reset keeps that default).
  const labels = surface.clientWidth < 520 ? 'pseudo' : cfg.labels || 'badge';
  { const option = form.elements.labels.querySelector(`option[value="${labels}"]`); option.defaultSelected = option.selected = true; }

  const readFilters = () => {
    const data = new FormData(form);
    return { groups: new Set(data.getAll('group')), labels: data.get('labels'), sized: data.get('sized') === 'on' };
  };

  // Camera in game coordinates; y grows upwards like on the portal map.
  let width = 0, height = 0, view = null, filters = readFilters(), shown = players;
  // Distance measure from a clicked point: free end on the cursor, snapped to a nearby point, or pinned.
  let measure = null, measureLayer = null;
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
      if (cfg.ring && cfg.ring(p)) g.append(el('circle', { cx: s.x, cy: s.y, r: r + 5, class: 'cm-ring-self' }));
      g.append(el('circle', { cx: s.x, cy: s.y, r, fill: colorOf[groupOf(p)], class: 'cm-dot' }));
      layer.append(g);
      // Labels are HTML on top of the map so they can reuse the page badges.
      if (filters.labels !== 'none') {
        const tag = html('span', 'cm-tag');
        tag.append(html('span', 'cm-tag-name', p.pseudo));
        if (filters.labels === 'badge' && cfg.badge) tag.append(cfg.badge(p));
        const left = s.x > width - 190;
        tag.classList.toggle('is-left', left);
        tag.style.left = (left ? s.x - r - 6 : s.x + r + 6) + 'px';
        tag.style.top = s.y + 'px';
        tags.append(tag);
      }
    }
    svg.append(layer);
    measureLayer = el('g', { class: 'cm-measure' });
    svg.append(measureLayer);
    renderMeasure();
  };

  const update = () => {
    filters = readFilters();
    shown = players.filter(p => filters.groups.has(groupOf(p)));
    if (count) count.textContent = shown.length === players.length ? `${players.length} ${cfg.noun}` : `${shown.length} / ${players.length} ${cfg.noun}`;
    legend.replaceChildren(...groups.map(group => {
      const n = shown.filter(p => groupOf(p) === group.key).length;
      const item = html('span', n ? '' : 'is-empty');
      item.append(dot(group.key), `${group.label} (${n})`);
      return item;
    }));
    if (measure && (!shown.includes(measure.from) || (measure.to && !shown.includes(measure.to)))) stopMeasure();
    if (!measure) hideTip();
    draw();
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
    tip.classList.remove('is-measure');
    tip.replaceChildren(html('strong', '', member.pseudo), html('span', '', cfg.info(member)));
    tip.hidden = false;
    tip.style.left = Math.max(6, Math.min(width - tip.offsetWidth - 6, at.x + 14)) + 'px';
    tip.style.top = Math.max(6, at.y - tip.offsetHeight - 10) + 'px';
  };

  // Distance and one-way attack travel time, same formula as the userscript (checked against Toolzzz).
  const travelSeconds = (distance, speed) => (Number.isInteger(speed) ? Math.round(86400 * (1 - Math.exp(-distance / 350)) * 7.375 * Math.pow(0.9, speed)) : null);
  const duration = seconds => {
    const days = Math.floor(seconds / 86400), pad = n => String(n).padStart(2, '0');
    return (days ? days + 'j ' : '') + pad(Math.floor(seconds % 86400 / 3600)) + ':' + pad(Math.floor(seconds % 3600 / 60)) + ':' + pad(seconds % 60);
  };
  const SNAP = 26;
  const snapAt = at => {
    let best = null, distance = SNAP;
    for (const p of shown) {
      if (p === measure.from) continue;
      const s = screen(p), d = Math.hypot(s.x - at.x, s.y - at.y);
      if (d <= distance) { best = p; distance = d; }
    }
    return best;
  };
  const renderMeasure = () => {
    if (!measureLayer) return;
    measureLayer.replaceChildren();
    if (!measure) return;
    const from = measure.from, a = screen(from);
    const end = measure.to ? measure.to : measure.at;
    measureLayer.append(el('circle', { cx: a.x, cy: a.y, r: radius(from) + 6, class: 'cm-measure-origin' }));
    if (!end) return;
    const b = screen(end);
    measureLayer.append(el('line', { x1: a.x, y1: a.y, x2: b.x, y2: b.y, class: 'cm-measure-line' }));
    measureLayer.append(el('circle', { cx: b.x, cy: b.y, r: measure.to ? radius(measure.to) + 6 : 4, class: measure.to ? 'cm-measure-target' : 'cm-measure-end' }));
    // Shown rounded up like the userscript's distance column; the travel time uses the exact distance.
    const distance = Math.hypot(end.x - from.x, end.y - from.y);
    const rows = [html('strong', '', `${from.pseudo} → ${measure.to ? measure.to.pseudo : `X ${Math.round(end.x)} · Y ${Math.round(end.y)}`}`),
      html('span', '', `Distance : ${number(Math.ceil(distance))} cases`)];
    // undefined: no travel time on this map for that origin; null: attack speed unknown.
    const speed = cfg.speedOf ? cfg.speedOf(from) : undefined;
    if (speed !== undefined) {
      const seconds = travelSeconds(distance, speed);
      rows.push(html('span', '', seconds === null ? `Trajet : VA de ${from.pseudo} inconnue` : `Trajet : ${duration(seconds)} (VA ${speed})`));
    }
    rows.push(html('small', '', measure.pinned ? 'Clic ailleurs pour effacer' : 'Clic sur un point pour figer · Échap pour annuler'));
    tip.replaceChildren(...rows);
    tip.classList.add('is-measure');
    tip.hidden = false;
    tip.style.left = Math.max(6, Math.min(width - tip.offsetWidth - 6, b.x + 16)) + 'px';
    tip.style.top = Math.max(6, b.y - tip.offsetHeight - 14) + 'px';
  };
  const stopMeasure = () => { measure = null; tip.classList.remove('is-measure'); hideTip(); renderMeasure(); };
  const clickMember = member => {
    if (!measure || measure.pinned) measure = { from: member, to: null, at: null, pinned: false };
    else if (member === measure.from) return stopMeasure();
    else Object.assign(measure, { to: member, pinned: true });
    tip.classList.add('is-measure');
    if (!measure.to) hideTip();
    renderMeasure();
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
    if (measure && !measure.pinned) {
      // Snap to a point near the cursor; moving further away frees the line again.
      measure.to = snapAt(p);
      measure.at = world(p.x, p.y);
      return renderMeasure();
    }
    if (measure) return;
    const member = memberAt(event.target);
    if (member) showTip(member, p); else hideTip();
  });
  svg.addEventListener('pointerup', event => {
    if (!drag || drag.id !== event.pointerId) return;
    if (!drag.moved) {
      const member = drag.member || (measure && !measure.pinned ? measure.to : null);
      if (member) clickMember(member);
      else if (measure) stopMeasure();
      else hideTip();
    }
    drag = null;
  });
  for (const type of ['pointercancel', 'lostpointercapture']) svg.addEventListener(type, () => { drag = null; });
  svg.addEventListener('pointerleave', event => { if (event.pointerType === 'mouse' && !measure) hideTip(); });
  svg.addEventListener('keydown', event => {
    const step = 60 / view.scale;
    const pan = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] }[event.key];
    if (pan) { view.x += pan[0]; view.y += pan[1]; hideTip(); draw(); }
    else if (event.key === '+' || event.key === '=') zoom(1.5);
    else if (event.key === '-') zoom(1 / 1.5);
    else if (event.key === 'Escape' && measure) stopMeasure();
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
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    // Escape first cancels a measure, then leaves the overlay full screen.
    if (measure) stopMeasure();
    else if (overlay) { overlay = false; syncFull(); fullButton.focus(); }
  });

  new ResizeObserver(() => {
    const ready = width > 0;
    width = surface.clientWidth; height = surface.clientHeight;
    if (!width || !height) return;
    if (!ready || refit) { fit(shown); refit = false; }
    hideTip(); draw();
  }).observe(surface);
  update();

  // Pins a measure between two points, e.g. from a table row.
  return {
    measure(fromId, toId) {
      const from = players.find(p => p.id === fromId), to = players.find(p => p.id === toId);
      if (!from || !to || !shown.includes(from) || !shown.includes(to)) return false;
      measure = { from, to, at: null, pinned: true };
      // Frame both ends so the line is easy to read among many points.
      if (width) fit([from, to]);
      draw();
      return true;
    },
  };
};
