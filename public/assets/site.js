'use strict';
document.querySelectorAll('[data-password]').forEach(button => {
  button.addEventListener('click', () => {
    const field = document.getElementById(button.dataset.password);
    const visible = field.type === 'password';
    field.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
  });
});

// Move existing rows: form values and pending edits remain attached to their player.
document.querySelectorAll('.chaine-table').forEach(table => {
  const body = table.tBodies[0];
  const initialOrder = new Map([...body.rows].map((row, index) => [row, index]));
  const collator = new Intl.Collator('fr', {numeric: true, sensitivity: 'base'});
  const grades = ['Grenier', 'Passeur', 'Chasseur', 'A flooder', 'Ne pas toucher', 'Hors chaîne'];
  let active = 'grade', direction = 1;
  function value(row, key) {
    if (key === 'grade') {
      const grade = row.querySelector('.chaine-grade')?.value ?? row.dataset.grade;
      return grade ? grades.indexOf(grade) : null;
    }
    if (key === 'movement') return /^-?\d+$/.test(row.dataset.movement) ? BigInt(row.dataset.movement) : null;
    if (key === 'order' || key === 'field') {
      const raw = key === 'order' ? (row.querySelector('.chaine-order')?.value ?? row.dataset.order) : row.dataset.field;
      return /^\d+$/.test(raw) ? BigInt(raw) : null;
    }
    return row.dataset.player;
  }
  function compare(a, b, key, sign) {
    const av = value(a, key), bv = value(b, key);
    if (av === null) return bv === null ? 0 : 1;
    if (bv === null) return -1;
    return sign * (key === 'player' ? collator.compare(av, bv) : (av < bv ? -1 : av > bv ? 1 : 0));
  }
  table.querySelectorAll('[data-sort]').forEach(button => {
    button.addEventListener('click', () => {
      const key = button.dataset.sort;
      // Grade restores the canonical groups; Order only reverses inside groups.
      direction = key === 'grade' ? 1 : (active === key ? -direction : key === 'order' ? -1 : 1);
      active = key;
      const grouped = key === 'grade' || key === 'order';
      const rows = [...body.rows].sort((a, b) => (grouped
        ? compare(a, b, 'grade', 1) || compare(a, b, 'order', direction * (key === 'grade' && value(a, 'grade') === 1 ? -1 : 1)) || compare(a, b, 'player', 1)
        : compare(a, b, key, direction)) || initialOrder.get(a) - initialOrder.get(b));
      body.append(...rows);
      table.querySelectorAll('[data-sort]').forEach(other => {
        other.closest('th').setAttribute('aria-sort', other === button ? (direction === 1 ? 'ascending' : 'descending') : 'none');
      });
      const status = table.closest('.users-panel').querySelector('[data-sort-status]');
      if (status) status.textContent = key === 'grade' ? 'Ordre de chaîne : passeurs du plus grand numéro au plus petit, autres grades en ordre croissant.' : grouped
        ? table.dataset.chaineGroup === 'outside' ? `Hors chaîne, puis ordre ${direction === 1 ? 'croissant' : 'décroissant'}.` : `Grenier → Passeur → Chasseur → A flooder → Ne pas toucher, puis ordre ${direction === 1 ? 'croissant' : 'décroissant'} dans chaque groupe.`
        : `Tri : ${button.textContent.trim()}, ordre ${direction === 1 ? 'croissant' : 'décroissant'}.`;
    });
  });
});

document.querySelectorAll('.chaine-grade').forEach(select => {
  select.addEventListener('change', () => { select.dataset.grade = select.value; });
});
