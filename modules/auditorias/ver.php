<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/auditorias/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT a.*,
           cl.razon_social, cl.codigo_interno, cl.ruc, cl.id AS cliente_id,
           n.codigo AS norma_codigo,
           c.id AS cert_id, c.numero_certificado, c.fecha_vencimiento,
           c.casa_certificadora_id AS cert_casa_id,
           cc_cert.nombre AS cert_casa_nombre,
           cc_aud.nombre AS casa_auditoria_nombre
    FROM auditorias a
    JOIN certificados c   ON c.id  = a.certificado_id
    JOIN clientes cl      ON cl.id = c.cliente_id
    JOIN normas_iso n     ON n.id  = c.norma_iso_id
    LEFT JOIN casas_certificadoras cc_cert ON cc_cert.id = c.casa_certificadora_id
    LEFT JOIN casas_certificadoras cc_aud  ON cc_aud.id  = a.casa_certificadora_id
    WHERE a.id = ?
");
$stmt->execute([$id]);
$aud = $stmt->fetch();
if (!$aud) { header('Location: ' . APP_URL . '/modules/auditorias/index.php'); exit; }

$casas = $pdo->query("SELECT id, nombre FROM casas_certificadoras WHERE activo=1 ORDER BY nombre")->fetchAll();
$errors = [];
$user   = auth_user();

// Upload dir
$upload_dir = '/home/groupglo/domains/sistema.groupglobaliso.com/public_html/documentaciones_clientes/auditorias/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'casa_certificadora_id' => (int)($_POST['casa_certificadora_id'] ?? 0) ?: null,
        'auditor_externo'       => trim($_POST['auditor_externo'] ?? ''),
        'fecha_realizada'       => $_POST['fecha_realizada'] ?? '',
        'fecha_reprogramada'    => $_POST['fecha_reprogramada'] ?? '',
        'resultado'             => $_POST['resultado'] ?? 'pendiente',
        'observaciones'         => trim($_POST['observaciones'] ?? ''),
    ];

    $resultados_validos = ['pendiente','aprobado','con_observaciones','no_conforme','reprogramado'];
    if (!in_array($data['resultado'], $resultados_validos)) $errors['resultado'] = 'Resultado inválido.';
    if ($data['resultado'] !== 'pendiente' && empty($data['fecha_realizada']) && $data['resultado'] !== 'reprogramado') {
        $errors['fecha_realizada'] = 'Ingresa la fecha en que se realizó la auditoría.';
    }
    if ($data['resultado'] === 'reprogramado' && empty($data['fecha_reprogramada'])) {
        $errors['fecha_reprogramada'] = 'Ingresa la nueva fecha programada.';
    }

    // Subir informe PDF
    $informe_archivo = $aud['informe_archivo'];
    if (!empty($_FILES['informe']['name'])) {
        $ext = strtolower(pathinfo($_FILES['informe']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $errors['informe'] = 'Solo se permiten archivos PDF.';
        } elseif ($_FILES['informe']['size'] > 10 * 1024 * 1024) {
            $errors['informe'] = 'El archivo no debe superar 10MB.';
        } else {
            $filename = 'auditoria_' . $id . '_' . time() . '.pdf';
            if (move_uploaded_file($_FILES['informe']['tmp_name'], $upload_dir . $filename)) {
                // Borrar archivo anterior si existe
                if ($aud['informe_archivo'] && file_exists($upload_dir . $aud['informe_archivo'])) {
                    @unlink($upload_dir . $aud['informe_archivo']);
                }
                $informe_archivo = $filename;
            } else {
                $errors['informe'] = 'Error al subir el archivo.';
            }
        }
    }

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE auditorias SET
              casa_certificadora_id = ?,
              auditor_externo       = ?,
              fecha_realizada       = ?,
              fecha_reprogramada    = ?,
              resultado             = ?,
              observaciones         = ?,
              informe_archivo       = ?
            WHERE id = ?
        ")->execute([
            $data['casa_certificadora_id'],
            $data['auditor_externo'] ?: null,
            $data['fecha_realizada'] ?: null,
            $data['fecha_reprogramada'] ?: null,
            $data['resultado'],
            $data['observaciones'] ?: null,
            $informe_archivo,
            $id,
        ]);

        // Si fue reprogramada, actualizar fecha_programada
        if ($data['resultado'] === 'reprogramado' && $data['fecha_reprogramada']) {
            $pdo->prepare("UPDATE auditorias SET fecha_programada=? WHERE id=?")
                ->execute([$data['fecha_reprogramada'], $id]);
        }

        log_actividad('auditorias', 'actualizar', 'Auditoría ID: ' . $id . ' — Resultado: ' . $data['resultado']);
        header('Location: ' . APP_URL . '/modules/auditorias/ver.php?id=' . $id . '&ok=1');
        exit;
    }

    // Mantener datos en caso de error
    $aud = array_merge($aud, $data);
}

