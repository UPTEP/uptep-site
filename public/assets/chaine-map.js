// Chaine page: alliance members coloured by chain grade, with the grade badge and chain order.
(() => {
  const root = document.querySelector('[data-uptep-map="chaine"]');
  const source = document.getElementById('chaine-map-data');
  if (!root || !source || !window.uptepMap) return;
  const { players, grades, times } = JSON.parse(source.textContent);
  const number = n => new Intl.NumberFormat('fr-FR').format(n);
  // Same hues as the grade badges of the tables, saturated enough for a dot.
  const colors = { Grenier: '#d39b16', Passeur: '#2f7fb5', Chasseur: '#7d55b8', 'A flooder': '#d06a24', 'Ne pas toucher': '#c0405a', 'Hors chaîne': '#5f7584', '': '#9aa39e' };
  const gradeName = grade => grade || 'Non attribué';
  const html = (tag, className, text) => { const node = document.createElement(tag); node.className = className; if (text !== undefined) node.textContent = text; return node; };
  window.uptepMap(root, {
    players, noun: 'membres', count: document.querySelector('[data-map-count]'),
    groups: [...grades, ''].filter(g => players.some(p => (p.grade || '') === g)).map(g => ({ key: g, label: gradeName(g), color: colors[g] })),
    groupOf: p => p.grade || '',
    ring: p => p.self,
    badge: p => {
      const badge = html('span', 'chaine-badge' + (p.grade ? '' : ' is-unassigned'), gradeName(p.grade));
      badge.dataset.grade = p.grade || '';
      if (p.order !== null) badge.append(html('span', 'chaine-badge-order', ` · ${p.order}`));
      return badge;
    },
    info: p => `${gradeName(p.grade)}${p.order !== null ? ' · ' + p.order : ''} · ${p.field === null ? '—' : number(p.field)} TDC · X ${p.x}, Y ${p.y}`,
    // Chefs get every member's attack speed; others get no travel time at all.
    speedOf: times ? p => p.speed ?? null : undefined,
  });
})();
