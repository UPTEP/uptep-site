/* TdP chart (Statistiques): too many members share a TdP to label the lines, so each day column
 * carries its members grouped by TdP, shown in a tooltip on hover, touch or keyboard focus. */
(() => {
  for (const chart of document.querySelectorAll('.tdp-chart')) {
    const tip = chart.querySelector('.tdp-tooltip');
    const guide = chart.querySelector('.tdp-guide');
    let current = null;
    function fill(hit) {
      const data = JSON.parse(hit.dataset.tip);
      const head = document.createElement('strong');
      head.textContent = `${data.date} · moyenne ${data.moyenne}`;
      const list = document.createElement('dl');
      for (const [value, names] of data.groupes) {
        const term = document.createElement('dt');
        term.textContent = value;
        const detail = document.createElement('dd');
        detail.textContent = names.join(', ');
        list.append(term, detail);
      }
      tip.replaceChildren(head, list);
      guide.setAttribute('x1', hit.dataset.x);
      guide.setAttribute('x2', hit.dataset.x);
    }
    function show(hit, clientX) {
      if (current !== hit) {
        current = hit;
        fill(hit);
      }
      chart.classList.add('is-active');
      tip.hidden = false;
      // Beside the pointer (or the column), flipped to the left near the right edge.
      const box = chart.getBoundingClientRect();
      const column = hit.getBoundingClientRect();
      const x = (clientX ?? column.left + column.width / 2) - box.left;
      let left = x + 14;
      if (left + tip.offsetWidth > box.width) left = x - 14 - tip.offsetWidth;
      tip.style.left = `${Math.max(0, left)}px`;
    }
    function hide() {
      current = null;
      chart.classList.remove('is-active');
      tip.hidden = true;
    }
    chart.addEventListener('pointermove', (event) => {
      const hit = event.target.closest('.tdp-hit');
      if (hit) show(hit, event.clientX);
      else hide();
    });
    chart.addEventListener('pointerleave', hide);
    chart.addEventListener('focusin', (event) => {
      const hit = event.target.closest('.tdp-hit');
      if (hit) show(hit);
    });
    chart.addEventListener('focusout', hide);
  }
})();
