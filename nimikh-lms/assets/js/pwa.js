/* Registers the service worker and wires "Install the app" buttons. */
(function () {
  'use strict';
  var cfg = window.NimikhPwa;
  if (!cfg || !('serviceWorker' in navigator)) { return; }

  window.addEventListener('load', function () {
    navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope }).catch(function () { /* optional enhancement */ });
  });

  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    document.querySelectorAll('[data-nk-install]').forEach(function (b) { b.hidden = false; });
  });
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-nk-install]');
    if (!btn || !deferred) { return; }
    deferred.prompt();
    deferred.userChoice.finally(function () { deferred = null; btn.hidden = true; });
  });
  window.addEventListener('appinstalled', function () {
    document.querySelectorAll('[data-nk-install]').forEach(function (b) { b.hidden = true; });
  });
})();
