<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/certificados/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT c.*, cl.razon_social, cl.codigo_interno, cl.ruc,
           n.codigo AS norma_codigo, n.nombre AS norma_nombre,
           cc.nombre AS casa_nombre
    FROM certificados c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN normas_iso n ON n.id = c.norma_iso_id
    LEFT JOIN casas_certificadoras cc ON cc.id = c.casa_certificadora_id
    WHERE c.id = ?
");
$stmt->execute([$id]);
$cert = $stmt->fetch();
if (!$cert) { header('Location: ' . APP_URL . '/modules/certificados/index.php'); exit; }

// Auditorías
$auds = $pdo->prepare("SELECT * FROM auditorias WHERE certificado_id = ? ORDER BY fecha_programada");
$auds->execute([$id]);
$auditorias = $auds->fetchAll();

// Envíos
$envs = $pdo->prepare("
    SELECT e.*, ag.nombre AS agencia, es.nombre AS estado_nombre, es.color_hex
    FROM envios e
    JOIN envios_certificados ec ON ec.envio_id = e.id
    JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    JOIN estados_envio es ON es.id = e.estado_envio_id
    WHERE ec.certificado_id = ?
    ORDER BY e.created_at DESC
");
$envs->execute([$id]);
$envios = $envs->fetchAll();

$nuevo   = isset($_GET['nuevo']);
$editado = isset($_GET['editado']);
$from = $_GET['from'] ?? 'listado';
if ($from === 'dashboard') {
    $back_url = APP_URL . '/modules/dashboard/index.php';
} else {
    $back_url = APP_URL . '/modules/certificados/index.php';
}

$page_title  = 'Detalle de Certificado';
$active_menu = 'certificados';
$user        = auth_user();
require_once __DIR__ . '/../../templates/layout.php';

$dias_venc = $cert['fecha_vencimiento'] ? dias_hasta($cert['fecha_vencimiento']) : null;
?>
<style>
  .cert-header { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); padding:24px 28px; margin-bottom:20px; }
  .cert-title { font-family:'Inter',sans-serif; font-size:20px; font-weight:700; color:var(--gris-dark); margin-bottom:6px; }
  .cert-meta { display:flex; gap:16px; flex-wrap:wrap; margin-top:10px; }
  .meta-item { font-size:12px; color:var(--gris-texto); display:flex; align-items:center; gap:4px; }
  .meta-item strong { color:var(--gris-dark); }
  /* badge-*: globales en layout_end.php (dark mode incluido) */
  .info-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:20px; }
  .info-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); padding:18px 20px; }
  .info-card-label { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
  .info-card-value { font-size:20px; font-weight:700; font-family:'Inter',sans-serif; color:var(--gris-dark); }
  .info-card-sub { font-size:12px; color:var(--gris-texto); margin-top:2px; }
  .panel { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; margin-bottom:20px; }
  .panel-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--gris-borde); }
  .panel-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--gris-dark); }
  .mini-table { width:100%; border-collapse:collapse; }
  .mini-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; padding:10px 16px; background:var(--bg-surface2); border-bottom:1px solid var(--gris-borde); text-align:left; }
  .mini-table td { padding:12px 16px; font-size:13px; border-bottom:1px solid var(--bg-surface2); }
  .mini-table tr:last-child td { border-bottom:none; }
  .empty-mini { padding:24px; text-align:center; color:var(--gris-texto); font-size:13px; }
  .alert-ok { background:var(--s-ok-bg); border:1px solid #b7e4c0; border-left:4px solid var(--verde); padding:12px 16px; border-radius:8px; font-size:13px; color:#1a6630; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  @media (max-width:768px) {
    .info-grid { grid-template-columns:1fr 1fr; }
    .cert-header { padding:16px; }
  }
  @media (max-width:480px) {
    .info-grid { grid-template-columns:1fr; }
  }

  @media (max-width: 768px) {
    .panel { border-radius:10px; }
    .panel-header { flex-wrap:wrap; gap:8px; }
    .info-grid { grid-template-columns:1fr !important; }
    .cert-meta { flex-direction:column; gap:6px; }
  }
  .vsz-btn {
    padding:4px 10px; font-size:11px; font-weight:700;
    background:var(--gris-card); color:var(--gris-texto);
    border:1.5px solid var(--gris-borde); border-radius:7px;
    cursor:pointer; transition:all 0.15s; line-height:1.4;
  }
  .vsz-btn:hover { border-color:var(--azul); color:var(--azul); }
  .vsz-btn.vsz-active { background:var(--azul); color:#fff; border-color:var(--azul); }
</style>

<script>
ggiReady(() => {
  <?php if ($nuevo || $editado): ?>
  <?php if ($nuevo): ?>
  showToast('Certificado registrado exitosamente. Auditorías creadas automáticamente.', 'ok');
  <?php else: ?>
  showToast('Cambios guardados exitosamente.', 'ok');
  <?php endif; ?>
  history.replaceState({}, '', window.location.pathname + '?id=<?= $id ?>');
  <?php endif; ?>
});
</script>

<?php
$_cert_crumb_label = $cert['norma_codigo'] . ($cert['numero_certificado'] ? ' — '.$cert['numero_certificado'] : '');
ggi_breadcrumb([
    ['label' => 'Empresas',       'href' => APP_URL.'/modules/clientes/index.php',    'ss' => 'ggi_ss_clientes'],
    ['label' => sanitize($cert['razon_social']), 'href' => APP_URL.'/modules/clientes/ver.php?id='.(int)$cert['cliente_id']],
    ['label' => 'Certificados',   'href' => APP_URL.'/modules/certificados/index.php', 'ss' => 'ggi_ss_certificados'],
    ['label' => sanitize($_cert_crumb_label)],
]);
?>

<!-- Header del certificado -->
<div class="cert-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
        <strong style="font-size:13px;background:var(--s-info-bg);color:#475569;border:1px solid #e2e8f0;padding:3px 10px;border-radius:100px;"><?= sanitize($cert['norma_codigo']) ?></strong>
        <?= badge_estado_certificado($cert['estado']) ?>
      </div>
      <div class="cert-title" style="font-size:16px;"><?= sanitize($cert['razon_social']) ?></div>
      <div style="font-size:13px;color:var(--gris-texto);margin-top:4px;"><?= sanitize($cert['norma_nombre']) ?></div>
      <div class="cert-meta">
        <div class="meta-item"><strong>Casa certif.:</strong> <?= sanitize($cert['casa_nombre']) ?></div>
        <?php if ($cert['numero_certificado']): ?>
        <div class="meta-item"><strong>N°:</strong> <?= sanitize($cert['numero_certificado']) ?></div>
        <?php endif; ?>
        <div class="meta-item"><strong>Cliente:</strong> <?= sanitize($cert['ruc']) ?></div>
      </div>
      <?php if ($cert['alcance']): ?>
      <div style="font-size:13px;color:var(--gris-texto);margin-top:8px;"><strong>Alcance:</strong> <?= sanitize($cert['alcance']) ?></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= APP_URL ?>/modules/certificados/editar.php?id=<?= $id ?>&from=detalle" class="btn btn-outline btn-sm">Editar</a>
    </div>
  </div>
</div>

<!-- Métricas de fechas -->
<div class="info-grid">
  <div class="info-card">
    <div class="info-card-label">Fecha de emisión</div>
    <div class="info-card-value" style="font-size:16px;"><?= $cert['fecha_emision'] ? formato_fecha($cert['fecha_emision']) : '—' ?></div>
  </div>
  <div class="info-card">
    <div class="info-card-label">Vencimiento</div>
    <div class="info-card-value" style="font-size:16px;"><?= $cert['fecha_vencimiento'] ? formato_fecha($cert['fecha_vencimiento']) : '—' ?></div>
    <div class="info-card-sub">
      <?php if ($dias_venc === null): ?>
        <span style="color:var(--gris-texto);font-size:12px;">Sin fecha asignada</span>
      <?php elseif ($dias_venc > 0): ?>
        <span style="color:<?= $dias_venc <= 30 ? '#dc2626' : ($dias_venc <= 90 ? 'var(--s-warn-tx)' : '#229133') ?>; font-weight:600;"><?= $dias_venc ?> días restantes</span>
      <?php else: ?>
        <span style="color:#dc2626;font-weight:600;">Vencido hace <?= abs($dias_venc) ?> días</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="info-card">
    <div class="info-card-label">Auditorías programadas</div>
    <div class="info-card-value"><?= count($auditorias) ?></div>
    <div class="info-card-sub">seguimientos registrados</div>
  </div>
</div>

<?php
// Servir vía stream.php autenticado (evita dependencia de .htaccess y problemas de iframe)
$url_es     = !empty($cert['certificado_archivo'])    ? APP_URL . '/modules/certificados/stream.php?id=' . $id . '&lang=es'    : null;
$url_en     = !empty($cert['certificado_archivo_en']) ? APP_URL . '/modules/certificados/stream.php?id=' . $id . '&lang=en'    : null;
$dl_es      = $url_es ? $url_es . '&descargar=1' : null;
$dl_en      = $url_en ? $url_en . '&descargar=1' : null;
$nombre_es  = !empty($cert['certificado_archivo'])    ? basename($cert['certificado_archivo'])    : null;
$nombre_en  = !empty($cert['certificado_archivo_en']) ? basename($cert['certificado_archivo_en']) : null;
$tiene_ambos    = $url_es && $url_en;
$url_inicial    = $url_es ?: $url_en;
$dl_inicial     = $dl_es  ?: $dl_en;
$nombre_inicial = $nombre_es ?: $nombre_en;
?>
<?php if ($url_es || $url_en): ?>
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header">
    <div class="panel-title">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--verde)"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Certificado adjunto
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <?php if ($tiene_ambos): ?>
      <div style="display:flex;border:1.5px solid var(--gris-borde);border-radius:8px;overflow:hidden;">
        <button id="btn-es" onclick="switchCert('es')"
          style="padding:6px 14px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--azul);color:#fff;transition:all 0.15s;">
          Español
        </button>
        <button id="btn-en" onclick="switchCert('en')"
          style="padding:6px 14px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--bg-surface);color:var(--gris-texto);transition:all 0.15s;">
          Inglés
        </button>
      </div>
      <?php endif; ?>
      <a id="btn-descargar" href="<?= $dl_inicial ?>" download="<?= sanitize($nombre_inicial) ?>" target="_blank" class="btn btn-primary btn-sm">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Descargar
      </a>
      <a href="<?= APP_URL ?>/modules/certificados/editar.php?id=<?= $id ?>&from=detalle" class="btn btn-outline btn-sm">Actualizar</a>
    </div>
  </div>
  <!-- Visor: abre el certificado en el lightbox global (pdf.js: sin barra nativa, zoom y scroll en móvil) -->
  <div style="border-top:1px solid var(--gris-borde);padding:22px 18px;text-align:center;">
    <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="var(--azul)" stroke-width="1.4" style="opacity:.45;margin:0 auto 12px;display:block;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    <button type="button" id="cert-ver-btn" onclick="verCertificado()" class="btn btn-primary" style="min-width:180px;justify-content:center;gap:8px;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      Ver certificado
    </button>
    <p style="font-size:11.5px;color:var(--gris-texto);margin:10px 0 0;">Se abre en un visor dentro de la misma página · zoom y scroll incluidos.</p>
  </div>
