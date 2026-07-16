<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones');

$pdo    = Database::get();
$errors = [];
$user   = auth_user();
$_ggi_embed = !empty($_GET['embed']) || !empty($_POST['_embed']);
$from   = $_POST['from'] ?? $_GET['from'] ?? 'listado';
$cancel_url = $from === 'dashboard'
    ? APP_URL . '/modules/dashboard/index.php'
    : APP_URL . '/modules/certificados/index.php';

$cliente_id_pre = (int)($_GET['cliente_id'] ?? 0);
$clientes_query = $user['rol'] === 'ejecutivo_ventas'
    ? $pdo->prepare("SELECT id, codigo_interno, razon_social, ruc FROM clientes WHERE estado='activo' AND ejecutivo_id=? ORDER BY razon_social")
    : $pdo->prepare("SELECT id, codigo_interno, razon_social, ruc FROM clientes WHERE estado='activo' ORDER BY razon_social");
if ($user['rol'] === 'ejecutivo_ventas') $clientes_query->execute([$user['id']]);
else $clientes_query->execute([]);
$clientes = $clientes_query->fetchAll();
$normas   = $pdo->query("SELECT id, codigo, nombre FROM normas_iso WHERE activo=1 ORDER BY codigo")->fetchAll();
$casas    = $pdo->query("SELECT id, nombre FROM casas_certificadoras WHERE activo=1 ORDER BY nombre")->fetchAll();

