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
    return Math.ceil(Math.max(body.scrollHeight, body.offsetHeight));
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
