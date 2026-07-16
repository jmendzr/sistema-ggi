<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT dc.*, cl.ruc, cl.razon_social
    FROM documentos_cliente dc
    LEFT JOIN clientes cl ON cl.id = dc.cliente_id
    WHERE dc.id = ?
");
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) { header('Location: ' . APP_URL . '/modules/clientes/index.php'); exit; }

$ruta_fisica = rtrim(UPLOAD_PATH, '/') . '/' . $doc['ruta_archivo'];
$ruta_stream = APP_URL . '/modules/documentos/stream.php?id=' . $id;

if (!file_exists($ruta_fisica)) {
    header('Location: ' . APP_URL . '/modules/clientes/ver.php?id=' . $doc['cliente_id'] . '&doc_error=1');
    exit;
}

if (isset($_GET['descargar'])) {
    pwa_log('download_endpoint:START doc=' . $id);
    $ruc_safe    = preg_replace('/[^0-9]/',          '', $doc['ruc']           ?? '');
    $tipo_safe   = preg_replace('/[^A-Za-z0-9_\-]/', '_', strtolower($doc['tipo_documento'] ?? 'documento'));
    $ext_safe    = strtolower(pathinfo($doc['ruta_archivo'], PATHINFO_EXTENSION));
    $nombre_final = ($ruc_safe ? $ruc_safe . '_' : '') . $tipo_safe . ($ext_safe ? '.' . $ext_safe : '');
    // Cabeceras CORS para fetch() desde el mismo origen (iOS fetch+share)
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Content-Type: '               . ($doc['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $nombre_final . '"');
    header('Content-Length: '             . filesize($ruta_fisica));
    pwa_log('download_endpoint:HEADERS_SENT nombre=' . $nombre_final);
    readfile($ruta_fisica);
    pwa_log('download_endpoint:DONE');
    exit;
}

$es_pdf           = $doc['mime_type'] === 'application/pdf' || substr(strtolower($doc['nombre_archivo']), -4) === '.pdf';
$es_imagen        = in_array($doc['mime_type'], ['image/jpeg','image/png','image/webp']);

$_tipos_doc = [
    'ficha_ruc'       => 'Ficha RUC',
    'copia_literal'   => 'Copia Literal',
    'acta_alcance'    => 'Acta de Alcance',
    'voucher_inicial' => 'Voucher Inicial',
    'voucher_final'   => 'Voucher Final',
];
$_doc_label  = $_tipos_doc[$doc['tipo_documento'] ?? ''] ?? 'Documento';
$_back_url   = APP_URL . '/modules/clientes/ver.php?id=' . (int)$doc['cliente_id'];
$_back_label = sanitize($doc['razon_social'] ?? 'Empresa');

$page_title  = 'Ver Documento';
$active_menu = 'clientes';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';
?>

<style>
/* ══════════════════════════════════════════
   Document Viewer
   ══════════════════════════════════════════ */

