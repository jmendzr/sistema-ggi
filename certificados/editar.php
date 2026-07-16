<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/modules/certificados/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT c.*, cl.razon_social, cl.ruc, cl.id AS cl_id,
           n.codigo AS norma_codigo
    FROM certificados c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN normas_iso n ON n.id = c.norma_iso_id
    WHERE c.id = ?
");
$stmt->execute([$id]);
$cert = $stmt->fetch();
if (!$cert) { header('Location: ' . APP_URL . '/modules/certificados/index.php'); exit; }

$errors = [];
$data   = $cert;
$user   = auth_user();
$from   = $_POST['from'] ?? $_GET['from'] ?? 'listado';
$cancel_url = $from === 'detalle'
    ? APP_URL . '/modules/certificados/ver.php?id=' . $id
    : APP_URL . '/modules/certificados/index.php';

$clientes = $pdo->query("SELECT id, ruc, razon_social FROM clientes WHERE estado='activo' ORDER BY razon_social")->fetchAll();
$normas   = $pdo->query("SELECT id, codigo, nombre FROM normas_iso WHERE activo=1 ORDER BY codigo")->fetchAll();
$casas    = $pdo->query("SELECT id, nombre FROM casas_certificadoras WHERE activo=1 ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'cliente_id'            => (int)($_POST['cliente_id'] ?? 0),
        'norma_iso_id'          => (int)($_POST['norma_iso_id'] ?? 0),
        'casa_certificadora_id' => (int)($_POST['casa_certificadora_id'] ?? 0),
        'numero_certificado'    => trim($_POST['numero_certificado'] ?? ''),
        'alcance'               => trim($_POST['alcance'] ?? ''),
        'fecha_emision'         => $cert['fecha_emision'], // No modificable
        'fecha_vencimiento'     => $cert['fecha_vencimiento'], // No modificable
        'fecha_auditoria_1'     => $cert['fecha_auditoria_1'], // No modificable
        'fecha_auditoria_2'     => $cert['fecha_auditoria_2'], // No modificable
        'estado'                => $_POST['estado'] ?? 'en_proceso',
        'observaciones'         => trim($_POST['observaciones'] ?? ''),
    ];

    if (!$data['cliente_id'])            $errors['cliente_id']            = 'Selecciona un cliente.';
    if (!$data['norma_iso_id'])          $errors['norma_iso_id']          = 'Selecciona una norma ISO.';
    if (!$data['casa_certificadora_id']) $errors['casa_certificadora_id'] = 'Selecciona la casa certificadora.';

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE certificados SET
              cliente_id=?, norma_iso_id=?, casa_certificadora_id=?,
              numero_certificado=?, alcance=?, estado=?, observaciones=?
            WHERE id=?
        ")->execute([
            $data['cliente_id'], $data['norma_iso_id'], $data['casa_certificadora_id'],
            $data['numero_certificado']?:null, $data['alcance']?:null,
            $data['estado'], $data['observaciones']?:null, $id
        ]);

        // Procesar archivos
        $cl = $pdo->prepare("SELECT ruc FROM clientes WHERE id=?");
        $cl->execute([$data['cliente_id']]);
        $ruc = $cl->fetchColumn();
        $carpeta = rtrim(UPLOAD_PATH, '/') . '/' . $ruc . '/certificados/';
        if (!is_dir($carpeta)) { mkdir($carpeta, 0755, true); }

        foreach (['cert_es' => 'certificado_archivo', 'cert_en' => 'certificado_archivo_en'] as $field => $col) {
            if (!empty($_FILES[$field]['name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                $ext         = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                $nombre_orig = pathinfo($_FILES[$field]['name'], PATHINFO_FILENAME);
                $nombre_orig = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nombre_orig);
                $sufijo      = $field === 'cert_es' ? 'ES' : 'EN';
                $nombre      = $nombre_orig . '_' . $sufijo . '.' . $ext;
                if (file_exists($carpeta . $nombre)) {
                    $nombre = $nombre_orig . '_' . $sufijo . '_' . date('Ymd') . '.' . $ext;
                }
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $carpeta . $nombre)) {
                    $pdo->prepare("UPDATE certificados SET $col=? WHERE id=?")
                        ->execute([$ruc . '/certificados/' . $nombre, $id]);
                    $cert[$col] = $ruc . '/certificados/' . $nombre;
                }
            }
        }

        log_actividad('certificados', 'editar', 'Certificado actualizado ID: ' . $id);
        header('Location: ' . APP_URL . '/modules/certificados/editar.php?id=' . $id . '&editado=1');
        exit;
    }
}

