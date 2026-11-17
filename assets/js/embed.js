/**
 * Restore Your Vote - embed loader (host side).
 *
 * Optional. Include once on a page that embeds the eligibility tool in an
 * <iframe data-ryv-embed ...>; the iframe then grows/shrinks to fit the tool
 * instead of showing its own scrollbar. Without this script the iframe still
 * works at whatever fixed height it was given.
 *
 * Only messages that come from one of these iframes' own window AND from that
 * iframe's own origin are honored; the height is clamped to a sane range.
 */
(function () {
  'use strict';

  if (window.__ryvEmbedLoader) {
    return;
  }
  window.__ryvEmbedLoader = true;

  var MIN = 160;
  var MAX = 20000;

  function originOf(frame) {
    try {
      return new URL(frame.getAttribute('src'), window.location.href).origin;
    } catch (err) {
      return null;
    }
  }

  window.addEventListener('message', function (e) {
    var d = e.data;
    if (!d || d.source !== 'ryv-embed' || d.type !== 'resize') {
      return;
    }

    var frames = document.querySelectorAll('iframe[data-ryv-embed]');
    for (var i = 0; i < frames.length; i++) {
      var frame = frames[i];
      if (frame.contentWindow !== e.source || e.origin !== originOf(frame)) {
        continue;
      }
      var h = Number(d.height);
      if (!isFinite(h)) {
        return;
      }
      frame.style.height = Math.min(MAX, Math.max(MIN, Math.ceil(h))) + 'px';
      e.source.postMessage({ source: 'ryv-embed-host', type: 'ack' }, e.origin);
      return;
    }
  });
})();