</div>

<script>
var urls = {
  es: <?= json_encode($url_es) ?>,
  en: <?= json_encode($url_en) ?>
};
var dlUrls = {
  es: <?= json_encode($dl_es) ?>,
  en: <?= json_encode($dl_en) ?>
};
var nombres = {
  es: <?= json_encode($nombre_es) ?>,
  en: <?= json_encode($nombre_en) ?>
};
var currentLang = urls.es ? 'es' : 'en';

function switchCert(lang) {
  if (!urls[lang]) return;
  currentLang = lang;
  var dl = document.getElementById('btn-descargar');
  if (dl) { dl.href = dlUrls[lang] || urls[lang]; dl.download = nombres[lang] || ''; }
  ['es','en'].forEach(l => {
    const btn = document.getElementById('btn-' + l);
    if (!btn) return;
    if (l === lang) { btn.style.background = 'var(--azul)'; btn.style.color = '#fff'; }
    else { btn.style.background = 'var(--bg-surface)'; btn.style.color = 'var(--gris-texto)'; }
  });
}

// Abre el certificado en el lightbox global (pdf.js: sin barra nativa, zoom y scroll)
function verCertificado() {
  if (!urls[currentLang]) return;
  if (window.ggiLightbox) {
    window.ggiLightbox.open(urls[currentLang], {
      title:    nombres[currentLang] || 'Certificado',
      download: dlUrls[currentLang] || urls[currentLang],
      filename: nombres[currentLang] || ''
    });
  } else {
    window.open(urls[currentLang], '_blank');
  }
}
</script>
<?php endif; ?>

