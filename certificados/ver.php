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
    JOIN casas_certificadoras cc ON cc.id = c.casa_certificadora_id
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

$dias_venc = dias_hasta($cert['fecha_vencimiento']);
?>
<style>
  .cert-header { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); padding:24px 28px; margin-bottom:20px; }
  .cert-title { font-family:'Outfit',sans-serif; font-size:20px; font-weight:700; color:var(--azul); margin-bottom:6px; }
  .cert-meta { display:flex; gap:16px; flex-wrap:wrap; margin-top:10px; }
  .meta-item { font-size:12px; color:var(--gris-texto); display:flex; align-items:center; gap:4px; }
  .meta-item strong { color:var(--gris-dark); }
  /* badge-*: globales en layout_end.php (dark mode incluido) */
  .info-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:20px; }
  .info-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); padding:18px 20px; }
  .info-card-label { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
  .info-card-value { font-size:20px; font-weight:700; font-family:'Outfit',sans-serif; color:var(--azul); }
  .info-card-sub { font-size:12px; color:var(--gris-texto); margin-top:2px; }
  .panel { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; margin-bottom:20px; }
  .panel-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--gris-borde); }
  .panel-title { font-family:'Outfit',sans-serif; font-size:14px; font-weight:600; color:var(--azul); }
  .mini-table { width:100%; border-collapse:collapse; }
  .mini-table th { font-size:11px; font-weight:600; color:var(--gris-texto); text-transform:uppercase; padding:10px 16px; background:#fafbfe; border-bottom:1px solid var(--gris-borde); text-align:left; }
  .mini-table td { padding:12px 16px; font-size:13px; border-bottom:1px solid #f0f2f8; }
  .mini-table tr:last-child td { border-bottom:none; }
  .empty-mini { padding:24px; text-align:center; color:var(--gris-texto); font-size:13px; }
  .alert-ok { background:#e8f7eb; border:1px solid #b7e4c0; border-left:4px solid var(--verde); padding:12px 16px; border-radius:8px; font-size:13px; color:#1a6630; margin-bottom:16px; display:flex; align-items:center; gap:8px; }
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
    #cert-viewer { height:60vh !important; }
    .info-grid { grid-template-columns:1fr !important; }
    .cert-meta { flex-direction:column; gap:6px; }
  }
</style>

<!-- Header del certificado -->
<div class="cert-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
        <strong style="font-size:13px;background:var(--azul-light);color:var(--azul);padding:3px 10px;border-radius:100px;"><?= sanitize($cert['norma_codigo']) ?></strong>
        <?= badge_estado_certificado($cert['estado']) ?>
      </div>
      <div class="cert-title" style="font-size:16px;"><?= sanitize($cert['razon_social']) ?></div>
      <div style="font-size:13px;color:var(--gris-texto);margin-top:4px;"><?= sanitize($cert['norma_nombre']) ?></div>
      <div class="cert-meta">
        <div class="meta-item"><strong>Casa certif.:</strong> <?= sanitize($cert['casa_nombre']) ?></div>
        <?php if ($cert['numero_certificado']): ?>
        <div class="meta-item"><strong>N°:</strong> <?= sanitize($cert['numero_certificado']) ?></div>
        <?php endif; ?>
        <div class="meta-item"><strong>Empresa:</strong> <?= sanitize($cert['ruc']) ?></div>
      </div>
      <?php if ($cert['alcance']): ?>
      <div style="font-size:13px;color:var(--gris-texto);margin-top:8px;"><strong>Alcance:</strong> <?= sanitize($cert['alcance']) ?></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= APP_URL ?>/modules/certificados/editar.php?id=<?= $id ?>&from=detalle" class="btn btn-outline btn-sm">Editar</a>
      <?php if ($from === 'auditoria'): ?>
      <a href="<?= APP_URL ?>/modules/auditorias/index.php" class="btn btn-outline btn-sm">← Volver</a>
      <?php else: ?>
      <a href="<?= $back_url ?>" class="btn btn-outline btn-sm">← Volver</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Métricas de fechas -->
<div class="info-grid">
  <div class="info-card">
    <div class="info-card-label">Fecha de emisión</div>
    <div class="info-card-value" style="font-size:16px;"><?= formato_fecha($cert['fecha_emision']) ?></div>
  </div>
  <div class="info-card">
    <div class="info-card-label">Vencimiento</div>
    <div class="info-card-value" style="font-size:16px;"><?= formato_fecha($cert['fecha_vencimiento']) ?></div>
    <div class="info-card-sub">
      <?php if ($dias_venc > 0): ?>
        <span style="color:<?= $dias_venc <= 30 ? '#dc2626' : ($dias_venc <= 90 ? '#b45309' : '#229133') ?>; font-weight:600;"><?= $dias_venc ?> días restantes</span>
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
$url_es = !empty($cert['certificado_archivo'])    ? rtrim(UPLOAD_URL,'/') . '/' . $cert['certificado_archivo']    : null;
$url_en = !empty($cert['certificado_archivo_en']) ? rtrim(UPLOAD_URL,'/') . '/' . $cert['certificado_archivo_en'] : null;
$nombre_es = $url_es ? basename($cert['certificado_archivo'])    : null;
$nombre_en = $url_en ? basename($cert['certificado_archivo_en']) : null;
$tiene_ambos = $url_es && $url_en;
$url_inicial = $url_es ?: $url_en;
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
          🇵🇪 Español
        </button>
        <button id="btn-en" onclick="switchCert('en')"
          style="padding:6px 14px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:#fff;color:var(--gris-texto);transition:all 0.15s;">
          🇬🇧 Inglés
        </button>
      </div>
      <?php endif; ?>
      <a id="btn-descargar" href="<?= $url_inicial ?>" download class="btn btn-primary btn-sm">
        <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Descargar
      </a>
      <a href="<?= APP_URL ?>/modules/certificados/editar.php?id=<?= $id ?>&from=detalle" class="btn btn-outline btn-sm">Actualizar</a>
    </div>
  </div>
  <div style="border-top:1px solid var(--gris-borde);">
    <!-- Visor PDF: iframe en desktop, enlace directo en móvil -->
    <div id="cert-viewer-wrap">
      <iframe id="cert-viewer" src="<?= $url_inicial ?>"
        style="width:100%;height:75vh;min-height:400px;border:none;display:block;"
        title="<?= sanitize($nombre_inicial) ?>">
      </iframe>
    </div>
    <div id="cert-mobile-msg" style="display:none;padding:20px;text-align:center;">
      <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.4;margin-bottom:8px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <p style="font-size:13px;color:var(--gris-texto);margin-bottom:12px;">Los visores PDF pueden no funcionar en móvil. Abre el archivo directamente:</p>
      <a id="cert-mobile-link" href="<?= $url_inicial ?>" target="_blank" class="btn btn-primary btn-sm">Abrir certificado</a>
    </div>
    <script>
    // En móvil sustituir iframe por botón de apertura directa
    if (window.innerWidth < 768) {
      document.getElementById('cert-viewer-wrap').style.display = 'none';
      document.getElementById('cert-mobile-msg').style.display = 'block';
    }
    </script>
  </div>
</div>

<script>
const urls = {
  es: <?= json_encode($url_es) ?>,
  en: <?= json_encode($url_en) ?>
};
const nombres = {
  es: <?= json_encode($nombre_es) ?>,
  en: <?= json_encode($nombre_en) ?>
};
function switchCert(lang) {
  if (!urls[lang]) return;
  document.getElementById('cert-viewer').src = urls[lang];
  document.getElementById('btn-descargar').href = urls[lang];
  document.getElementById('btn-descargar').download = nombres[lang];
  const mobileLink = document.getElementById('cert-mobile-link');
  if (mobileLink) mobileLink.href = urls[lang];

  // Actualizar estilo de botones
  ['es','en'].forEach(l => {
    const btn = document.getElementById('btn-' + l);
    if (!btn) return;
    if (l === lang) {
      btn.style.background = 'var(--azul)';
      btn.style.color = '#fff';
    } else {
      btn.style.background = '#fff';
      btn.style.color = 'var(--gris-texto)';
    }
  });
}
</script>
<?php endif; ?>

<!-- Auditorías -->
<?php
$resultado_map = [
    'pendiente'         => ['label' => 'Pendiente',         'color' => '#6B7280'],
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
    <thead><tr><th>Tipo</th><th>Fecha programada</th><th>Fecha realizada</th><th>Auditor</th><th>Resultado</th><th></th></tr></thead>
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
    <a href="<?= APP_URL ?>/modules/envios/crear.php?certificado_id=<?= $id ?>&cliente_id=<?= $cert['cliente_id'] ?>&from=ficha" class="btn btn-outline btn-sm">+ Registrar envío</a>
  </div>
  <?php if (empty($envios)): ?>
  <div class="empty-mini">Sin envíos registrados para este certificado.</div>
  <?php else: ?>
  <table class="mini-table">
    <thead><tr><th>Agencia</th><th>Estado</th><th>Enviado</th><th>Recibido</th><th>Código</th></tr></thead>
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
<?php if ($nuevo || $editado): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  <?php if ($nuevo): ?>
  showToast('Certificado registrado exitosamente. Auditorías creadas automáticamente.', 'ok');
  <?php endif; ?>
  <?php if ($editado): ?>
  showToast('Cambios guardados exitosamente.', 'ok');
  <?php endif; ?>
});
</script>
<?php endif; ?>
