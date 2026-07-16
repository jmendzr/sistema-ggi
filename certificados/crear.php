<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones','ventas');

$pdo    = Database::get();
$errors = [];
$user   = auth_user();
$from   = $_POST['from'] ?? $_GET['from'] ?? 'listado';
$cancel_url = $from === 'dashboard'
    ? APP_URL . '/modules/dashboard/index.php'
    : APP_URL . '/modules/certificados/index.php';

$cliente_id_pre = (int)($_GET['cliente_id'] ?? 0);
$clientes = $pdo->query("SELECT id, codigo_interno, razon_social, ruc FROM clientes WHERE estado='activo' ORDER BY razon_social")->fetchAll();
$normas   = $pdo->query("SELECT id, codigo, nombre FROM normas_iso WHERE activo=1 ORDER BY codigo")->fetchAll();
$casas    = $pdo->query("SELECT id, nombre FROM casas_certificadoras WHERE activo=1 ORDER BY nombre")->fetchAll();

// Función para subir un archivo de certificado
function subirCertificado($file, $ruc, $cert_id, $sufijo = '') {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['pdf','jpg','jpeg','png','webp'];
    if (!in_array($ext, $allowed)) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null;

    $carpeta = rtrim(UPLOAD_PATH, '/') . '/' . $ruc . '/certificados/';
    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0755, true);
        file_put_contents($carpeta . '.htaccess', "Options -Indexes\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n");
    }

    // Mantener nombre original, agregar sufijo si es necesario
    $nombre_original = pathinfo($file['name'], PATHINFO_FILENAME);
    $nombre_original = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nombre_original);
    $nombre_final    = $nombre_original . ($sufijo ? '_' . $sufijo : '') . '.' . $ext;

    // Si ya existe el archivo, agregar timestamp
    if (file_exists($carpeta . $nombre_final)) {
        $nombre_final = $nombre_original . ($sufijo ? '_' . $sufijo : '') . '_' . date('Ymd') . '.' . $ext;
    }

    if (move_uploaded_file($file['tmp_name'], $carpeta . $nombre_final)) {
        return $ruc . '/certificados/' . $nombre_final;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'cliente_id'            => (int)($_POST['cliente_id'] ?? 0),
        'norma_iso_id'          => (int)($_POST['norma_iso_id'] ?? 0),
        'casa_certificadora_id' => (int)($_POST['casa_certificadora_id'] ?? 0),
        'numero_certificado'    => trim($_POST['numero_certificado'] ?? ''),
        'alcance'               => trim($_POST['alcance'] ?? ''),
        'fecha_emision'         => $_POST['fecha_emision'] ?? '',
        'estado'                => $_POST['estado'] ?? 'en_proceso',
        'observaciones'         => trim($_POST['observaciones'] ?? ''),
    ];

    if (!$data['cliente_id'])            $errors['cliente_id']            = 'Selecciona un cliente.';
    if (!$data['norma_iso_id'])          $errors['norma_iso_id']          = 'Selecciona una norma ISO.';
    if (!$data['casa_certificadora_id']) $errors['casa_certificadora_id'] = 'Selecciona la casa certificadora.';
    if (empty($data['fecha_emision']))   $errors['fecha_emision']         = 'Fecha de emisión requerida.';

    // Calcular fechas automáticamente
    if (!empty($data['fecha_emision'])) {
        $data['fecha_vencimiento'] = date('Y-m-d', strtotime($data['fecha_emision'] . ' +3 years'));
        $data['fecha_auditoria_1'] = date('Y-m-d', strtotime($data['fecha_emision'] . ' +1 year'));
        $data['fecha_auditoria_2'] = date('Y-m-d', strtotime($data['fecha_emision'] . ' +2 years'));
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO certificados
              (cliente_id, norma_iso_id, casa_certificadora_id, numero_certificado,
               alcance, fecha_emision, fecha_vencimiento, fecha_auditoria_1,
               fecha_auditoria_2, estado, observaciones, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $data['cliente_id'], $data['norma_iso_id'], $data['casa_certificadora_id'],
            $data['numero_certificado'] ?: null, $data['alcance'] ?: null,
            $data['fecha_emision'], $data['fecha_vencimiento'],
            $data['fecha_auditoria_1'], $data['fecha_auditoria_2'],
            $data['estado'], $data['observaciones'] ?: null, $user['id'],
        ]);
        $cert_id = $pdo->lastInsertId();

        // Obtener RUC del cliente
        $ruc = $pdo->prepare("SELECT ruc FROM clientes WHERE id=?");
        $ruc->execute([$data['cliente_id']]);
        $ruc = $ruc->fetchColumn();

        // Subir archivos manteniendo nombre original
        $archivo_es = subirCertificado($_FILES['cert_es'] ?? [], $ruc, $cert_id, 'ES');
        $archivo_en = subirCertificado($_FILES['cert_en'] ?? [], $ruc, $cert_id, 'EN');

        if ($archivo_es || $archivo_en) {
            $pdo->prepare("UPDATE certificados SET certificado_archivo=?, certificado_archivo_en=? WHERE id=?")
                ->execute([$archivo_es, $archivo_en, $cert_id]);
        }

        // Crear auditorías automáticamente
        $pdo->prepare("INSERT INTO auditorias (certificado_id, tipo, fecha_programada, registrado_por) VALUES (?,?,?,?)")
            ->execute([$cert_id, 'seguimiento_1', $data['fecha_auditoria_1'], $user['id']]);
        $pdo->prepare("INSERT INTO auditorias (certificado_id, tipo, fecha_programada, registrado_por) VALUES (?,?,?,?)")
            ->execute([$cert_id, 'seguimiento_2', $data['fecha_auditoria_2'], $user['id']]);

        log_actividad('certificados', 'crear', 'Certificado ID: ' . $cert_id);
        header('Location: ' . APP_URL . '/modules/certificados/ver.php?id=' . $cert_id . '&nuevo=1&from=' . $from);
        exit;
    }
}

