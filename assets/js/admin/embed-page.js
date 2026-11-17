/* Embed & Share admin page: builds the share link + iframe code, drives the live preview. */
(function ($) {
  'use strict';

  var cfg = window.RYVEmbedAdmin;
  var root = document.getElementById('ryv-embed-app');
  if (!cfg || !root) return;

  var $id = function (id) { return document.getElementById(id); };
  var els = {
    form: $id('ryv-embed-form'), layout: $id('ryv-embed-layout'), title: $id('ryv-embed-title'),
    description: $id('ryv-embed-description'), restart: $id('ryv-embed-restart'), pad: $id('ryv-embed-pad'),
    fit: $id('ryv-embed-fit'), align: $id('ryv-embed-align'), look: $id('ryv-embed-look'), steps: $id('ryv-embed-steps'), showNote: $id('ryv-embed-show-note'), note: $id('ryv-embed-note'), titleText: $id('ryv-embed-title-text'), descText: $id('ryv-embed-desc-text'),
    labels: { label_start_over: $id('ryv-embed-label-start-over'), label_continue: $id('ryv-embed-label-continue'), label_submit: $id('ryv-embed-label-submit'), label_placeholder: $id('ryv-embed-label-placeholder') }, heightPx: $id('ryv-embed-height-px'), link: $id('ryv-embed-link'),
    open: $id('ryv-embed-open'), state: $id('ryv-embed-state'), stateField: $id('ryv-embed-state-field'), code: $id('ryv-embed-code'), preview: $id('ryv-embed-preview')
  };
  var colors = {};
  var timer = 0;

  cfg.forms.forEach(function (f, i) {
    var o = document.createElement('option');
    o.value = String(i);
    o.textContent = f.label;
    els.form.appendChild(o);
  });

  function fillStates() {
    var form = cfg.forms[parseInt(els.form.value, 10)];
    els.state.innerHTML = '';
    var all = document.createElement('option');
    all.value = '';
    all.textContent = cfg.i18n.allStates;
    els.state.appendChild(all);
    (form.states || []).forEach(function (st) {
      var o = document.createElement('option');
      o.value = st.slug;
      o.textContent = st.label;
      els.state.appendChild(o);
    });
    els.stateField.style.display = (form.states || []).length ? '' : 'none';
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function heightMode() {
    return root.querySelector('input[name="ryv-embed-height"]:checked').value;
  }

  function buildUrl() {
    var form = cfg.forms[parseInt(els.form.value, 10)];
    var p = [];
    if (els.state.value) p.push('state=' + encodeURIComponent(els.state.value));
    var plain = els.look.value === 'plain';
    if (plain) p.push('look=plain');
    if (els.layout.value) p.push('layout=' + els.layout.value);
    if (!els.title.checked) p.push('title=0');
    if (!els.description.checked) p.push('description=0');
    if (!els.restart.checked) p.push('restart=0');
    if (!els.pad.checked) p.push('pad=0');
    if (plain && els.fit.value === 'full') p.push('fit=full');
    if (plain && els.align.value === 'center') p.push('align=center');
    var nt = els.note.value.trim();
    if (!plain && !els.steps.checked) p.push('steps=0');
    if (!plain) {
      if (!els.showNote.checked || !nt) p.push('note=0');
      else if (nt !== els.note.getAttribute('data-default')) p.push('note=' + encodeURIComponent(nt));
    }
    var tt = els.titleText.value.trim(), dt = els.descText.value.trim();
    if (tt) p.push('title_text=' + encodeURIComponent(tt));
    if (dt) p.push('description_text=' + encodeURIComponent(dt));
    Object.keys(els.labels).forEach(function (k) {
      var v = els.labels[k].value.trim();
      if (v) p.push(k + '=' + encodeURIComponent(v));
    });
    ['primary', 'secondary', 'text', 'bg'].forEach(function (k) {
      var v = (colors[k] || '').trim();
      if (/^#?[0-9a-f]{3,8}$/i.test(v)) p.push(k + '=' + encodeURIComponent(v.replace('#', '')));
    });
    if (!p.length) return form.url;
    return form.url + (form.url.indexOf('?') > -1 ? '&' : '?') + p.join('&');
  }

  function syncLookFields() {
    var plain = els.look.value === 'plain';
    Array.prototype.forEach.call(document.querySelectorAll('.ryv-embed-plain-only'), function (n) { n.style.display = plain ? '' : 'none'; });
    els.note.parentNode.style.opacity = '';
    Array.prototype.forEach.call(document.querySelectorAll('.ryv-embed-site-only'), function (n) { n.style.display = plain ? 'none' : ''; });
    els.note.disabled = plain || !els.showNote.checked;
  }

  function update() {
    syncLookFields();
    var form = cfg.forms[parseInt(els.form.value, 10)];
    var url = buildUrl();
    var auto = heightMode() === 'auto';
    var h = parseInt(els.heightPx.value, 10) || 700;

    els.heightPx.style.display = auto ? 'none' : 'block';
    els.link.value = url;
    els.open.href = url;

    var code = '<iframe src="' + esc(url) + '"\n' +
      '  title="' + esc(form.title) + '"\n' +
      '  width="100%" height="' + (auto ? 700 : h) + '"\n' +
      '  style="border:0;width:100%;max-width:100%;"\n' +
      '  loading="lazy" referrerpolicy="strict-origin-when-cross-origin"' + (auto ? '\n  data-ryv-embed' : '') + '></iframe>';
    if (auto) code += '\n<script src="' + esc(cfg.loaderUrl) + '" async></script>';
    els.code.value = code;

    clearTimeout(timer);
    timer = setTimeout(function () {
      if (els.preview.getAttribute('src') !== url) els.preview.setAttribute('src', url);
    }, 350);
  }

  $('.ryv-embed-color').wpColorPicker({
    change: function (e, ui) { colors[$(e.target).data('param')] = ui.color.toString(); update(); },
    clear: function (e) { colors[$(e.target).data('param') || $(e.target).closest('.wp-picker-container').find('.ryv-embed-color').data('param')] = ''; setTimeout(update, 0); }
  });

  function fillTextPlaceholders() {
    var f = cfg.forms[parseInt(els.form.value, 10)] || {};
    els.titleText.placeholder = f.title || '';
    els.descText.placeholder = f.desc || '';
  }
  els.form.addEventListener('change', fillTextPlaceholders);
  fillTextPlaceholders();
  els.form.addEventListener('change', fillStates);
  root.addEventListener('input', update);
  root.addEventListener('change', update);

  root.querySelectorAll('.ryv-embed-devices .button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      root.querySelectorAll('.ryv-embed-devices .button').forEach(function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      els.preview.style.maxWidth = btn.getAttribute('data-width');
    });
  });

  root.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var src = $id(btn.getAttribute('data-copy'));
      var done = function () {
        var old = btn.textContent;
        btn.textContent = cfg.i18n.copied;
        setTimeout(function () { btn.textContent = old; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(src.value).then(done, function () { src.select(); document.execCommand('copy'); done(); });
      } else {
        src.select(); document.execCommand('copy'); done();
      }
    });
  });

  fillStates();
  update();
})(jQuery);
