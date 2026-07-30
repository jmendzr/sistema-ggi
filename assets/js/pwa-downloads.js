/* pwa-downloads.js — extraído de layout_end.php (auditoría 2026-07). */

/* ── Descargas iOS y Android PWA ────────────────────────────────────────────
 *
 * PROBLEMA DIAGNOSTICADO:
 *   En iOS WKWebView (standalone PWA y Safari móvil), el atributo `download`
 *   se ignora para URLs de servidor. El navegador navega a la URL, recibe
 *   Content-Disposition: attachment, muestra Quick Look, y al cerrar
 *   Quick Look el WebView queda en estado inconsistente (posible reset del
 *   contexto de cookies), provocando pérdida de sesión.
 *
 * SOLUCIÓN (iOS — PWA y Safari):
 *   Interceptar el clic. Descargar con fetch() same-origin (cookie siempre
 *   enviada, sin navegación del WebView). Compartir con Web Share API o
 *   blob URL. El WebView nunca abandona la página → sesión intacta.
 *
 * Android PWA:
 *   window.open(_blank) abre Chrome externo donde attachment funciona nativo.
 * ─────────────────────────────────────────────────────────────────────────── */
(function () {
  /* Detecta iOS aunque Safari tenga "Sitio web para ordenador" activo.
     En ese modo el UA reporta "Macintosh" pero maxTouchPoints sigue siendo > 1. */
  var isIos     = /iP(hone|ad|od)/i.test(navigator.userAgent)
                  || (/Mac/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
  var isAndroid = !isIos && window.matchMedia
                  && window.matchMedia('(display-mode:standalone)').matches;

  /* ── Android PWA ── */
  if (isAndroid) {
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a[href*="descargar=1"]');
      if (!a) return;
      e.preventDefault();
      window.open(a.href, '_blank');
    }, true);
    return;
  }

  /* ── iOS (PWA + Safari) — fetch + Web Share / blob URL ── */
  if (!isIos) return;

  var LOG_EP = '/modules/ajax_pwa_log.php';

  /* Inyectar animación de spinner una sola vez */
  if (!document.getElementById('_ggi_dl_css')) {
    var st = document.createElement('style');
    st.id  = '_ggi_dl_css';
    st.textContent = '@keyframes _ggiSpin{to{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }

  /* Registra en consola + pwa_debug.log (fire-and-forget) */
  function ggiLog(step, detail) {
    var msg = detail ? (step + ' | ' + detail) : step;
    console.log('[GGI-DL]', msg);
    try {
      var fd = new FormData();
      fd.append('msg', msg);
      fetch(LOG_EP, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function(){});
    } catch(ignore) {}
  }

  function SPIN(txt) {
    return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none"'
      + ' stroke="currentColor" stroke-width="2.5" stroke-linecap="round"'
      + ' style="animation:_ggiSpin .7s linear infinite;vertical-align:-1px;margin-right:4px">'
      + '<path d="M12 2a10 10 0 0 1 10 10"/></svg>' + txt;
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href*="descargar=1"]');
    if (!a || a._ggiBusy) return;
    e.preventDefault();
    e.stopPropagation();

    a._ggiBusy            = true;
    var savedHTML         = a.innerHTML;
    var savedPointer      = a.style.pointerEvents;
    a.style.pointerEvents = 'none';
    var navigateAway      = false;

    /* ── PASO 1 ── */
    ggiLog('PASO_1_CLICK', 'url=' + a.href);
    a.innerHTML = SPIN('Descargando archivo…');

    /* ── PASO 2 ── */
    ggiLog('PASO_2_FETCH_INICIO', 'credentials=same-origin');

    fetch(a.href, { credentials: 'same-origin' })

      /* ── PASO 3 ── */
      .then(function (resp) {
        ggiLog('PASO_3_FETCH_RESPUESTA',
          'status=' + resp.status + ' ok=' + resp.ok + ' type=' + resp.type);

        if (!resp.ok) { ggiLog('PASO_3_ERROR', 'HTTP_' + resp.status); throw new Error('HTTP_' + resp.status); }

        var cd      = resp.headers.get('content-disposition') || '';
        var ct      = resp.headers.get('content-type')        || '';
        var fnMatch = cd.match(/filename="?([^";\r\n]+)"?/i);
        var fname   = fnMatch ? fnMatch[1].trim() : 'documento';
        ggiLog('PASO_3b_HEADERS', 'ct=' + ct + ' | cd=' + cd + ' | fname=' + fname);

        a.innerHTML = SPIN('Preparando archivo…');
        return resp.blob().then(function (blob) { return { blob: blob, fname: fname }; });
      })

      /* ── PASO 4 ── */
      .then(function (r) {
        ggiLog('PASO_4_BLOB', 'size=' + r.blob.size + ' | type=' + r.blob.type + ' | fname=' + r.fname);
        if (r.blob.size === 0) { ggiLog('PASO_4_ERROR', 'blob_vacio'); throw new Error('BLOB_VACIO'); }

        /* ── PASO 5 ── */
        var file = new File([r.blob], r.fname, { type: r.blob.type });
        ggiLog('PASO_5_FILE', 'name=' + file.name + ' size=' + file.size);

        /* ── PASO 6 ── */
        var shareOk = !!(navigator.canShare && navigator.canShare({ files: [file] }));
        ggiLog('PASO_6_CANSHARE',
          'share_fn=' + (typeof navigator.share) +
          ' | canShare_fn=' + (typeof navigator.canShare) +
          ' | result=' + shareOk);

        if (shareOk) {
          /* ── PASO 7 ── */
          ggiLog('PASO_7_SHARE_INICIO', 'title=' + r.fname);
          a.innerHTML = SPIN('Abriendo menú de compartir…');

          return navigator.share({ files: [file], title: r.fname })
            .then(function () {
              /* ── PASO 8 ── */
              ggiLog('PASO_8_SHARE_OK', 'completado');
            })
            .catch(function (shareErr) {
              /* ── PASO 9 ── */
              ggiLog('PASO_9_SHARE_ERROR', 'name=' + shareErr.name + ' | msg=' + shareErr.message);
              if (shareErr.name === 'AbortError') {
                ggiLog('PASO_9b_SHARE_CANCELADO', 'usuario_cerro_share_sheet');
                return;
              }
              ggiLog('PASO_9c_SHARE_FALLO_REAL', 'fallback_a_blob');
              _blobFallback(r.blob, r.fname);
            });
        }

        /* ── PASO 10 ── */
        ggiLog('PASO_10_BLOB_FALLBACK', 'canShare=false');
        _blobFallback(r.blob, r.fname);
      })

      .catch(function (err) {
        var msg = err && err.message ? err.message : String(err);
        if (msg === 'BLOB_VACIO') {
          a.innerHTML = SPIN('Error: archivo vacío');
          setTimeout(function () {
            a.innerHTML = savedHTML; a.style.pointerEvents = savedPointer; a._ggiBusy = false;
          }, 2500);
          return;
        }
        /* ── PASO 11 ── */
        ggiLog('PASO_11_FALLBACK_NAV', 'error=' + msg);
        navigateAway = true;
        a.innerHTML  = SPIN('Error al descargar…');
        setTimeout(function () { window.location.href = a.href; }, 900);
      })

      .finally(function () {
        if (!navigateAway) {
          a.innerHTML = savedHTML; a.style.pointerEvents = savedPointer; a._ggiBusy = false;
        }
      });

    function _blobFallback(blob, fname) {
      try {
        var blobUrl = URL.createObjectURL(blob);
        var tmp = document.createElement('a');
        tmp.href = blobUrl; tmp.download = fname;
        document.body.appendChild(tmp); tmp.click(); document.body.removeChild(tmp);
        ggiLog('PASO_10b_BLOB_CLICK', 'blobUrl_generado_y_clic_disparado');
        setTimeout(function () { URL.revokeObjectURL(blobUrl); }, 6000);
      } catch (blobErr) {
        ggiLog('PASO_10c_BLOB_ERROR', String(blobErr));
      }
    }
  }, true);
})();
