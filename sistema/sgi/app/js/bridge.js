/* Ponte SGI ↔ modelo HTML. Injetada no início do modelo (dentro da moldura isolada quadro.php).
 * - Captura e restaura os valores de todos os campos (input, select, textarea, .editable, [contenteditable]).
 * - Oculta os botões "guardar no navegador/exportar/imprimir" dos modelos originais: no SGI, guardar é sempre no servidor.
 * - Adapta o modelo a ecrãs de 390 px (tabelas com deslocamento horizontal, campos tocáveis).
 * - Gera a representação final (valores fixados, sem scripts).
 */
(function () {
  'use strict';
  var CFG = window.__SGI_CFG__ || {};
  var ORIGIN = CFG.origin;
  var NONCE = CFG.nonce;
  var MODE = CFG.mode || 'edit'; // edit | view | final
  var initialCount = null;

  function send(msg) {
    msg.nonce = NONCE;
    try { window.parent.postMessage(msg, ORIGIN); } catch (e) { /* sem pai */ }
  }

  // ---- Armazenamento do navegador desativado dentro dos modelos (origem opaca) ----
  function memStorage() {
    var m = {};
    return {
      getItem: function (k) { return Object.prototype.hasOwnProperty.call(m, k) ? m[k] : null; },
      setItem: function (k, v) { m[k] = String(v); },
      removeItem: function (k) { delete m[k]; },
      clear: function () { m = {}; },
      key: function (i) { return Object.keys(m)[i] || null; },
      get length() { return Object.keys(m).length; }
    };
  }
  try { Object.defineProperty(window, 'localStorage', { value: memStorage(), configurable: true }); } catch (e) {}
  try { Object.defineProperty(window, 'sessionStorage', { value: memStorage(), configurable: true }); } catch (e) {}
  // alertas dos modelos sobre "guardado no navegador" não são verdadeiros no SGI: suprimir os que falam em guardar
  var nativeAlert = window.alert;
  window.alert = function (m) {
    if (/guard|salv|carreg|navegador|localStorage|export|descarreg/i.test(String(m))) return;
    try { nativeAlert.call(window, m); } catch (e) {}
  };

  var HIDE_RE = /guardar|salvar|carregar|exportar|descarregar|download|imprimir|limpar|reiniciar|editar conte|desativar edi|enviar inqu|gerar relat|guardar pdf/i;
  var HIDE_IDS = ['editContentBtn', 'saveBtn', 'loadBtn', 'exportBtn', 'downloadBtn', 'printBtn', 'editStatus'];

  function isFieldInput(el) {
    if (el.tagName !== 'INPUT') return true;
    var t = (el.getAttribute('type') || 'text').toLowerCase();
    return ['button', 'submit', 'reset', 'hidden', 'file', 'image', 'password'].indexOf(t) < 0;
  }

  function isEditableContainer(el) {
    return el.tagName !== 'INPUT' && el.tagName !== 'SELECT' && el.tagName !== 'TEXTAREA';
  }

  function fieldEls(root) {
    root = root || document;
    var all = root.querySelectorAll('input,select,textarea,.editable,[contenteditable]');
    var out = [];
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (el.closest('[data-sgi-ui]')) continue;
      if (!isFieldInput(el)) continue;
      if (isEditableContainer(el)) {
        var ce = el.getAttribute('contenteditable');
        if (ce === 'false' && !el.classList.contains('editable')) continue;
        if (el.parentElement && el.parentElement.closest('.editable,[contenteditable="true"],[contenteditable=""]')) continue;
        if (el.querySelector('input,select,textarea')) continue;
      }
      out.push(el);
    }
    return out;
  }

  function getVal(el) {
    if (isEditableContainer(el)) return el.innerHTML;
    if (el.tagName === 'INPUT') {
      var t = (el.type || '').toLowerCase();
      if (t === 'checkbox' || t === 'radio') return el.checked ? '1' : '';
      return el.value;
    }
    if (el.tagName === 'SELECT' && el.multiple) {
      var v = [];
      for (var i = 0; i < el.options.length; i++) if (el.options[i].selected) v.push(el.options[i].value);
      return JSON.stringify(v);
    }
    return el.value;
  }

  function cleanHtml(html) {
    var t = document.createElement('template');
    t.innerHTML = String(html);
    var bad = t.content.querySelectorAll('script,iframe,object,embed,link,meta,base');
    for (var i = 0; i < bad.length; i++) bad[i].remove();
    var all = t.content.querySelectorAll('*');
    for (var j = 0; j < all.length; j++) {
      var attrs = all[j].attributes;
      for (var k = attrs.length - 1; k >= 0; k--) {
        var n = attrs[k].name.toLowerCase();
        var val = String(attrs[k].value).replace(/\s+/g, '').toLowerCase();
        if (n.indexOf('on') === 0 || ((n === 'href' || n === 'src') && val.indexOf('javascript:') === 0)) all[j].removeAttribute(attrs[k].name);
      }
    }
    return t.innerHTML;
  }

  function setVal(el, v) {
    if (v === undefined || v === null) return;
    if (isEditableContainer(el)) { el.innerHTML = cleanHtml(v); return; }
    if (el.tagName === 'INPUT') {
      var t = (el.type || '').toLowerCase();
      if (t === 'checkbox' || t === 'radio') { el.checked = v === '1' || v === 'true' || v === 'on'; return; }
      el.value = v;
      return;
    }
    if (el.tagName === 'SELECT' && el.multiple) {
      var arr = [];
      try { arr = JSON.parse(v); } catch (e) {}
      for (var i = 0; i < el.options.length; i++) el.options[i].selected = arr.indexOf(el.options[i].value) >= 0;
      return;
    }
    if (el.tagName === 'SELECT') {
      var found = false;
      for (var j = 0; j < el.options.length; j++) if (el.options[j].value === v) found = true;
      if (!found && v !== '') { var o = document.createElement('option'); o.value = v; o.textContent = v; el.appendChild(o); }
    }
    el.value = v;
  }

  function labelFor(el) {
    var txt = '';
    try { if (el.labels && el.labels.length) txt = el.labels[0].textContent; } catch (e) {}
    if (!txt) txt = el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.getAttribute('title') || '';
    if (!txt) {
      var td = el.closest('td,th');
      var table = el.closest('table');
      if (td && table) {
        var idx = Array.prototype.indexOf.call(td.parentElement.children, td);
        var head = table.querySelector('thead tr:last-child') || table.querySelector('tr');
        if (head && head.children[idx] && head !== td.parentElement) txt = head.children[idx].textContent;
        var rowHead = td.parentElement.children[0];
        if (!txt && rowHead && rowHead !== td) txt = rowHead.textContent;
      }
    }
    if (!txt) {
      var prev = el.previousElementSibling || (el.parentElement && el.parentElement.previousElementSibling);
      if (prev) txt = prev.textContent;
    }
    if (!txt) txt = el.getAttribute('name') || el.id || '';
    return String(txt).replace(/\s+/g, ' ').trim().slice(0, 80);
  }

  function collect() {
    var els = fieldEls();
    var fields = {}, labels = {}, required = [];
    for (var i = 0; i < els.length; i++) {
      var k = 'k' + i;
      fields[k] = getVal(els[i]);
      labels[k] = labelFor(els[i]) || ('Campo ' + (i + 1));
      if (els[i].required || els[i].getAttribute('aria-required') === 'true' || els[i].hasAttribute('data-sgi-obrigatorio')) required.push(k);
    }
    var out = { fields: fields, labels: labels, required: required, count: els.length };
    if (initialCount !== null && els.length !== initialCount) out.dom = snapshotBody();
    return out;
  }

  function snapshotBody() {
    var clone = document.body.cloneNode(true);
    bake(clone);
    var ui = clone.querySelectorAll('[data-sgi-ui],script');
    for (var i = 0; i < ui.length; i++) ui[i].remove();
    return clone.innerHTML;
  }

  /** Fixa os valores atuais como atributos no clone (mesma ordem de campos). */
  function bake(cloneRoot) {
    var src = fieldEls(document);
    var dst = fieldEls(cloneRoot);
    for (var i = 0; i < dst.length && i < src.length; i++) {
      var s = src[i], d = dst[i];
      if (isEditableContainer(d)) { d.innerHTML = cleanHtml(s.innerHTML); d.removeAttribute('contenteditable'); continue; }
      if (d.tagName === 'INPUT') {
        var t = (d.type || '').toLowerCase();
        if (t === 'checkbox' || t === 'radio') { if (s.checked) d.setAttribute('checked', 'checked'); else d.removeAttribute('checked'); }
        else d.setAttribute('value', s.value);
      } else if (d.tagName === 'TEXTAREA') {
        d.textContent = s.value;
      } else if (d.tagName === 'SELECT') {
        for (var j = 0; j < d.options.length; j++) {
          if (s.options[j] && s.options[j].selected) d.options[j].setAttribute('selected', 'selected'); else d.options[j].removeAttribute('selected');
        }
      }
    }
  }

  function finalHtml() {
    var clone = document.documentElement.cloneNode(true);
    var body = clone.querySelector('body');
    bake(body);
    var rm = clone.querySelectorAll('script,[data-sgi-ui],[data-sgi-oculto],noscript,iframe,object,embed');
    for (var i = 0; i < rm.length; i++) rm[i].remove();
    var fs = fieldEls(clone);
    for (var j = 0; j < fs.length; j++) {
      if (!isEditableContainer(fs[j])) fs[j].setAttribute('disabled', 'disabled');
    }
    var btns = clone.querySelectorAll('button');
    for (var b = 0; b < btns.length; b++) btns[b].remove();
    var all = clone.querySelectorAll('*');
    for (var a = 0; a < all.length; a++) {
      var attrs = all[a].attributes;
      for (var k = attrs.length - 1; k >= 0; k--) if (attrs[k].name.toLowerCase().indexOf('on') === 0) all[a].removeAttribute(attrs[k].name);
    }
    var st = document.createElement('style');
    st.textContent = MOBILE_CSS + 'input[disabled],select[disabled],textarea[disabled]{color:#0f172a;opacity:1;background:transparent;-webkit-text-fill-color:#0f172a}';
    var head = clone.querySelector('head');
    if (head) head.appendChild(st);
    return '<!DOCTYPE html>\n' + clone.outerHTML;
  }

  var MOBILE_CSS =
    'html{-webkit-text-size-adjust:100%}img,svg,video,canvas{max-width:100%;height:auto}' +
    '.sgi-tabela-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}' +
    '@media (max-width:760px){' +
    'body{padding:6px!important;margin:0!important;min-width:0!important}' +
    'body>*{max-width:100%!important;box-sizing:border-box}' +
    'input:not([type=checkbox]):not([type=radio]),select,textarea{font-size:16px!important;min-height:40px;max-width:100%}' +
    '[contenteditable="true"]{min-height:32px}' +
    'input[type=checkbox],input[type=radio]{width:22px;height:22px}' +
    'table input:not([type=checkbox]):not([type=radio]),table select,table textarea{min-width:110px}' +
    'pre{white-space:pre-wrap}' +
    '}';

  function prepare() {
    // estilo móvel
    var st = document.createElement('style');
    st.setAttribute('data-sgi-ui', '1');
    st.textContent = MOBILE_CSS + '.sgi-falta{outline:3px solid #dc2626!important;outline-offset:1px;background:#fef2f2!important}' +
      (MODE !== 'edit' ? '' : '.editable[contenteditable=true]{outline:1px dashed #94a3b8;outline-offset:2px}');
    (document.head || document.documentElement).appendChild(st);

    // tabelas largas: envolver num contentor com deslocamento horizontal
    var tables = document.querySelectorAll('table');
    for (var i = 0; i < tables.length; i++) {
      var t = tables[i];
      if (t.parentElement && t.parentElement.closest('table')) continue;
      var p = t.parentElement;
      var ov = p ? getComputedStyle(p).overflowX : '';
      if (ov === 'auto' || ov === 'scroll') continue;
      var w = document.createElement('div');
      w.className = 'sgi-tabela-scroll';
      p.insertBefore(w, t);
      w.appendChild(t);
    }
    // outros blocos mais largos do que o ecrã (ex.: min-width fixo)
    var vw = document.documentElement.clientWidth;
    var blocks = document.querySelectorAll('body *');
    for (var b = 0; b < blocks.length; b++) {
      var el = blocks[b];
      if (el.closest('.sgi-tabela-scroll') || el.tagName === 'TABLE') continue;
      var cs = getComputedStyle(el);
      if (cs.position === 'fixed') continue;
      if (parseFloat(cs.minWidth) > vw) el.style.minWidth = '0';
      if (parseFloat(cs.width) > vw && cs.display !== 'inline' && el.scrollWidth > vw + 4 && !el.querySelector('table')) {
        el.style.maxWidth = '100%';
        el.style.overflowX = 'auto';
      }
    }

    // ocultar controlos próprios dos modelos (guardar no navegador, exportar, imprimir…)
    var cands = document.querySelectorAll('button,a,input[type=button],input[type=submit],input[type=reset]');
    for (var c = 0; c < cands.length; c++) {
      var el2 = cands[c];
      var txt = (el2.textContent || el2.value || '') + ' ' + (el2.id || '');
      var isLink = el2.tagName === 'A';
      if ((!isLink && (HIDE_RE.test(txt) || HIDE_IDS.indexOf(el2.id) >= 0)) || (isLink && /download|descarreg|export/i.test(txt) && !el2.getAttribute('href'))) {
        el2.style.display = 'none';
        el2.setAttribute('data-sgi-oculto', '1');
      } else if (MODE !== 'edit' && !isLink) {
        el2.style.display = 'none';
        el2.setAttribute('data-sgi-oculto', '1');
      }
    }
    for (var h = 0; h < HIDE_IDS.length; h++) {
      var e3 = document.getElementById(HIDE_IDS[h]);
      if (e3) { e3.style.display = 'none'; e3.setAttribute('data-sgi-oculto', '1'); }
    }
    var tb = document.querySelectorAll('.print-btn,.edit-controls,.editor-toolbar,.toolbar-edicion,.edit-indicator');
    for (var x = 0; x < tb.length; x++) { tb[x].style.display = 'none'; tb[x].setAttribute('data-sgi-oculto', '1'); }

    // ligações para outros documentos do SGI
    document.addEventListener('click', function (ev) {
      var a = ev.target.closest && ev.target.closest('a[href]');
      if (!a) return;
      var href = a.getAttribute('href') || '';
      if (href.charAt(0) === '#') return;
      if (/^(https?:)?\/\//i.test(href) || /^data:|^blob:/.test(href)) {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
        return;
      }
      if (/\.html?(#.*)?$/i.test(href)) {
        ev.preventDefault();
        send({ t: 'link', file: decodeURIComponent(href.split('/').pop().split('#')[0]) });
      }
    }, true);
  }

  function applyMode() {
    var els = fieldEls();
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (isEditableContainer(el)) {
        el.setAttribute('contenteditable', MODE === 'edit' ? 'true' : 'false');
      } else if (MODE !== 'edit') {
        el.setAttribute('disabled', 'disabled');
      }
    }
  }

  var changeTimer = null;
  function onChange() {
    clearTimeout(changeTimer);
    changeTimer = setTimeout(function () { send({ t: 'changed' }); }, 400);
  }

  function restore(data) {
    if (!data || !data.fields) return;
    var els = fieldEls();
    if (data.dom && typeof data.count === 'number' && data.count !== els.length) {
      document.body.innerHTML = cleanHtml(data.dom);
      els = fieldEls();
    }
    for (var i = 0; i < els.length; i++) setVal(els[i], data.fields['k' + i]);
  }

  function init() {
    initialCount = fieldEls().length;
    prepare();
    restore(CFG.data);
    applyMode();
    document.addEventListener('input', onChange, true);
    document.addEventListener('change', onChange, true);
    var c = collect();
    send({ t: 'ready', count: c.count, labels: c.labels, required: c.required, title: document.title });
  }

  window.addEventListener('message', function (ev) {
    if (ev.origin !== ORIGIN || ev.source !== window.parent || !ev.data || ev.data.nonce !== NONCE) return;
    var m = ev.data;
    if (m.t === 'collect') send({ t: 'data', id: m.id, data: collect() });
    else if (m.t === 'snapshot') send({ t: 'snapshot', id: m.id, html: finalHtml(), data: collect() });
    else if (m.t === 'print') window.print();
    else if (m.t === 'mark') {
      var els = fieldEls();
      for (var i = 0; i < els.length; i++) els[i].classList.toggle('sgi-falta', (m.keys || []).indexOf('k' + i) >= 0);
      var first = document.querySelector('.sgi-falta');
      if (first) first.scrollIntoView({ block: 'center' });
    } else if (m.t === 'fill-test') {
      // usado apenas pelos testes automáticos de ida e volta
      var fs = fieldEls();
      for (var j = 0; j < fs.length; j++) {
        var el = fs[j];
        if (el.disabled || el.readOnly) continue;
        if (isEditableContainer(el)) { el.innerHTML = 'Texto ' + m.seed + ' #' + j; continue; }
        var t = (el.type || '').toLowerCase();
        if (t === 'checkbox' || t === 'radio') { if (t === 'checkbox') el.checked = (j % 2 === 0); continue; }
        if (el.tagName === 'SELECT') { if (el.options.length > 1) el.selectedIndex = el.options.length - 1; continue; }
        if (t === 'date') el.value = '2026-0' + (1 + (j % 9)) + '-1' + (j % 9);
        else if (t === 'number' || t === 'range') el.value = String(j % 10);
        else if (t === 'time') el.value = '08:' + (10 + (j % 49));
        else if (t === 'month') el.value = '2026-0' + (1 + (j % 9));
        else if (t === 'email') el.value = 'teste' + j + '@exemplo.ao';
        else if (t === 'color') el.value = '#123456';
        else if (t === 'tel') el.value = '9' + String(10000000 + j);
        else if (t === 'url') el.value = 'https://exemplo.ao/' + j;
        else el.value = 'V' + m.seed + '-' + j;
      }
      send({ t: 'filled', id: m.id, data: collect() });
    }
  });

  // Inicializar depois dos scripts do próprio modelo (alguns geram linhas/tabelas ao carregar).
  var started = false;
  function start() { if (started) return; started = true; setTimeout(init, 60); }
  if (document.readyState === 'complete') start();
  else { window.addEventListener('load', start); setTimeout(start, 8000); }
})();