// Función para subir un archivo de certificado
function subirCertificado($file, $ruc, $cert_id, $sufijo = '') {
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['pdf','jpg','jpeg','png','webp'];
    if (!in_array($ext, $allowed)) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null;
    $finfo   = new finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($file['tmp_name']);
    $pdf_mimes     = ['application/pdf','application/x-pdf','application/acrobat','application/vnd.pdf','text/pdf','text/x-pdf'];
    $allowed_mimes = array_merge(['image/jpeg','image/png','image/webp'], $pdf_mimes);
    $mime_ok = in_array($mime, $allowed_mimes) || ($ext === 'pdf' && $mime === 'application/octet-stream');
    if (!$mime_ok) return null;

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
        'fecha_emision'         => $_POST['fecha_emision'] ?? '',
        'estado'                => $_POST['estado'] ?? 'en_proceso',
        'observaciones'         => trim($_POST['observaciones'] ?? ''),
    ];

    if (!$data['cliente_id'])            $errors['cliente_id']   = 'Selecciona un cliente.';
    if (!$data['norma_iso_id'])          $errors['norma_iso_id'] = 'Selecciona una norma ISO.';

    // Calcular fechas automáticamente solo si se provee fecha de emisión
    $data['fecha_vencimiento'] = null;
    $data['fecha_auditoria_1'] = null;
    $data['fecha_auditoria_2'] = null;
    if (!empty($data['fecha_emision'])) {
        // Un día antes del aniversario correspondiente
        $data['fecha_auditoria_1'] = date('Y-m-d', strtotime($data['fecha_emision'] . ' +1 year  -1 day'));
        $data['fecha_auditoria_2'] = date('Y-m-d', strtotime($data['fecha_emision'] . ' +2 years -1 day'));
        $data['fecha_vencimiento'] = date('Y-m-d', strtotime($data['fecha_emision'] . ' +3 years -1 day'));
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO certificados
              (cliente_id, norma_iso_id, casa_certificadora_id, numero_certificado,
               fecha_emision, fecha_vencimiento, fecha_auditoria_1,
               fecha_auditoria_2, estado, observaciones, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $data['cliente_id'], $data['norma_iso_id'],
            $data['casa_certificadora_id'] ?: null,
            $data['numero_certificado'] ?: null,
            $data['fecha_emision'] ?: null, $data['fecha_vencimiento'],
            $data['fecha_auditoria_1'], $data['fecha_auditoria_2'],
            $data['estado'], $data['observaciones'] ?: null, $user['id'],
        ]);
        $cert_id = $pdo->lastInsertId();

        // Obtener RUC y razón social del cliente
        $cl_cert = $pdo->prepare("SELECT ruc, razon_social FROM clientes WHERE id=?");
        $cl_cert->execute([$data['cliente_id']]);
        $cl_cert = $cl_cert->fetch();
        $ruc     = $cl_cert['ruc'] ?? '';

        // Subir archivos manteniendo nombre original
        $archivo_es = subirCertificado($_FILES['cert_es'] ?? [], $ruc, $cert_id, 'ES');
        $archivo_en = subirCertificado($_FILES['cert_en'] ?? [], $ruc, $cert_id, 'EN');

        if ($archivo_es || $archivo_en) {
            $pdo->prepare("UPDATE certificados SET certificado_archivo=?, certificado_archivo_en=? WHERE id=?")
                ->execute([$archivo_es, $archivo_en, $cert_id]);
        }

        // Crear auditorías solo si se calcularon fechas
        if ($data['fecha_auditoria_1']) {
            $pdo->prepare("INSERT INTO auditorias (certificado_id, tipo, fecha_programada, registrado_por) VALUES (?,?,?,?)")
                ->execute([$cert_id, 'seguimiento_1', $data['fecha_auditoria_1'], $user['id']]);
        }
        if ($data['fecha_auditoria_2']) {
            $pdo->prepare("INSERT INTO auditorias (certificado_id, tipo, fecha_programada, registrado_por) VALUES (?,?,?,?)")
                ->execute([$cert_id, 'seguimiento_2', $data['fecha_auditoria_2'], $user['id']]);
        }

        log_actividad('certificados', 'crear', 'Certificado ID: ' . $cert_id);

        NotificationService::dispatch('certificados_cargados', [
            'id'          => (int)$cert_id,
            'cliente_id'  => $data['cliente_id'],
            'triggered_by'=> $user['id'],
            'title'       => "Certificados cargados — " . ($cl_cert['razon_social'] ?? ''),
            'message'     => "Nuevo certificado registrado para " . ($cl_cert['razon_social'] ?? '') . ".",
            'push_body'   => "Certificado ISO registrado correctamente.",
        ]);

        // ── Trigger de envío ──
        // Si el certificado es vigente, usar el verificador unificado:
        //   - Con cotización: crea envío solo cuando TODOS los certs de la propuesta sean vigentes.
        //   - Sin cotización: crea envío individual inmediatamente.
        if ($data['estado'] === 'vigente') {
            require_once __DIR__ . '/../envios/auto_generar.php';
            $envio_auto_id = verificar_envio_por_cotizacion($pdo, $cert_id, $user['id']);
            if ($envio_auto_id) {
                log_actividad('envios', 'auto_crear',
                    "Envío #{$envio_auto_id} generado automáticamente desde certificado #{$cert_id}");
            }
        }

        if ($_ggi_embed) {
            ?><!DOCTYPE html><html><body><script>
            window.parent.postMessage({ggi:'created',module:'certificados'},'*');
            </script></body></html><?php
            exit;
        }
        header('Location: ' . APP_URL . '/modules/certificados/ver.php?id=' . $cert_id . '&nuevo=1&from=' . $from);
        exit;
    }
}

