(() => {
  const dialog = document.getElementById('registration-terms');
  if (dialog && typeof dialog.showModal === 'function') {
    dialog.close();
    dialog.showModal();
    dialog.addEventListener('cancel', event => {
      event.preventDefault();
      document.getElementById('registration-decline').requestSubmit();
    });
  }
  const token = location.hash.slice(1);
  if (!token) return;
  // Fragment never reaches HTTP access logs. Remove it from browser history immediately.
  history.replaceState(null, '', location.pathname);
  if (!/^[a-f0-9]{64}$/.test(token)) return;
  const form = document.getElementById('discord-login-prepare');
  if (!form) return;
  form.elements.namedItem('token').value = token;
  // This only prepares an explicit confirmation. GET/prefetch never consumes the link.
  form.requestSubmit();
})();