$page_title  = 'Editar Certificado';
$active_menu = 'certificados';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .form-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; }
  .form-section { padding:20px 24px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Outfit',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .section-title svg { color:var(--verde); }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:span 2; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field input[type=text], .field input[type=date], .field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:14px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark);
    background:#fafbfe; outline:none; width:100%; -webkit-appearance:none;
  }
  .field input:focus, .field textarea:focus { border-color:var(--azul); background:#fff; }
  .field input.error, .field select.error { border-color:var(--red); }
  .field .err-msg { font-size:11px; color:var(--red); }
  .field textarea { resize:vertical; min-height:80px; }
  /* Fechas no editables */
  .field input[readonly] { background:#f0f2f8 !important; color:var(--gris-texto) !important; cursor:not-allowed; border-color:#e2e8f0 !important; }
  /* Select con ícono */
  .select-wrap { position:relative; }
  .select-wrap svg { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .select-wrap select { width:100%; padding:10px 36px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:14px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark); background:#fafbfe; outline:none; appearance:none; -webkit-appearance:none; cursor:pointer; }
  .select-wrap select:focus { border-color:var(--azul); background:#fff; }
  /* Dropzone */
  .dropzone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; overflow:hidden; }
  .dropzone:hover { border-color:var(--azul); background:#f0f4ff; }
  .dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; margin:0; padding:0; }
  /* Archivo actual */
  .file-actual { display:flex; align-items:center; gap:10px; padding:10px 14px; background:#f0faf2; border:1px solid #b7e4c0; border-radius:9px; margin-bottom:8px; min-width:0; overflow:hidden; }
  .file-actual-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  /* Preview nuevo archivo */
  .file-info { display:none; align-items:center; gap:8px; padding:10px 14px; background:#e8edf8; border:1px solid #b3c8f7; border-radius:9px; margin-top:6px; min-width:0; }
  .file-info.show { display:flex; }
  .file-info-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .form-actions { padding:16px 24px; background:#fafbfe; border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  @media (max-width:640px) {
    .form-section { padding:16px; }
    .form-grid { grid-template-columns:1fr; }
    .span-2 { grid-column:span 1; }
    .form-actions { flex-direction:column; }
    .form-actions .btn { width:100%; justify-content:center; }
  }
</style>

<div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:16px;">
  <a href="<?= $cancel_url ?>" class="btn btn-outline btn-sm">← Volver</a>
</div>

<form method="POST" id="form-cert-editar" enctype="multipart/form-data" novalidate>
<div class="form-card">

  <!-- Datos del certificado -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      Datos del certificado
    </div>
    <div class="form-grid">
      <div class="field">
        <label>Empresa</label>
        <div class="select-wrap">
          <select name="cliente_id" class="<?= isset($errors['cliente_id'])?'error':'' ?>">
            <?php foreach ($clientes as $cl): ?>
            <option value="<?= $cl['id'] ?>" <?= ($data['cliente_id']==$cl['id'])?'selected':'' ?>><?= sanitize($cl['ruc'].' — '.$cl['razon_social']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <?php if (isset($errors['cliente_id'])): ?><div class="err-msg"><?= $errors['cliente_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Norma ISO</label>
        <div class="select-wrap">
          <select name="norma_iso_id">
            <?php foreach ($normas as $n): ?>
            <option value="<?= $n['id'] ?>" <?= ($data['norma_iso_id']==$n['id'])?'selected':'' ?>><?= sanitize($n['codigo'].' — '.$n['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </div>
      <div class="field">
        <label>Casa certificadora</label>
        <div class="select-wrap">
          <select name="casa_certificadora_id">
            <?php foreach ($casas as $cc): ?>
            <option value="<?= $cc['id'] ?>" <?= ($data['casa_certificadora_id']==$cc['id'])?'selected':'' ?>><?= sanitize($cc['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </div>
      <div class="field">
        <label>N° de certificado</label>
        <input type="text" name="numero_certificado" value="<?= sanitize($data['numero_certificado']??'') ?>">
      </div>
      <div class="field span-2">
        <label>Alcance</label>
        <textarea name="alcance"><?= sanitize($data['alcance']??'') ?></textarea>
      </div>
    </div>
  </div>

  <!-- Fechas (solo lectura) -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Fechas
      <span style="font-size:11px;font-weight:400;color:var(--gris-texto);margin-left:4px;">— las fechas no son modificables</span>
    </div>
    <div class="form-grid">
      <div class="field">
        <label>Fecha de emisión</label>
        <input type="date" value="<?= $cert['fecha_emision'] ?>" readonly>
      </div>
      <div class="field">
        <label>Vencimiento</label>
        <input type="date" value="<?= $cert['fecha_vencimiento'] ?>" readonly>
      </div>
      <div class="field">
        <label>Auditoría seguimiento 1</label>
        <input type="date" value="<?= $cert['fecha_auditoria_1'] ?>" readonly>
      </div>
      <div class="field">
        <label>Auditoría seguimiento 2</label>
        <input type="date" value="<?= $cert['fecha_auditoria_2'] ?>" readonly>
      </div>
    </div>
  </div>

  <!-- Archivos -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Archivos del certificado
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;">

      <!-- Español -->
      <div style="flex:1;min-width:240px;max-width:100%;">
        <div style="font-size:12px;font-weight:600;color:var(--azul);margin-bottom:8px;">🇵🇪 Certificado en Español</div>
        <?php if (!empty($cert['certificado_archivo'])): ?>
          <div class="file-actual">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span class="file-actual-name"><?= sanitize(basename($cert['certificado_archivo'])) ?></span>
            <a href="<?= rtrim(UPLOAD_URL,'/') . '/' . $cert['certificado_archivo'] ?>" target="_blank" class="btn btn-outline btn-sm" style="flex-shrink:0;padding:4px 10px;font-size:11px;">Ver</a>
          </div>
          <p style="font-size:11px;color:var(--gris-texto);margin:6px 0 6px;">Para actualizar sube uno nuevo:</p>
        <?php else: ?>
          <p style="font-size:11px;color:var(--gris-texto);margin:0 0 6px;">Sin archivo. Sube el certificado:</p>
        <?php endif; ?>
        <div class="dropzone" id="dz-es">
          <input type="file" name="cert_es" id="file-es" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="showFile('es',this)">
          <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.35;margin-bottom:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <div style="font-size:12px;font-weight:600;color:var(--gris-dark);">Clic o arrastra aquí</div>
          <div style="font-size:11px;color:var(--gris-texto);">PDF, JPG, PNG — máx 10MB</div>
        </div>
        <div class="file-info" id="info-es">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span id="name-es" class="file-info-name"></span>
          <button type="button" onclick="removeFile('es')" style="background:none;border:none;cursor:pointer;color:var(--gris-texto);flex-shrink:0;font-size:14px;">✕</button>
        </div>
      </div>

      <!-- Inglés -->
      <div style="flex:1;min-width:240px;max-width:100%;">
        <div style="font-size:12px;font-weight:600;color:var(--azul);margin-bottom:8px;">🇬🇧 Certificado en Inglés</div>
        <?php if (!empty($cert['certificado_archivo_en'])): ?>
          <div class="file-actual">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span class="file-actual-name"><?= sanitize(basename($cert['certificado_archivo_en'])) ?></span>
            <a href="<?= rtrim(UPLOAD_URL,'/') . '/' . $cert['certificado_archivo_en'] ?>" target="_blank" class="btn btn-outline btn-sm" style="flex-shrink:0;padding:4px 10px;font-size:11px;">Ver</a>
          </div>
          <p style="font-size:11px;color:var(--gris-texto);margin:6px 0 6px;">Para actualizar sube uno nuevo:</p>
        <?php else: ?>
          <p style="font-size:11px;color:var(--gris-texto);margin:0 0 6px;">Sin archivo. Sube el certificado:</p>
        <?php endif; ?>
        <div class="dropzone" id="dz-en">
          <input type="file" name="cert_en" id="file-en" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="showFile('en',this)">
          <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.35;margin-bottom:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <div style="font-size:12px;font-weight:600;color:var(--gris-dark);">Clic o arrastra aquí</div>
          <div style="font-size:11px;color:var(--gris-texto);">PDF, JPG, PNG — máx 10MB</div>
        </div>
        <div class="file-info" id="info-en">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span id="name-en" class="file-info-name"></span>
          <button type="button" onclick="removeFile('en')" style="background:none;border:none;cursor:pointer;color:var(--gris-texto);flex-shrink:0;font-size:14px;">✕</button>
        </div>
      </div>

    </div>
  </div>

  <!-- Estado y observaciones -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Estado y observaciones
    </div>
    <div class="form-grid">
      <div class="field">
        <label>Estado</label>
        <div class="select-wrap">
          <select name="estado">
            <?php foreach (['en_proceso'=>'En proceso','vigente'=>'Vigente','por_vencer'=>'Por vencer','suspendido'=>'Suspendido','vencido'=>'Vencido'] as $val=>$lbl): ?>
            <option value="<?= $val ?>" <?= ($data['estado']===$val)?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </div>
      <div class="field span-2">
        <label>Observaciones</label>
        <textarea name="observaciones"><?= sanitize($data['observaciones']??'') ?></textarea>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Guardar cambios</button>
    <a href="<?= $cancel_url ?>" class="btn btn-outline">Cancelar</a>
    <?php if (has_role('gerente_general','gerente_operaciones')): ?>
    <button type="button" class="btn btn-danger" style="margin-left:auto;" onclick="document.getElementById('modal-eliminar').classList.add('open')">Eliminar certificado</button>
    <?php endif; ?>
  </div>
</div>
</form>

<!-- Modal eliminar -->
<div class="mant-modal-overlay" id="modal-eliminar" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="mant-modal-box" style="max-width:420px;">
    <div class="mant-modal-header">
      <div class="mant-modal-title">¿Eliminar certificado?</div>
      <button type="button" class="mant-modal-close" onclick="document.getElementById('modal-eliminar').classList.remove('open')">✕</button>
    </div>
    <div class="mant-modal-body">
      <p style="font-size:13px;color:var(--gris-texto);margin-bottom:20px;">Esta acción eliminará el certificado, sus archivos adjuntos y auditorías asociadas. No se puede deshacer.</p>
      <form method="POST" action="<?= APP_URL ?>/modules/certificados/eliminar.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div style="display:flex;gap:10px;">
          <button type="submit" class="btn btn-danger" style="flex:1;">Sí, eliminar</button>
          <button type="button" class="btn btn-outline" style="flex:1;" onclick="document.getElementById('modal-eliminar').classList.remove('open')">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function showFile(lang, input) {
  if (!input.files[0]) return;
  document.getElementById('name-' + lang).textContent = input.files[0].name;
  document.getElementById('info-' + lang).classList.add('show');
  document.getElementById('dz-' + lang).style.display = 'none';
}
function removeFile(lang) {
  document.getElementById('file-' + lang).value = '';
  document.getElementById('info-' + lang).classList.remove('show');
  document.getElementById('dz-' + lang).style.display = 'block';
}
</script>
<script>
new GGIForm('form-cert-editar')
  .selectRequired('cliente_id',            'Selecciona un cliente.')
  .selectRequired('norma_iso_id',          'Selecciona una norma ISO.')
  .selectRequired('casa_certificadora_id', 'Selecciona la casa certificadora.')
  .dateRequired('fecha_emision',           'La fecha de emisión es requerida.')
  .init();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<?php if (isset($_GET['editado'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  showToast('Cambios guardados exitosamente.', 'ok');
});
</script>
<?php endif; ?>
