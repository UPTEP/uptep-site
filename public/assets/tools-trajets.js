(() => {
  'use strict';
  const form = document.querySelector('[data-trajets-form]');
  if (!form) return;
  const fold = text => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

  // Attack speed: prefilled from UPTEP+ when the viewer may see it, required otherwise.
  const speeds = JSON.parse(document.getElementById('trajets-speeds').textContent);
  const pseudo = form.elements.joueur, va = form.elements.va, hint = form.querySelector('[data-trajets-va-hint]');
  const baseHint = hint.textContent;
  const syncSpeed = () => {
    const known = speeds[pseudo.value.trim().toLowerCase()];
    va.required = known === undefined;
    va.placeholder = known === undefined ? 'Niveau, ex. 10' : `VA ${known} (UPTEP+)`;
    hint.textContent = known === undefined ? baseHint : `VA ${known} connue grâce à UPTEP+ : laisse vide pour l’utiliser, ou saisis un autre niveau.`;
  };
  pseudo.addEventListener('input', syncSpeed);
  syncSpeed();

  // Alliance list: text filter and a summary of the selection.
  const filter = form.querySelector('[data-trajets-filter]');
  const items = [...form.querySelectorAll('.trajets-alliance')];
  const none = form.querySelector('[data-trajets-none]');
  const picked = form.querySelector('[data-trajets-picked]');
  const boxes = items.map(item => item.querySelector('input'));
  filter.addEventListener('input', () => {
    const query = fold(filter.value.trim());
    let visible = 0;
    for (const item of items) { item.hidden = query !== '' && !fold(item.textContent).includes(query); if (!item.hidden) visible++; }
    none.hidden = visible > 0;
  });
  const syncPicked = () => {
    const names = boxes.filter(box => box.checked).map(box => box.closest('label').querySelector('span').textContent);
    picked.textContent = names.length ? `· ${names.length} : ${names.join(', ')}` : '';
    boxes[0].setCustomValidity(names.length ? '' : 'Choisis au moins une alliance.');
  };
  boxes.forEach(box => box.addEventListener('change', syncPicked));
  syncPicked();
  // Enter in the filter must not submit the form.
  filter.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });

  // Result map: origin player and the chosen alliances, no chain grades.
  const root = document.querySelector('[data-uptep-map="trajets"]');
  const source = document.getElementById('trajets-map-data');
  if (!root || !source || !window.uptepMap) return;
  const { players, origin, speed, tags } = JSON.parse(source.textContent);
  const number = n => new Intl.NumberFormat('fr-FR').format(n);
  const palette = ['#2f7fb5', '#c0405a', '#d39b16', '#7d55b8', '#2e8b57', '#d06a24', '#3a9d9d', '#b5487a', '#5b6abf', '#8a6d1f', '#5f7584', '#a33c39'];
  const name = tag => (tag === '' ? 'Sans alliance' : tag);
  const start = players.find(p => p.id === origin);
  const groups = [{ key: '\u0000origin', label: `Départ : ${start.pseudo}`, color: '#173747' }, ...tags.map((tag, i) => ({ key: tag, label: name(tag), color: palette[i % palette.length] }))];
  const colorOf = Object.fromEntries(groups.map(g => [g.key, g.color]));
  const groupOf = p => (p.id === origin ? '\u0000origin' : p.tag);
  const map = window.uptepMap(root, {
    players, groups, groupOf, noun: 'joueurs', count: document.querySelector('[data-trajets-count]'), labels: 'pseudo',
    ring: p => p.id === origin,
    badge: p => {
      const badge = document.createElement('span');
      badge.className = 'cm-alliance';
      badge.textContent = name(p.tag);
      badge.style.borderColor = colorOf[groupOf(p)];
      return badge;
    },
    info: p => `${name(p.tag)} · ${number(p.field)} TDC · X ${p.x}, Y ${p.y}${p.id === origin ? '' : ` · ${number(Math.ceil(Math.hypot(p.x - start.x, p.y - start.y)))} cases de ${start.pseudo}`}`,
    // The entered speed belongs to the origin player; other starting points get distances only.
    speedOf: p => (p.id === origin ? speed : undefined),
  });
  document.querySelectorAll('[data-trajets-show]').forEach(button => button.addEventListener('click', () => {
    if (map.measure(origin, Number(button.dataset.trajetsShow))) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));
})();
