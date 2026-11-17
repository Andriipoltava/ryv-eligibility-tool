/**
 * Runs INSIDE the embedded page (/embed/{form}/). Reports the content height
 * to the parent window so the host's loader (embed.js) can size the iframe to
 * fit - no inner scrollbar, no fixed guess. The payload is only a number, so
 * it is posted with targetOrigin "*" (nothing sensitive to protect); the host
 * side is what validates who is talking.
 */
(function () {
  'use strict';

  if (window.parent === window) {
    return;
  }

  var last = 0;
  var scheduled = false;

  function measure() {
    var body = document.body;
    // scrollHeight (not offsetHeight) so absolutely-positioned overflow, such
    // as the open state dropdown, is included rather than clipped.
    var h = Math.max(body.scrollHeight, body.offsetHeight);

    // An open dropdown is absolutely positioned, so it can extend past the
    // content; count its bottom edge (+ a small gap) so the frame is tall
    // enough to show it instead of clipping it.
    var menus = document.querySelectorAll('.ryv-select2-menu');
    for (var i = 0; i < menus.length; i++) {
      var r = menus[i].getBoundingClientRect();
      if (r.height > 0) {
        h = Math.max(h, Math.ceil(r.bottom + (window.pageYOffset || 0)) + 16);
      }
    }

    return Math.ceil(h);
  }

  function send() {
    scheduled = false;
    var h = measure();
    if (h === last) {
      return;
    }
    last = h;
    window.parent.postMessage({ source: 'ryv-embed', type: 'resize', height: h }, '*');
  }

  function schedule() {
    if (!scheduled) {
      scheduled = true;
      window.requestAnimationFrame(send);
    }
  }

  // The host acknowledges once it is auto-sizing us; only then hide our own
  // scrollbar (a plain fixed-height iframe still needs to scroll).
  window.addEventListener('message', function (e) {
    if (e.source === window.parent && e.data && e.data.source === 'ryv-embed-host' && e.data.type === 'ack') {
      document.documentElement.style.overflow = 'hidden';
    }
  });

  if (window.ResizeObserver) {
    new ResizeObserver(schedule).observe(document.body);
  }
  new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, attributes: true });
  window.addEventListener('resize', schedule);
  window.addEventListener('load', schedule);
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(schedule);
  }
  schedule();
})();