$resultado_map = [
    'pendiente'         => ['label' => 'Pendiente',         'color' => 'var(--tx2)'],
    'aprobado'          => ['label' => 'Aprobado',          'color' => '#10B981'],
    'con_observaciones' => ['label' => 'Con observaciones', 'color' => '#F59E0B'],
    'no_conforme'       => ['label' => 'No conforme',       'color' => '#EF4444'],
    'reprogramado'      => ['label' => 'Reprogramado',      'color' => '#8B5CF6'],
];
$tipo_map = [
    'seguimiento_1' => 'Auditoría de Seguimiento 1',
    'seguimiento_2' => 'Auditoría de Seguimiento 2',
    'renovacion'    => 'Auditoría de Renovación',
];

$res          = $resultado_map[$aud['resultado']] ?? $resultado_map['pendiente'];
$informe_url  = !empty($aud['informe_archivo'])
    ? rtrim(UPLOAD_URL, '/') . '/auditorias/' . $aud['informe_archivo']
    : null;

$page_title  = 'Detalle de Auditoría';
$active_menu = 'auditorias';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .aud-header { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); padding:24px 28px; margin-bottom:20px; }
  .detail-panel { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; margin-bottom:20px; }
  .detail-panel-header { padding:16px 20px; border-bottom:1px solid var(--gris-borde); font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--gris-dark); display:flex; align-items:center; gap:8px; }
  .detail-row { display:flex; padding:12px 20px; border-bottom:1px solid var(--bg-surface2); font-size:13px; }
  .detail-row:last-child { border-bottom:none; }
  .detail-label { font-weight:600; color:var(--gris-texto); width:180px; flex-shrink:0; }
  .detail-value { color:var(--gris-dark); }
  .form-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; }
  .form-section { padding:22px 26px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--gris-dark); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:span 2; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--gris-texto); }
  .field .err-msg { font-size:11px; color:var(--red,#ef4444); }
  .field input[type=text],.field input[type=date],.field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif; color:var(--gris-dark);
    background:var(--bg-surface2); outline:none; width:100%; transition:border-color 0.2s;
  }
  .field input:focus,.field textarea:focus { border-color:var(--azul); background:var(--bg-surface); }
  .field input.error,.field select.error,.field textarea.error { border-color:#ef4444; }
  .field textarea { resize:vertical; min-height:80px; }
  .select-wrap { position:relative; }
  .select-wrap select { width:100%; padding:10px 30px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; color:var(--gris-dark); background-color:var(--bg-surface2); outline:none; cursor:pointer; }
  .select-wrap select:focus { border-color:var(--azul); background-color:var(--bg-surface); }
  .form-actions { padding:16px 26px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
  .upload-zone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; background:var(--bg-surface2); }
  .upload-zone:hover { border-color:var(--azul); background:var(--s-info-bg); }
  .upload-zone input { display:none; }
  @media(max-width:640px){.form-grid{grid-template-columns:1fr;}.span-2{grid-column:span 1;}}
</style>

<?php if (isset($_GET['ok'])): ?>
<script>ggiReady(function() {
  showToast('Auditoría actualizada exitosamente.', 'ok');
  history.replaceState({}, '', window.location.pathname + '?id=<?= $id ?>');
});</script>
<?php endif; ?>

<?php
ggi_breadcrumb([
    ['label' => 'Empresas',    'href' => APP_URL.'/modules/clientes/index.php',    'ss' => 'ggi_ss_clientes'],
    ['label' => sanitize($aud['razon_social']), 'href' => APP_URL.'/modules/clientes/ver.php?id='.(int)$aud['cliente_id']],
    ['label' => 'Certificados','href' => APP_URL.'/modules/certificados/ver.php?id='.(int)$aud['cert_id']],
    ['label' => 'Auditorías',  'href' => APP_URL.'/modules/auditorias/index.php',   'ss' => 'ggi_ss_auditorias'],
    ['label' => sanitize($aud['norma_codigo']).' — '.sanitize($tipo_map[$aud['tipo']] ?? $aud['tipo'])],
]);
?>

<!-- Header -->
<div class="aud-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
        <strong style="font-size:13px;background:var(--s-info-bg);color:#475569;border:1px solid #e2e8f0;padding:3px 10px;border-radius:100px;"><?= sanitize($aud['norma_codigo']) ?></strong>
        <span style="background:<?= $res['color'] ?>22;color:<?= $res['color'] ?>;border:1px solid <?= $res['color'] ?>55;padding:3px 10px;border-radius:100px;font-size:12px;font-weight:600;white-space:nowrap;">
          <?= $res['label'] ?>
        </span>
      </div>
      <div style="font-family:'Inter',sans-serif;font-size:18px;font-weight:700;color:var(--gris-dark);"><?= sanitize($aud['razon_social']) ?></div>
      <div style="font-size:13px;color:var(--gris-texto);margin-top:4px;">
        <?= sanitize($tipo_map[$aud['tipo']] ?? $aud['tipo']) ?> · Programada: <strong><?= formato_fecha($aud['fecha_programada']) ?></strong>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= APP_URL ?>/modules/certificados/ver.php?id=<?= $aud['cert_id'] ?>" class="btn btn-outline btn-sm">Ver certificado</a>
    </div>
  </div>
</div>

<!-- Info actual -->
<div class="detail-panel">
  <div class="detail-panel-header">
    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
    Información de la auditoría
  </div>
  <div class="detail-row"><div class="detail-label">Cliente</div><div class="detail-value"><?= sanitize($aud['razon_social']) ?><?= $aud['ruc'] ? ' ('.sanitize($aud['ruc']).')' : '' ?></div></div>
  <div class="detail-row"><div class="detail-label">Norma</div><div class="detail-value"><?= sanitize($aud['norma_codigo']) ?><?= $aud['numero_certificado'] ? ' — '.sanitize($aud['numero_certificado']) : '' ?></div></div>
  <div class="detail-row"><div class="detail-label">Tipo</div><div class="detail-value"><?= sanitize($tipo_map[$aud['tipo']] ?? $aud['tipo']) ?></div></div>
  <div class="detail-row"><div class="detail-label">Fecha programada</div><div class="detail-value"><?= formato_fecha($aud['fecha_programada']) ?></div></div>
  <div class="detail-row"><div class="detail-label">Vencimiento certif.</div><div class="detail-value"><?= formato_fecha($aud['fecha_vencimiento']) ?></div></div>
  <?php if ($aud['auditor_externo']): ?>
  <div class="detail-row"><div class="detail-label">Auditor externo</div><div class="detail-value"><?= sanitize($aud['auditor_externo']) ?></div></div>
  <?php endif; ?>
  <?php if ($aud['casa_auditoria_nombre']): ?>
  <div class="detail-row"><div class="detail-label">Casa certificadora</div><div class="detail-value"><?= sanitize($aud['casa_auditoria_nombre']) ?></div></div>
  <?php endif; ?>
  <?php if ($informe_url): ?>
  <div class="detail-row">
    <div class="detail-label">Informe adjunto</div>
    <div class="detail-value">
      <a href="<?= $informe_url ?>" target="_blank" class="btn btn-outline btn-sm">
        <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Ver informe PDF
      </a>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Formulario registro resultado -->
<div class="form-card">
  <form method="POST" enctype="multipart/form-data">
    <div class="form-section">
      <div class="section-title">
        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Registrar resultado
      </div>
      <div class="form-grid">

        <div class="field">
          <label>Resultado <span class="req">*</span></label>
          <div class="select-wrap">
            <select name="resultado" id="sel-resultado" onchange="onResultadoChange(this)" class="<?= isset($errors['resultado'])?'error':'' ?>">
              <option value="pendiente"         <?= ($aud['resultado']??'')==='pendiente'?'selected':'' ?>>Pendiente</option>
              <option value="aprobado"          <?= ($aud['resultado']??'')==='aprobado'?'selected':'' ?>>Aprobado</option>
              <option value="con_observaciones" <?= ($aud['resultado']??'')==='con_observaciones'?'selected':'' ?>>Con observaciones</option>
              <option value="no_conforme"       <?= ($aud['resultado']??'')==='no_conforme'?'selected':'' ?>>No conforme</option>
              <option value="reprogramado"      <?= ($aud['resultado']??'')==='reprogramado'?'selected':'' ?>>Reprogramado</option>
            </select>
          </div>
          <?php if (isset($errors['resultado'])): ?><div class="err-msg"><?= $errors['resultado'] ?></div><?php endif; ?>
        </div>

        <div class="field" id="campo-fecha-realizada">
          <label>Fecha realizada</label>
          <input type="date" name="fecha_realizada" value="<?= sanitize($aud['fecha_realizada']??'') ?>" class="<?= isset($errors['fecha_realizada'])?'error':'' ?>">
          <?php if (isset($errors['fecha_realizada'])): ?><div class="err-msg"><?= $errors['fecha_realizada'] ?></div><?php endif; ?>
        </div>

        <div class="field" id="campo-fecha-reprogramada" style="display:none;">
          <label>Nueva fecha programada <span class="req">*</span></label>
          <input type="date" name="fecha_reprogramada" value="<?= sanitize($aud['fecha_reprogramada']??'') ?>" class="<?= isset($errors['fecha_reprogramada'])?'error':'' ?>">
          <?php if (isset($errors['fecha_reprogramada'])): ?><div class="err-msg"><?= $errors['fecha_reprogramada'] ?></div><?php endif; ?>
        </div>

        <div class="field">
          <label>Casa certificadora</label>
          <div class="select-wrap">
            <?php
            // Preseleccionar: usa la de la auditoría si tiene, sino la del certificado como default
            $casa_presel = $aud['casa_certificadora_id'] ?: $aud['cert_casa_id'];
            ?>
            <select name="casa_certificadora_id">
              <option value="">— Seleccionar —</option>
              <?php foreach ($casas as $cc): ?>
              <option value="<?= $cc['id'] ?>" <?= $casa_presel == $cc['id'] ? 'selected' : '' ?>><?= sanitize($cc['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label>Auditor externo</label>
          <input type="text" name="auditor_externo" placeholder="Nombre del auditor" value="<?= sanitize($aud['auditor_externo']??'') ?>">
        </div>

        <div class="field span-2">
          <label>Observaciones / Hallazgos</label>
          <textarea name="observaciones" placeholder="Descripción de hallazgos, comentarios internos..."><?= sanitize($aud['observaciones']??'') ?></textarea>
        </div>

        <div class="field span-2">
          <label>Informe de auditoría (PDF)</label>
          <div class="upload-zone" onclick="document.getElementById('input-informe').click()">
            <input type="file" id="input-informe" name="informe" accept=".pdf" onchange="onFileChange(this)">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="margin-bottom:8px;color:var(--azul);opacity:0.5;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <div id="upload-label" style="font-size:13px;color:var(--gris-texto);">
              <?= $aud['informe_archivo'] ? '📄 Archivo actual: '.sanitize(basename($aud['informe_archivo'])).' · Haz clic para reemplazar' : 'Haz clic para subir el informe PDF (máx. 10MB)' ?>
            </div>
          </div>
          <?php if (isset($errors['informe'])): ?><div class="err-msg"><?= $errors['informe'] ?></div><?php endif; ?>
        </div>

      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Guardar resultado
      </button>
      <a href="<?= APP_URL ?>/modules/auditorias/index.php" class="btn btn-outline">Cancelar</a>
    </div>
  </form>
</div>

<script>
function onResultadoChange(sel) {
  const val = sel.value;
  const campoRealizada    = document.getElementById('campo-fecha-realizada');
  const campoReprogramada = document.getElementById('campo-fecha-reprogramada');
  campoReprogramada.style.display = val === 'reprogramado' ? 'flex' : 'none';
  campoRealizada.style.display    = val === 'reprogramado' ? 'none' : 'flex';
}

function onFileChange(input) {
  const label = document.getElementById('upload-label');
  if (input.files && input.files[0]) {
    label.textContent = '📄 ' + input.files[0].name;
  }
}

// Init
ggiReady(() => {
  onResultadoChange(document.getElementById('sel-resultado'));
});
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