/* ── Card ── */
.dv-card {
  background: var(--bg-card, #fff);
  border-radius: 12px;
  border: 1px solid var(--gris-borde);
  /* overflow:clip recorta visualmente igual que hidden pero NO crea
     scroll container, así position:sticky en hijos funciona correctamente */
  overflow: clip;
}

/* ── Header: nombre + acciones ── */
.dv-header {
  padding: 12px 18px;
  border-bottom: 1px solid var(--gris-borde);
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-card, #fff);
  /* Sticky: se ancla bajo la barra de la app al hacer scroll por el PDF.
     Funciona porque el padre usa overflow:clip (no crea scroll container). */
  position: sticky;
  top: var(--header-h, 60px);
  z-index: 40;
}
.dv-info  { flex: 1; min-width: 0; }
.dv-title {
  font-family: 'Inter', sans-serif;
  font-size: 13px; font-weight: 700;
  color: var(--tx, #1a1d2e);          /* neutro, no azul — no es un enlace */
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dv-meta  { font-size: 11px; color: var(--gris-texto); margin-top: 2px; }
.dv-actions { display: flex; gap: 7px; flex-shrink: 0; }

/* ── Toolbar: zoom + conteo de páginas (sticky dentro del stage) ── */
.dv-toolbar {
  position: sticky;
  top: 0;
  z-index: 20;
  padding: 7px 16px;
  border-bottom: 1px solid var(--gris-borde);
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-surface, #f8f9fb);
  backdrop-filter: blur(6px);
}
.dv-zoom-btn {
  width: 28px; height: 28px;
  border: 1px solid var(--gris-borde);
  border-radius: 6px;
  background: var(--bg-card, #fff);
  color: var(--tx);
  font-size: 15px; font-weight: 700; line-height: 1;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background .13s, border-color .13s;
  flex-shrink: 0;
}
.dv-zoom-btn:hover { background: var(--bg-hover, #f3f4f6); border-color: var(--azul); }
.dv-zoom-label { font-size: 12px; font-weight: 600; color: var(--tx2); min-width: 38px; text-align: center; }
.dv-pg-count   { font-size: 12px; color: var(--gris-texto); margin-left: auto; }

/* ── Barra de progreso de carga ── */
.dv-progress-bar {
  height: 2px;
  background: var(--azul, #1a3a8f);
  width: 0%;
  transition: width .3s ease;
  position: absolute;
  bottom: 0; left: 0;
}

/* ── PDF stage ── */
.dv-body  { padding: 0; }
.pdf-stage {
  background: #3d3d3d;
  overflow-x: auto;
  position: relative;
}
.pdf-canvas-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 20px 0 24px;
  gap: 0;
}

/* ── Cada página ── */
.pdf-page-wrap {
  position: relative;
  background:var(--bg-surface);
  box-shadow: 0 4px 20px rgba(0,0,0,.45);
  margin-bottom: 2px;
  line-height: 0;
  /* Transición suave al aparecer */
  animation: pageIn .2s ease both;
}
@keyframes pageIn {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}
.pdf-page-wrap canvas { display: block; }

/* ── Separador entre páginas ── */
.pdf-sep {
  width: 100%;
  padding: 10px 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  opacity: .6;
}
.pdf-sep-line  { flex: 1; max-width: 100px; height: 1px; background: rgba(255,255,255,.2); }
.pdf-sep-label { font-size: 10px; color: rgba(255,255,255,.5); font-weight: 500; letter-spacing: .5px; }

/* ── Indicador flotante de página actual ── */
.pdf-page-pill {
  position: sticky;
  bottom: 16px;
  left: 50%;
  transform: translateX(-50%);
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(0,0,0,.65);
  color: #fff;
  font-size: 12px; font-weight: 600;
  padding: 5px 14px;
  border-radius: 20px;
  backdrop-filter: blur(8px);
  pointer-events: none;
  opacity: 0;
  transition: opacity .25s;
  white-space: nowrap;
  z-index: 30;
  width: max-content;
  /* Centrado horizontal dentro del flex-column */
  align-self: center;
}
.pdf-page-pill.visible { opacity: 1; }

/* ── Loading ── */
.pdf-loading {
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 14px; min-height: 240px;
  color: rgba(255,255,255,.75);
  padding: 20px;
}
.pdf-spinner {
  width: 36px; height: 36px;
  border: 3px solid rgba(255,255,255,.15);
  border-top-color: rgba(255,255,255,.8);
  border-radius: 50%;
  animation: spin .75s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.pdf-loading-text  { font-size: 13px; font-weight: 500; }
.pdf-loading-pages { font-size: 11px; color: rgba(255,255,255,.45); }

/* ── Error ── */
.pdf-error {
  display: none; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 14px; min-height: 240px;
  padding: 32px 24px; text-align: center;
  color: rgba(255,255,255,.75);
}
.pdf-error-title { font-size: 14px; font-weight: 600; }
.pdf-error-sub   { font-size: 12px; color: rgba(255,255,255,.45); }

/* ── Imagen ── */
.img-preview { max-width: 100%; display: block; margin: 0 auto; padding: 24px; }

/* ══════════════════════════════════════════
   MÓVIL (≤ 768px)
   ══════════════════════════════════════════ */
@media (max-width: 768px) {
  /* Breadcrumb más compacto */
  .ggi-bc { margin-bottom: 8px; }

  /* Header compacto en móvil */
  .dv-header {
    padding: 9px 12px;
    gap: 8px;
  }
  .dv-title { font-size: 12px; }
  .dv-meta  { display: none; }

  /* Botón descarga: solo icono en móvil */
  .btn-dl-txt { display: none; }

  /* Toolbar sticky: sigue al header */
  .dv-toolbar {
    top: 0;           /* dentro del pdf-stage que es position:relative */
    padding: 6px 12px;
  }

  /* Stage sin padding extra */
  .pdf-canvas-wrap { padding: 12px 0 20px; }

  /* Páginas respetan el ancho de pantalla */
  .pdf-page-wrap canvas { max-width: calc(100vw - 24px) !important; }

  /* Pill siempre centrado en móvil */
  .pdf-page-pill { font-size: 11px; padding: 4px 12px; }

  /* Safe area PWA */
  .dv-card { margin-bottom: max(12px, env(safe-area-inset-bottom)); }
}

/* ══════════════════════════════════════════
   DARK MODE
   ══════════════════════════════════════════ */
:root[data-tema="oscuro"] .dv-card    { background: var(--bg-surface2); }
:root[data-tema="oscuro"] .dv-header  { background: var(--bg-surface2); }
:root[data-tema="oscuro"] .dv-toolbar { background: var(--bg-surface3, #1a2035); }
:root[data-tema="oscuro"] .dv-zoom-btn { background: var(--bg-surface2); border-color: var(--brd2, #2d3748); color: var(--tx); }
</style>

<?php
ggi_breadcrumb([
    ['label' => 'Empresas',   'href' => APP_URL . '/modules/clientes/index.php', 'ss' => 'ggi_ss_clientes'],
    ['label' => $_back_label, 'href' => $_back_url],
    ['label' => 'Documentos'],
    ['label' => $_doc_label],
]);
?>

<div class="dv-card">

  <!-- Header: nombre del archivo + acciones -->
  <div class="dv-header">
    <div class="dv-info">
      <div class="dv-title"><?= sanitize($doc['nombre_archivo']) ?></div>
      <div class="dv-meta">
        Versión <?= (int)$doc['version'] ?> &mdash;
        <?= date('d/m/Y H:i', strtotime($doc['uploaded_at'])) ?>
        <?= $doc['tamano_bytes'] ? '&mdash; ' . round($doc['tamano_bytes'] / 1024, 1) . ' KB' : '' ?>
      </div>
    </div>
    <div class="dv-actions">
      <a href="?id=<?= $id ?>&descargar=1" class="btn btn-primary btn-sm" download title="Descargar">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span class="btn-dl-txt">Descargar</span>
      </a>
    </div>
  </div>

  <!-- Cuerpo -->
  <div class="dv-body">

    <?php if ($es_pdf): ?>

      <!-- Toolbar: zoom + conteo (sticky, se muestra tras renderizar pág. 1) -->
      <div class="dv-toolbar" id="dv-toolbar" style="display:none; position:relative;">
        <button class="dv-zoom-btn" onclick="dvZoom(-1)" aria-label="Reducir zoom" title="Reducir">−</button>
        <span class="dv-zoom-label" id="dv-zoom-label">100%</span>
        <button class="dv-zoom-btn" onclick="dvZoom(+1)" aria-label="Ampliar zoom" title="Ampliar">+</button>
        <span class="dv-pg-count" id="dv-pg-count"></span>
        <!-- Barra de progreso de carga -->
        <div class="dv-progress-bar" id="dv-progress"></div>
      </div>

      <!-- Área de renderizado PDF -->
      <div class="pdf-stage" id="pdf-stage">
        <div class="pdf-canvas-wrap" id="pdf-canvas-wrap">

          <!-- Loading -->
          <div class="pdf-loading" id="pdf-loading">
            <div class="pdf-spinner"></div>
            <div>
              <div class="pdf-loading-text">Cargando documento…</div>
              <div class="pdf-loading-pages" id="pdf-loading-pages"></div>
            </div>
          </div>

          <!-- Error -->
          <div class="pdf-error" id="pdf-error">
            <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="rgba(255,255,255,.5)" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div>
              <div class="pdf-error-title">No se pudo cargar el documento</div>
              <div class="pdf-error-sub">Intenta descargar el archivo directamente.</div>
            </div>
            <a href="?id=<?= $id ?>&descargar=1" class="btn btn-primary btn-sm" download>
              <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Descargar PDF
            </a>
          </div>

        </div>

        <!-- Pill flotante: "Pág. X / Y" — aparece al hacer scroll -->
        <div class="pdf-page-pill" id="pdf-pill">Pág. 1 / 1</div>
      </div>

    <?php elseif ($es_imagen): ?>
      <img
        src="<?= htmlspecialchars($ruta_stream, ENT_QUOTES) ?>"
        alt="<?= sanitize($doc['nombre_archivo']) ?>"
        class="img-preview"
        loading="lazy"
      >
    <?php else: ?>
      <div style="padding:40px;text-align:center;color:var(--gris-texto);">
        <p style="margin-bottom:16px;">No se puede previsualizar este tipo de archivo.</p>
        <a href="?id=<?= $id ?>&descargar=1" class="btn btn-primary" download>Descargar archivo</a>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php if ($es_pdf): ?>
<script>
(function () {
  'use strict';

  var PDFJS_SRC  = <?= json_encode(APP_URL . '/assets/vendor/pdfjs/pdf.min.js') ?>;
  var WORKER_SRC = <?= json_encode(APP_URL . '/assets/vendor/pdfjs/pdf.worker.min.js') ?>;
  var PDF_URL    = <?= json_encode($ruta_stream) ?>;
  var ZOOM_STEPS = [0.5, 0.6, 0.75, 1.0, 1.25, 1.5, 2.0];

  var pdfDoc    = null;
  var baseScale = 1;
  var zoomIdx   = 3;    // 1.0 = fit-width
  var rendering = false;
  var totalPages = 0;
  var pillTimer  = null;

  var el = {
    wrap:         document.getElementById('pdf-canvas-wrap'),
    loading:      document.getElementById('pdf-loading'),
    loadingPages: document.getElementById('pdf-loading-pages'),
    error:        document.getElementById('pdf-error'),
    toolbar:      document.getElementById('dv-toolbar'),
    zoomLbl:      document.getElementById('dv-zoom-label'),
    pgCount:      document.getElementById('dv-pg-count'),
    progress:     document.getElementById('dv-progress'),
    pill:         document.getElementById('pdf-pill'),
    stage:        document.getElementById('pdf-stage'),
  };

  /* ── Carga de script ── */
  function loadScript(src) {
    return new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = src; s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }

  /* ── Ancho disponible para las páginas ── */
  function stageWidth() {
    return el.stage ? el.stage.clientWidth - 2 : window.innerWidth - 26;
  }

  /* ── Error ── */
  function showError() {
    el.loading.style.display = 'none';
    el.error.style.display   = 'flex';
    el.toolbar.style.display = 'none';
  }

  /* ── Progreso de carga ── */
  function setProgress(done, total) {
    if (!el.progress || !total) return;
    el.progress.style.width = Math.round((done / total) * 100) + '%';
    if (el.loadingPages) {
      el.loadingPages.textContent = done < total
        ? 'Cargando página ' + done + ' de ' + total + '…'
        : '';
    }
  }

  /* ── Pill flotante: muestra "Pág. X / Y" y desaparece tras 1.5s ── */
  function showPill(pageNum) {
    if (!el.pill) return;
    el.pill.textContent = 'Pág. ' + pageNum + ' / ' + totalPages;
    el.pill.classList.add('visible');
    clearTimeout(pillTimer);
    pillTimer = setTimeout(function () {
      el.pill.classList.remove('visible');
    }, 1500);
  }

  /* ── IntersectionObserver: detecta qué página es la más visible ── */
  var observer = null;
  function setupObserver() {
    if (!window.IntersectionObserver) return;
    observer = new IntersectionObserver(function (entries) {
      var best = null, bestRatio = 0;
      entries.forEach(function (e) {
        if (e.intersectionRatio > bestRatio) {
          bestRatio = e.intersectionRatio;
          best = e.target;
        }
      });
      if (best) {
        var pn = parseInt(best.getAttribute('data-page'), 10);
        if (!isNaN(pn)) showPill(pn);
      }
    }, { root: null, threshold: [0.1, 0.3, 0.5, 0.7, 1.0] });
  }

  /* ── Renderiza una página y devuelve el wrapper div ── */
  function renderPageEl(page, pageNum, scale) {
    return new Promise(function (resolve) {
      var vp  = page.getViewport({ scale: scale });
      var dpr = window.devicePixelRatio || 1;

      var wrap   = document.createElement('div');
      wrap.className = 'pdf-page-wrap';
      wrap.setAttribute('data-page', pageNum);

      var canvas = document.createElement('canvas');
      canvas.width  = Math.round(vp.width  * dpr);
      canvas.height = Math.round(vp.height * dpr);
      canvas.style.width  = vp.width  + 'px';
      canvas.style.height = vp.height + 'px';

      var ctx = canvas.getContext('2d');
      ctx.scale(dpr, dpr);

      page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
        wrap.appendChild(canvas);

        /* Separador entre páginas */
        if (pageNum < totalPages) {
          var sep = document.createElement('div');
          sep.className = 'pdf-sep';
          sep.innerHTML =
            '<div class="pdf-sep-line"></div>' +
            '<span class="pdf-sep-label">pág. ' + pageNum + ' / ' + totalPages + '</span>' +
            '<div class="pdf-sep-line"></div>';
          wrap.appendChild(sep);
        }

        resolve(wrap);
      });
    });
  }

  /* ── Renderiza todas las páginas ── */
  async function renderAll(scale) {
    if (!pdfDoc || rendering) return;
    rendering = true;

    /* Limpiar páginas anteriores (sin tocar loading/error) */
    Array.from(el.wrap.children).forEach(function (n) {
      if (n.id !== 'pdf-loading' && n.id !== 'pdf-error') n.remove();
    });
    el.loading.style.display = 'flex';
    el.error.style.display   = 'none';
    if (el.progress) el.progress.style.width = '0%';

    /* Desconectar observer anterior */
    if (observer) observer.disconnect();
    setupObserver();

    try {
      for (var i = 1; i <= totalPages; i++) {
        var page   = await pdfDoc.getPage(i);
        var pageEl = await renderPageEl(page, i, scale);
        el.wrap.appendChild(pageEl);

        /* Observar para el pill de página */
        if (observer) observer.observe(pageEl);

        setProgress(i, totalPages);

        /* Tras primera página: mostrar toolbar y ocultar loading */
        if (i === 1) {
          el.loading.style.display = 'none';
          el.toolbar.style.display = 'flex';
          updateZoomLabel();
          showPill(1);
        }
      }
    } catch (err) {
      showError();
    } finally {
      rendering = false;
      /* Ocultar barra de progreso */
      setTimeout(function () {
        if (el.progress) el.progress.style.width = '0%';
      }, 600);
    }
  }

  /* ── Zoom ── */
  function currentScale() { return baseScale * ZOOM_STEPS[zoomIdx]; }
  function updateZoomLabel() {
    if (el.zoomLbl) el.zoomLbl.textContent = Math.round(ZOOM_STEPS[zoomIdx] * 100) + '%';
  }

  window.dvZoom = function (dir) {
    var next = zoomIdx + dir;
    if (next < 0 || next >= ZOOM_STEPS.length) return;
    zoomIdx = next;
    updateZoomLabel();
    renderAll(currentScale());
  };

  /* ── Re-renderizar al cambiar orientación / ancho ── */
  var resizeTimer;
  window.addEventListener('resize', function () {
    if (!pdfDoc) return;
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      pdfDoc.getPage(1).then(function (p) {
        var vp = p.getViewport({ scale: 1 });
        baseScale = stageWidth() / vp.width;
        renderAll(currentScale());
      });
    }, 250);
  });

  /* ── Arranque ── */
  async function boot() {
    try {
      await loadScript(PDFJS_SRC);
      pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER_SRC;

      var task = pdfjsLib.getDocument({ url: PDF_URL, withCredentials: true });
      pdfDoc = await task.promise;
      totalPages = pdfDoc.numPages;

      el.pgCount.textContent = totalPages === 1 ? '1 página' : totalPages + ' páginas';

      /* Escala base = fit-width a partir de la primera página */
      var page1 = await pdfDoc.getPage(1);
      var vp1   = page1.getViewport({ scale: 1 });
      baseScale = stageWidth() / vp1.width;

      await renderAll(currentScale());

    } catch (err) {
      showError();
    }
  }

  boot();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