<!-- Auditorías -->
<?php
$resultado_map = [
    'pendiente'         => ['label' => 'Pendiente',         'color' => 'var(--tx2)'],
    'aprobado'          => ['label' => 'Aprobado',          'color' => '#10B981'],
    'con_observaciones' => ['label' => 'Con observaciones', 'color' => '#F59E0B'],
    'no_conforme'       => ['label' => 'No conforme',       'color' => '#EF4444'],
    'reprogramado'      => ['label' => 'Reprogramado',      'color' => '#8B5CF6'],
];
$tipo_map = [
    'seguimiento_1' => 'Seguimiento 1',
    'seguimiento_2' => 'Seguimiento 2',
    'renovacion'    => 'Renovación',
];
?>
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header">
    <div class="panel-title">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--verde)"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      Auditorías
    </div>
  </div>
  <?php if (empty($auditorias)): ?>
  <div class="empty-mini">Sin auditorías registradas.</div>
  <?php else: ?>
  <table class="mini-table">
    <thead><tr><th scope="col">Tipo</th><th scope="col">Fecha programada</th><th scope="col">Fecha realizada</th><th scope="col">Auditor</th><th scope="col">Resultado</th><th scope="col"></th></tr></thead>
    <tbody>
      <?php foreach ($auditorias as $a):
        $res = $resultado_map[$a['resultado'] ?? 'pendiente'] ?? $resultado_map['pendiente'];
      ?>
      <tr>
        <td><?= sanitize($tipo_map[$a['tipo']] ?? $a['tipo']) ?></td>
        <td><?= formato_fecha($a['fecha_programada']) ?></td>
        <td><?= $a['fecha_realizada'] ? formato_fecha($a['fecha_realizada']) : '<span style="color:var(--gris-texto)">—</span>' ?></td>
        <td><?= $a['auditor_externo'] ? sanitize($a['auditor_externo']) : '<span style="color:var(--gris-texto)">—</span>' ?></td>
        <td>
          <span style="background:<?= $res['color'] ?>22;color:<?= $res['color'] ?>;border:1px solid <?= $res['color'] ?>55;padding:2px 9px;border-radius:100px;font-size:11px;font-weight:600;white-space:nowrap;">
            <?= $res['label'] ?>
          </span>
        </td>
        <td>
          <a href="<?= APP_URL ?>/modules/auditorias/ver.php?id=<?= $a['id'] ?>" style="font-size:12px;color:var(--azul);text-decoration:none;font-weight:600;">Ver →</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Envíos -->
