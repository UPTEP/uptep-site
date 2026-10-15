(() => {
'use strict';
const tabs = [...document.querySelectorAll('[data-tool-tab]')];
function activate(tab, focus = false) {
 for (const item of tabs) {
  const selected = item === tab;
  item.setAttribute('aria-selected', String(selected));
  item.tabIndex = selected ? 0 : -1;
  document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
 }
 if (focus) tab.focus();
}
for (const [index, tab] of tabs.entries()) {
 tab.addEventListener('click', () => activate(tab));
 tab.addEventListener('keydown', event => {
  const next = event.key === 'ArrowRight' ? (index + 1) % tabs.length : event.key === 'ArrowLeft' ? (index - 1 + tabs.length) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
  if (next !== null) { event.preventDefault(); activate(tabs[next], true); }
 });
}
})();