$page_title  = 'Nuevo Certificado';
$active_menu = 'certificados';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .form-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:900px; }
  .form-section { padding:20px 24px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Outfit',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .section-title svg { color:var(--verde); }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:span 2; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field label .req { color:var(--red); margin-left:2px; }
  .field label .auto { font-size:10px; color:var(--verde-dark); font-weight:600; margin-left:6px; }
  .field .err-msg { font-size:11px; color:var(--red); }
  .field input[type=text], .field input[type=date], .field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:14px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark);
    background:#fafbfe; outline:none; width:100%; transition:border-color 0.2s;
  }
  .field input:focus, .field textarea:focus { border-color:var(--azul); background:#fff; }
  .field input.error, .field select.error { border-color:var(--red); }
  .field textarea { resize:vertical; min-height:80px; }
  .field input[readonly] { background:#f0faf2 !important; color:#229133 !important; border-color:#2DB14044 !important; cursor:default; }
  .select-wrap { position:relative; }
  .select-wrap svg { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .select-wrap select { width:100%; padding:10px 36px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:14px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark); background:#fafbfe; outline:none; appearance:none; -webkit-appearance:none; cursor:pointer; transition:border-color 0.2s; }
  .select-wrap select:focus { border-color:var(--azul); background:#fff; }
  .select-wrap select.error { border-color:var(--red); }
  /* Dropzone */
  .dropzone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; }
  .dropzone:hover { border-color:var(--azul); background:var(--azul-light); }
  .dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
  .file-info { display:none; align-items:center; gap:8px; padding:10px 14px; background:#f0faf2; border:1px solid #b7e4c0; border-radius:9px; margin-top:8px; }
  .file-info.show { display:flex; }
  .file-info { min-width:0; }  .file-info-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--gris-dark); min-width:0; }
  .file-remove { background:none; border:none; cursor:pointer; color:var(--gris-texto); padding:2px 4px; font-size:14px; line-height:1; }
  .file-remove:hover { color:var(--red); }
  .form-actions { padding:16px 24px; background:#fafbfe; border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
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

<form method="POST" id="form-cert-crear" enctype="multipart/form-data" novalidate>
<div class="form-card">

  <div class="form-section">
    <div class="section-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Empresa y norma</div>
    <div class="form-grid">
      <div class="field">
        <label>Empresa <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="cliente_id" class="<?= isset($errors['cliente_id'])?'error':'' ?>">
            <option value="">— Seleccionar empresa —</option>
            <?php foreach ($clientes as $cl): ?>
            <option value="<?= $cl['id'] ?>" <?= (($_POST['cliente_id']??$cliente_id_pre)==$cl['id'])?'selected':'' ?>><?= sanitize($cl['ruc'].' — '.$cl['razon_social']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <?php if (isset($errors['cliente_id'])): ?><div class="err-msg"><?= $errors['cliente_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Norma ISO <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="norma_iso_id" class="<?= isset($errors['norma_iso_id'])?'error':'' ?>">
            <option value="">— Seleccionar norma —</option>
            <?php foreach ($normas as $n): ?>
            <option value="<?= $n['id'] ?>" <?= (($_POST['norma_iso_id']??0)==$n['id'])?'selected':'' ?>><?= sanitize($n['codigo'].' — '.$n['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <?php if (isset($errors['norma_iso_id'])): ?><div class="err-msg"><?= $errors['norma_iso_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Casa certificadora <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="casa_certificadora_id" class="<?= isset($errors['casa_certificadora_id'])?'error':'' ?>">
            <option value="">— Seleccionar casa —</option>
            <?php foreach ($casas as $cc): ?>
            <option value="<?= $cc['id'] ?>" <?= (($_POST['casa_certificadora_id']??0)==$cc['id'])?'selected':'' ?>><?= sanitize($cc['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <?php if (isset($errors['casa_certificadora_id'])): ?><div class="err-msg"><?= $errors['casa_certificadora_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>N° de certificado</label>
        <input type="text" name="numero_certificado" placeholder="Ej: CERT-2025-001" value="<?= sanitize($_POST['numero_certificado']??'') ?>">
      </div>
      <div class="field span-2">
        <label>Alcance</label>
        <textarea name="alcance" placeholder="Descripción del alcance..."><?= sanitize($_POST['alcance']??'') ?></textarea>
      </div>
    </div>
  </div>

  <div class="form-section">
    <div class="section-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Fechas</div>
    <div class="form-grid">
      <div class="field">
        <label>Fecha de emisión <span class="req">*</span></label>
        <input type="date" name="fecha_emision" id="fecha_emision" value="<?= $_POST['fecha_emision']??'' ?>" class="<?= isset($errors['fecha_emision'])?'error':'' ?>" onchange="calcularFechas()">
        <?php if (isset($errors['fecha_emision'])): ?><div class="err-msg"><?= $errors['fecha_emision'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Vencimiento <span class="auto">AUTO +3 años</span></label>
        <input type="date" name="fecha_vencimiento" id="fecha_vencimiento" value="<?= $_POST['fecha_vencimiento']??'' ?>" readonly>
      </div>
      <div class="field">
        <label>Auditoría 1 <span class="auto">AUTO +1 año</span></label>
        <input type="date" name="fecha_auditoria_1" id="fecha_auditoria_1" value="<?= $_POST['fecha_auditoria_1']??'' ?>" readonly>
      </div>
      <div class="field">
        <label>Auditoría 2 <span class="auto">AUTO +2 años</span></label>
        <input type="date" name="fecha_auditoria_2" id="fecha_auditoria_2" value="<?= $_POST['fecha_auditoria_2']??'' ?>" readonly>
      </div>
    </div>
  </div>

  <div class="form-section">
    <div class="section-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Archivos del certificado</div>
    <div class="form-grid">
      <div class="field">
        <label>Certificado en Español</label>
        <div class="dropzone" id="dz-es">
          <input type="file" name="cert_es" id="file-es" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="showFile('es', this)">
          <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.35;margin-bottom:6px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <div style="font-size:13px;font-weight:600;color:var(--gris-dark);">Subir certificado ES</div>
          <div style="font-size:11px;color:var(--gris-texto);margin-top:3px;">PDF, JPG, PNG — máx 10MB</div>
        </div>
        <div class="file-info" id="info-es">
          <svg width="15" height="15" flex-shrink="0" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span class="file-info-name" id="name-es"></span>
          <button type="button" class="file-remove" onclick="removeFile('es')">✕</button>
        </div>
      </div>
      <div class="field">
        <label>Certificado en Inglés</label>
        <div class="dropzone" id="dz-en">
          <input type="file" name="cert_en" id="file-en" accept=".pdf,.jpg,.jpeg,.png,.webp" onchange="showFile('en', this)">
          <svg width="28" height="28" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.35;margin-bottom:6px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <div style="font-size:13px;font-weight:600;color:var(--gris-dark);">Subir certificado EN</div>
          <div style="font-size:11px;color:var(--gris-texto);margin-top:3px;">PDF, JPG, PNG — máx 10MB</div>
        </div>
        <div class="file-info" id="info-en">
          <svg width="15" height="15" flex-shrink="0" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span class="file-info-name" id="name-en"></span>
          <button type="button" class="file-remove" onclick="removeFile('en')">✕</button>
        </div>
      </div>
    </div>
  </div>

  <div class="form-section">
    <div class="section-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Estado y observaciones</div>
    <div class="form-grid">
      <div class="field">
        <label>Estado</label>
        <div class="select-wrap">
          <select name="estado">
            <option value="en_proceso" <?= (($_POST['estado']??'en_proceso')==='en_proceso')?'selected':'' ?>>En proceso</option>
            <option value="vigente"    <?= (($_POST['estado']??'')==='vigente')?'selected':'' ?>>Vigente</option>
            <option value="por_vencer" <?= (($_POST['estado']??'')==='por_vencer')?'selected':'' ?>>Por vencer</option>
            <option value="suspendido" <?= (($_POST['estado']??'')==='suspendido')?'selected':'' ?>>Suspendido</option>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </div>
      <div class="field span-2">
        <label>Observaciones</label>
        <textarea name="observaciones" placeholder="Notas internas..."><?= sanitize($_POST['observaciones']??'') ?></textarea>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Guardar certificado
    </button>
    <a href="<?= $cancel_url ?>" class="btn btn-outline">Cancelar</a>
  </div>
</div>
</form>

<script>
function calcularFechas() {
  const v = document.getElementById('fecha_emision').value;
  if (!v) return;
  const addY = (d, y) => { const r = new Date(d + 'T00:00:00'); r.setFullYear(r.getFullYear()+y); return r.toISOString().split('T')[0]; };
  document.getElementById('fecha_vencimiento').value = addY(v, 3);
  document.getElementById('fecha_auditoria_1').value = addY(v, 1);
  document.getElementById('fecha_auditoria_2').value = addY(v, 2);
}
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
if (document.getElementById('fecha_emision').value) calcularFechas();
</script>
<script>
new GGIForm('form-cert-crear')
  .selectRequired('cliente_id',            'Selecciona un cliente.')
  .selectRequired('norma_iso_id',          'Selecciona una norma ISO.')
  .selectRequired('casa_certificadora_id', 'Selecciona la casa certificadora.')
  .dateRequired('fecha_emision',           'La fecha de emisión es requerida.')
  .init();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