$page_title  = 'Nuevo Certificado';
$active_menu = 'certificados';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .form-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:900px; }
  .form-section { padding:20px 24px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
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
    font-size:14px; font-family:'Inter',sans-serif; color:var(--gris-dark);
    background:var(--bg-surface2); outline:none; width:100%; transition:border-color 0.2s;
  }
  .field input:focus, .field textarea:focus { border-color:var(--azul); background:var(--bg-surface); }
  .field input.error, .field select.error { border-color:var(--red); }
  .field textarea { resize:vertical; min-height:80px; }
  .field input[readonly] { background:var(--s-ok-bg) !important; color:#229133 !important; border-color:#2DB14044 !important; cursor:default; }
  .select-wrap select { width:100%; padding:10px 36px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:14px; font-family:'Inter',sans-serif; color:var(--gris-dark); background-color:var(--bg-surface2); outline:none; cursor:pointer; transition:border-color 0.2s; }
  .select-wrap select:focus { border-color:var(--azul); background-color:var(--bg-surface); }
  .select-wrap select.error { border-color:var(--red); }
  /* Dropzone */
  .dropzone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; }
  .dropzone:hover { border-color:var(--azul); background:var(--azul-light); }
  .dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
  .file-info { display:none; align-items:center; gap:8px; padding:10px 14px; background:var(--s-ok-bg); border:1px solid #b7e4c0; border-radius:9px; margin-top:8px; }
  .file-info.show { display:flex; }
  .file-info { min-width:0; }  .file-info-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:var(--gris-dark); min-width:0; }
  .file-remove { background:none; border:none; cursor:pointer; color:var(--gris-texto); padding:2px 4px; font-size:14px; line-height:1; }
  .file-remove:hover { color:var(--red); }
  .form-actions { padding:16px 24px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
  @media (max-width:640px) {
    .form-section { padding:16px; }
    .form-grid { grid-template-columns:1fr; }
    .span-2 { grid-column:span 1; }
    .form-actions { flex-direction:column; }
    .form-actions .btn { width:100%; justify-content:center; }
  }
</style>

<?php if (!$_ggi_embed): ?>
<?php ggi_breadcrumb([
    ['label' => 'Certificados', 'href' => APP_URL.'/modules/certificados/index.php', 'ss' => 'ggi_ss_certificados'],
    ['label' => 'Nuevo certificado'],
]); ?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div><h1>Nuevo certificado</h1><p>Registra el certificado ISO del cliente</p></div>
</div>
<?php endif; ?>

<form method="POST" id="form-cert-crear" enctype="multipart/form-data" data-ggi-draft="cert-crear" novalidate>
<?= csrf_field() ?>
<?php if ($_ggi_embed): ?><input type="hidden" name="_embed" value="1"><?php endif; ?>
<div class="form-card">

  <div class="form-section">
    <div class="section-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Empresa y norma</div>
    <div class="form-grid">
      <div class="field">
        <label>Empresa <span class="req">*</span></label>
        <div class="ss-wrap">
          <svg class="ss-icon" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" id="cliente_id-ss-text" class="ss-input <?= isset($errors['cliente_id'])?'error':'' ?>" autocomplete="off" placeholder="Escribe RUC o razón social...">
          <div id="cliente_id-ss-drop" class="ss-dropdown"></div>
        </div>
        <select name="cliente_id" id="sel-cliente" style="display:none;" aria-hidden="true" class="<?= isset($errors['cliente_id'])?'error':'' ?>">
          <option value="">— Seleccionar empresa —</option>
          <?php foreach ($clientes as $cl): ?>
          <option value="<?= $cl['id'] ?>" <?= (($_POST['cliente_id']??$cliente_id_pre)==$cl['id'])?'selected':'' ?>><?= sanitize($cl['ruc'].' — '.$cl['razon_social']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['cliente_id'])): ?><div class="err-msg"><?= $errors['cliente_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Norma ISO <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="norma_iso_id" id="norma_iso_id" data-no-search class="<?= isset($errors['norma_iso_id'])?'error':'' ?>">
            <option value="">— Seleccionar norma —</option>
            <?php foreach ($normas as $n): ?>
            <option value="<?= $n['id'] ?>" <?= (($_POST['norma_iso_id']??0)==$n['id'])?'selected':'' ?>><?= sanitize($n['codigo'].' — '.$n['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (isset($errors['norma_iso_id'])): ?><div class="err-msg"><?= $errors['norma_iso_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Casa certificadora <span style="font-size:10px;color:var(--gris-texto);font-weight:400;">(opcional)</span></label>
        <div class="select-wrap">
          <select name="casa_certificadora_id" id="casa_certificadora_id" class="<?= isset($errors['casa_certificadora_id'])?'error':'' ?>">
            <option value="">— Seleccionar casa —</option>
            <?php
            $casa_sel_crear = (int)($_POST['casa_certificadora_id'] ?? 1); // QRO por defecto
            foreach ($casas as $cc): ?>
            <option value="<?= $cc['id'] ?>" <?= $casa_sel_crear == $cc['id'] ? 'selected' : '' ?>><?= sanitize($cc['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (isset($errors['casa_certificadora_id'])): ?><div class="err-msg"><?= $errors['casa_certificadora_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>N° de certificado</label>
        <input type="text" name="numero_certificado" placeholder="Ej: CERT-2025-001" value="<?= sanitize($_POST['numero_certificado']??'') ?>">
      </div>
    </div>
  </div>

  <div class="form-section">
    <div class="section-title"><svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Fechas</div>
    <div class="form-grid">
      <div class="field">
        <label>Fecha de emisión <span style="font-size:10px;color:var(--gris-texto);font-weight:400;">(opcional — se puede completar luego)</span></label>
        <input type="date" name="fecha_emision" id="fecha_emision" value="<?= sanitize($_POST['fecha_emision']??'') ?>" class="<?= isset($errors['fecha_emision'])?'error':'' ?>" onchange="calcularFechas()">
        <?php if (isset($errors['fecha_emision'])): ?><div class="err-msg"><?= $errors['fecha_emision'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Vencimiento <span class="auto">AUTO +3 años</span></label>
        <input type="date" name="fecha_vencimiento" id="fecha_vencimiento" value="<?= sanitize($_POST['fecha_vencimiento']??'') ?>" readonly>
      </div>
      <div class="field">
        <label>Auditoría 1 <span class="auto">AUTO +1 año</span></label>
        <input type="date" name="fecha_auditoria_1" id="fecha_auditoria_1" value="<?= sanitize($_POST['fecha_auditoria_1']??'') ?>" readonly>
      </div>
      <div class="field">
        <label>Auditoría 2 <span class="auto">AUTO +2 años</span></label>
        <input type="date" name="fecha_auditoria_2" id="fecha_auditoria_2" value="<?= sanitize($_POST['fecha_auditoria_2']??'') ?>" readonly>
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
    <?php if (!$_ggi_embed): ?>
    <a href="<?= $cancel_url ?>" class="btn btn-outline">Cancelar</a>
    <?php endif; ?>
  </div>
</div>
</form>

<script>
// Setea una fecha respetando el datepicker (flatpickr): si el input tiene instancia
// de flatpickr, hay que usar setDate() para que el input VISIBLE se actualice.
// (Antes se hacía input.value = ... y flatpickr no reflejaba el cambio → campos vacíos.)
function _setFechaCalc(id, val) {
  const el = document.getElementById(id);
  if (!el) return;
  if (el._flatpickr) el._flatpickr.setDate(val, false); // false = no dispara onChange
  else el.value = val;
}
function calcularFechas() {
  const el = document.getElementById('fecha_emision');
  const v = el ? el.value : ''; // flatpickr mantiene el valor original en Y-m-d
  if (!v) return;
  // Un día antes del aniversario correspondiente
  const addY = (d, y) => { const r = new Date(d + 'T00:00:00'); r.setFullYear(r.getFullYear()+y); r.setDate(r.getDate()-1); return r.toISOString().split('T')[0]; };
  _setFechaCalc('fecha_auditoria_1', addY(v, 1));
  _setFechaCalc('fecha_auditoria_2', addY(v, 2));
  _setFechaCalc('fecha_vencimiento', addY(v, 3));
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
// Enganchar al onChange de flatpickr en fecha_emisión cuando esté listo. flatpickr
// se inicializa en DOMContentLoaded (layout_end), después de este script, y el
// onchange inline no siempre dispara con flatpickr → recalcular desde su hook.
(function _hookFechaEmision() {
  const el = document.getElementById('fecha_emision');
  if (el && el._flatpickr) {
    el._flatpickr.config.onChange.push(function () { calcularFechas(); });
    if (el.value) calcularFechas();
  } else {
    setTimeout(_hookFechaEmision, 150); // reintentar hasta que flatpickr esté montado
  }
})();
</script>
<script>
initSearchSelect({
  textInputId:    'cliente_id-ss-text',
  dropdownId:     'cliente_id-ss-drop',
  hiddenSelectId: 'sel-cliente',
  data: <?= json_encode(array_map(fn($cl) => ['id'=>$cl['id'], 'label'=>($cl['ruc']?$cl['ruc'].' — ':'').$cl['razon_social']], $clientes), JSON_UNESCAPED_UNICODE) ?>,
});
</script>
<script>
new GGIForm('form-cert-crear')
  .selectRequired('cliente_id',   'Selecciona un cliente.')
  .selectRequired('norma_iso_id', 'Selecciona una norma ISO.')
  .init();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