<div class="panel">
  <div class="panel-header">
    <div class="panel-title">Envíos de este certificado</div>
    <?php if (has_role('gerente_general','gerente_operaciones','gestor_logistico','delivery')): ?>
    <a href="<?= APP_URL ?>/modules/envios/crear.php?certificado_id=<?= $id ?>&cliente_id=<?= $cert['cliente_id'] ?>&from=ficha" class="btn btn-outline btn-sm">+ Registrar envío</a>
    <?php endif; ?>
  </div>
  <?php if (empty($envios)): ?>
  <div class="empty-mini">Sin envíos registrados para este certificado.</div>
  <?php else: ?>
  <table class="mini-table">
    <thead><tr><th scope="col">Agencia</th><th scope="col">Estado</th><th scope="col">Enviado</th><th scope="col">Recibido</th><th scope="col">Código</th></tr></thead>
    <tbody>
      <?php foreach ($envios as $e): ?>
      <tr>
        <td><?= sanitize($e['agencia']) ?></td>
        <td><span style="background:<?= $e['color_hex'] ?>22;color:<?= $e['color_hex'] ?>;border:1px solid <?= $e['color_hex'] ?>55;padding:2px 9px;border-radius:100px;font-size:11px;font-weight:600;"><?= sanitize($e['estado_nombre']) ?></span></td>
        <td><?= formato_fecha($e['fecha_envio']) ?></td>
        <td><?= $e['fecha_recibido'] ? formato_fecha($e['fecha_recibido']) : '<span style="color:var(--gris-texto)">Pendiente</span>' ?></td>
        <td style="font-family:monospace;font-size:12px;"><?= $e['codigo_envio'] ? sanitize($e['codigo_envio']) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
