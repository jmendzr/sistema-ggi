/* pdf-viewer.js — visor/lightbox de PDF, extraído de layout_end.php (auditoría 2026-07). */

(function() {
  var lb, imgEl, frameEl, pdfEl, bodyEl, zoomBtn, titleEl, dlEl, loadEl, _lastFocus = null;
  var _curUrl = '', _curKind = '';   // para el botón imprimir (image | pdf | iframe)
  var PDFJS_SRC    = window.APP_URL + '/assets/vendor/pdfjs/pdf.min.js';
  var PDFJS_WORKER = window.APP_URL + '/assets/vendor/pdfjs/pdf.worker.min.js';
  var _pdfDoc = null, _pdfZoom = false, _pdfToken = 0, _pdfLibPromise = null;
  function refs() {
    lb = document.getElementById('ggi-lightbox');
    if (!lb) return false;
    imgEl   = lb.querySelector('.ggi-lightbox-img');
    frameEl = lb.querySelector('.ggi-lightbox-frame');
    pdfEl   = lb.querySelector('.ggi-lightbox-pdf');
    bodyEl  = lb.querySelector('.ggi-lightbox-body');
    zoomBtn = lb.querySelector('[data-zoom]');
    titleEl = lb.querySelector('.ggi-lightbox-title');
    dlEl    = lb.querySelector('[data-download]');
    loadEl  = lb.querySelector('.ggi-lightbox-loading');
    return true;
  }
  function loadPdfLib() {
    if (window.pdfjsLib) return Promise.resolve();
    if (_pdfLibPromise) return _pdfLibPromise;
    _pdfLibPromise = new Promise(function(res, rej) {
      var s = document.createElement('script');
      s.src = PDFJS_SRC;
      s.onload = function() { try { pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER; } catch(e) {} res(); };
      s.onerror = rej;
      document.head.appendChild(s);
    });
    return _pdfLibPromise;
  }
  function _withTimeout(promise, ms) {
    return Promise.race([ promise, new Promise(function (r) { setTimeout(function () { r('__timeout'); }, ms); }) ]);
  }
  function _diag(t) { /* diagnóstico retirado tras validar en iOS */ }
  async function renderPdfPages(token) {
    if (!_pdfDoc) return;
    pdfEl.innerHTML = '';
    var n = _pdfDoc.numPages;
    var bodyW = (bodyEl.clientWidth || window.innerWidth || 800) - 16;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);  // tope 2: un canvas enorme (dpr 3) cuelga en iOS
    var p1 = await _withTimeout(_pdfDoc.getPage(1), 6000);
    if (token !== _pdfToken) return;
    if (!p1 || p1 === '__timeout' || !p1.getViewport) return;
    var vp1   = p1.getViewport({ scale: 1 });
    var scale = bodyW / vp1.width;
    var vpBase = p1.getViewport({ scale: scale });
    // FASE 1: crear YA N páginas (envoltura + canvas) con dims de la pág.1 → aparecen todas
    // las hojas y se puede desplazar de inmediato, aunque el contenido tarde en pintar.
    var pages = [];
    for (var i = 0; i < n; i++) {
      var wrap = document.createElement('div');
      wrap.className = 'ggi-pdf-page';
      wrap.style.width  = vpBase.width + 'px';
      wrap.style.height = vpBase.height + 'px';
      var cv = document.createElement('canvas');
      cv.width  = Math.round(vpBase.width * dpr);
      cv.height = Math.round(vpBase.height * dpr);
      cv.style.width  = vpBase.width + 'px';
      cv.style.height = vpBase.height + 'px';
      wrap.appendChild(cv);
      pdfEl.appendChild(wrap);
      pages.push({ wrap: wrap, cv: cv });
    }
    loadEl.hidden = true;
    // FASE 2: pintar el canvas y montar la CAPA DE TEXTO (seleccionable/copiable) encima.
    for (var k = 1; k <= n; k++) {
      if (token !== _pdfToken) return;
      var page = (k === 1) ? p1 : await _withTimeout(_pdfDoc.getPage(k), 6000);
      if (token !== _pdfToken) return;
      if (!page || page === '__timeout' || !page.getViewport) continue;
      var vp = page.getViewport({ scale: scale });
      var pg = pages[k - 1];
      pg.wrap.style.width  = vp.width + 'px';
      pg.wrap.style.height = vp.height + 'px';
      pg.cv.width  = Math.round(vp.width * dpr);
      pg.cv.height = Math.round(vp.height * dpr);
      pg.cv.style.width  = vp.width + 'px';
      pg.cv.style.height = vp.height + 'px';
      var ctx = pg.cv.getContext('2d'); ctx.scale(dpr, dpr);
      // Pintar el canvas SIN await: su promesa puede colgar (bug pdf.js 3.11) pero el canvas se pinta.
      try { page.render({ canvasContext: ctx, viewport: vp }).promise.catch(function () {}); } catch (e) {}
      // CAPA DE TEXTO (seleccionable/copiable): getTextContent SÍ resuelve; renderTextLayer se dispara
      // sin await (su promesa también puede colgar, pero monta los spans igual).
      if (token !== _pdfToken) return;
      try {
        var tc = await _withTimeout(page.getTextContent(), 6000);
        if (tc && tc !== '__timeout' && tc.items && tc.items.length && pdfjsLib.renderTextLayer) {
          var tl = document.createElement('div');
          tl.className = 'textLayer';
          tl.style.width  = vp.width + 'px';
          tl.style.height = vp.height + 'px';
          tl.style.setProperty('--scale-factor', scale);
          pg.wrap.appendChild(tl);
          try { pdfjsLib.renderTextLayer({ textContentSource: tc, container: tl, viewport: vp, textDivs: [] }).promise.catch(function () {}); } catch (e) {}
        }
      } catch (e) {}
    }
  }
  async function renderPdfData(data, url, token) {
    pdfEl.hidden = false; pdfEl.innerHTML = '';
    bodyEl.classList.add('is-pdf'); lb.classList.add('lb-pdf');
    loadEl.hidden = false;
    _diag('v4 cargando pdf.js…');
    try {
      await loadPdfLib();
      if (token !== _pdfToken) return;
      _diag('v4 getDocument…');
      var doc = await _withTimeout(pdfjsLib.getDocument({ data: data }).promise, 12000);
      if (token !== _pdfToken) { try { doc.destroy(); } catch(e) {} return; }
      if (!doc || doc === '__timeout' || !doc.numPages) throw new Error('getDocument-timeout');
      _pdfDoc = doc;
      await renderPdfPages(token);
      loadEl.hidden = true;
    } catch (e) {
      // pdf.js falló → fallback a iframe (con la URL original)
      if (token !== _pdfToken) return;
      _diag('v4 pdf.js FALLO→iframe: ' + (e && e.message ? e.message : e));
      pdfEl.hidden = true; pdfEl.innerHTML = '';
      bodyEl.classList.remove('is-pdf'); lb.classList.remove('lb-pdf');
      loadEl.hidden = true;
      frameEl.hidden = false; frameEl.src = url;
    }
  }
  function open(url, opts) {
    opts = opts || {};
    if (!lb && !refs()) return;
    if (!url) return;
    _curUrl = url; _curKind = '';
    _lastFocus = document.activeElement;
    titleEl.textContent = opts.title || '';
    dlEl.setAttribute('href', opts.download || url);
    if (opts.filename) dlEl.setAttribute('download', opts.filename); else dlEl.setAttribute('download', '');
    // reset estado
    _pdfToken++; _pdfDoc = null; _pdfZoom = false;
    imgEl.hidden = true; imgEl.classList.remove('zoomed'); imgEl.removeAttribute('src');
    bodyEl.classList.remove('is-zoomed', 'is-pdf'); lb.classList.remove('lb-pdf');
    pdfEl.hidden = true; pdfEl.innerHTML = '';
    frameEl.hidden = true; frameEl.removeAttribute('src');
    if (zoomBtn) zoomBtn.hidden = true;   // se muestra si es imagen o PDF
    loadEl.hidden = false;
    lb.hidden = false;
    lockScroll();
    imgEl.alt = opts.title || 'Vista previa';
    _diag('v4 detectando…');
    // NO usar img.onload/onerror para detectar tipo: iOS Safari carga PDFs DENTRO de un <img>
    // (dispara onload y muestra solo la 1ª página). Obtenemos el content-type real por fetch
    // y a los PDF los pasamos a pdf.js DESDE LOS BYTES (así iOS no los trata como imagen).
    var token = _pdfToken;
    fetch(url, { credentials: 'same-origin' }).then(function (resp) {
      var ct = (resp.headers.get('content-type') || '').toLowerCase();
      return resp.blob().then(function (blob) { return { ct: ct || (blob.type || ''), blob: blob }; });
    }).then(function (res) {
      if (token !== _pdfToken) return;
      var ct = (res.ct || res.blob.type || '').toLowerCase();
      _diag('v4 tipo:' + (ct || '?'));
      if (ct.indexOf('image/') === 0) {
        _curKind = 'image';
        var ou = URL.createObjectURL(res.blob);
        imgEl.onload  = function () { loadEl.hidden = true; imgEl.hidden = false; };
        imgEl.onerror = function () { loadEl.hidden = true; _diag('v4 img ERROR'); };
        imgEl.src = ou;
      } else {
        _curKind = 'pdf';
        res.blob.arrayBuffer().then(function (buf) {
          if (token !== _pdfToken) return;
          renderPdfData(buf, url, token);
        });
      }
    }).catch(function (e) {
      if (token !== _pdfToken) return;
      _curKind = 'iframe';
      _diag('v4 fetch FALLO:' + (e && e.message ? e.message : e) + '→iframe');
      loadEl.hidden = true; frameEl.hidden = false; frameEl.src = url;
    });
    var closeBtn = lb.querySelector('.ggi-lightbox-close');
    if (closeBtn) closeBtn.focus();
  }
  function close() {
    if (!lb || lb.hidden) return;
    lb.hidden = true;
    _pdfToken++; _pdfDoc = null;
    imgEl.removeAttribute('src'); frameEl.removeAttribute('src');
    pdfEl.innerHTML = ''; pdfEl.hidden = true;
    bodyEl.classList.remove('is-pdf', 'is-zoomed'); lb.classList.remove('lb-pdf');
    unlockScroll();
    if (_lastFocus && _lastFocus.focus) _lastFocus.focus();
    _lastFocus = null;
  }
  // El scroll lo hace de forma nativa el propio overlay (.lb-pdf con overflow:auto +
  // -webkit-overflow-scrolling:touch), que es lo fiable en iOS. Solo bloqueamos el scroll
  // de la página de fondo mientras el modal está abierto, sin tocar overflow de <html>.
  function _blockBgScroll(e) {
    // permitir el gesto dentro del overlay (que es el scroller); bloquear fuera
    if (lb && lb.contains(e.target)) return;
    if (e.cancelable) e.preventDefault();
  }
  function lockScroll() {
    document.addEventListener('touchmove', _blockBgScroll, { passive: false });
  }
  function unlockScroll() {
    document.removeEventListener('touchmove', _blockBgScroll, { passive: false });
  }
  // Imprimir el archivo actual (imagen o PDF) mediante un iframe oculto.
  function printCurrent() {
    if (!_curUrl) return;
    var old = document.getElementById('ggi-print-frame');
    if (old) { try { old.parentNode.removeChild(old); } catch (_) {} }
    var f = document.createElement('iframe');
    f.id = 'ggi-print-frame';
    f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
    document.body.appendChild(f);
    var doPrint = function () {
      try { f.contentWindow.focus(); f.contentWindow.print(); }
      catch (_) { window.open(_curUrl, '_blank'); }  // fallback (iOS/PWA que bloquee print)
    };
    if (_curKind === 'image') {
      var doc = f.contentWindow.document;
      doc.open();
      doc.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Imprimir</title>'
        + '<style>@page{margin:10mm}html,body{margin:0;height:100%}'
        + 'img{max-width:100%;max-height:100vh;display:block;margin:0 auto}</style></head>'
        + '<body><img src="' + _curUrl.replace(/"/g, '&quot;') + '"></body></html>');
      doc.close();
      var img = doc.querySelector('img');
      if (img) { img.onload = function () { setTimeout(doPrint, 60); }; }
      else setTimeout(doPrint, 300);
    } else {
      // PDF u otro documento: cargar el archivo directo en el iframe
      f.onload = function () { setTimeout(doPrint, 120); };
      f.src = _curUrl;
    }
  }

  window.ggiLightbox = { open: open, close: close, print: printCurrent };

  // Apertura por delegación: cualquier [data-ggi-preview] (link o botón)
  document.addEventListener('click', function(e) {
    var trg = e.target.closest('[data-ggi-preview]');
    if (trg) {
      // data-src (archivo crudo) tiene prioridad; href queda como fallback sin-JS
      var url = trg.getAttribute('data-src') || trg.getAttribute('href');
      if (url && url !== '#') {
        e.preventDefault();
        open(url, { title: trg.getAttribute('data-title') || trg.getAttribute('title') || '', download: trg.getAttribute('data-download-url') || url });
      }
      return;
    }
    if (!lb || lb.hidden) return;
    if (e.target.closest('[data-print]'))    { e.preventDefault(); printCurrent(); return; }
    if (e.target.closest('[data-download]')) return;      // dejar descargar nativo
    if (e.target.closest('[data-close]'))    { close(); return; }
  });

  // Teclado: Escape cierra; Tab atrapado dentro del diálogo
  document.addEventListener('keydown', function(e) {
    if (!lb || lb.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'Tab') {
      var f = lb.querySelectorAll('button, a[href], iframe');
      f = Array.prototype.filter.call(f, function(el){ return el.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
})();
