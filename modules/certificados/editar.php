<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial','administracion_finanzas');
$user = auth_user();

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
    -- security checked below
");
$stmt->execute([$id]);
$cert = $stmt->fetch();
// Ejecutivo_ventas can only edit certs of their own clients
if ($cert && $user['rol'] === 'ejecutivo_ventas') {
    $owner = $pdo->prepare("SELECT ejecutivo_id FROM clientes WHERE id=?");
    $owner->execute([$cert['cl_id']]);
    $owner = $owner->fetchColumn();
    if ($owner != $user['id']) {
        header('Location: ' . APP_URL . '/modules/certificados/index.php');
        exit;
    }
}
if (!$cert) { header('Location: ' . APP_URL . '/modules/certificados/index.php'); exit; }

$errors = [];
$data   = $cert;
$user   = auth_user();
$from   = $_POST['from'] ?? $_GET['from'] ?? 'listado';
$cancel_url = $from === 'detalle'
    ? APP_URL . '/modules/certificados/ver.php?id=' . $id
    : APP_URL . '/modules/certificados/index.php';

$normas   = $pdo->query("SELECT id, codigo, nombre, meses_vigencia FROM normas_iso WHERE activo=1 ORDER BY codigo")->fetchAll();
$casas    = $pdo->query("SELECT id, nombre FROM casas_certificadoras WHERE activo=1 ORDER BY nombre")->fetchAll();

// Si el certificado fue generado desde una propuesta, la norma ISO no puede cambiarse
$tiene_cotizacion = !empty($cert['cotizacion_id']);

// BPL solo se emite en español → se oculta la carga del certificado en inglés
$es_solo_es = strtoupper(trim((string)$cert['norma_codigo'])) === 'BPL';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'cliente_id'            => (int)($_POST['cliente_id'] ?? 0),
        // Si viene de propuesta, la norma y la casa certificadora no se pueden cambiar
        'norma_iso_id'          => $tiene_cotizacion ? (int)$cert['norma_iso_id']           : (int)($_POST['norma_iso_id'] ?? 0),
        'casa_certificadora_id' => $tiene_cotizacion ? (int)$cert['casa_certificadora_id']  : (int)($_POST['casa_certificadora_id'] ?? 0),
        'numero_certificado'    => trim($_POST['numero_certificado'] ?? ''),
        'fecha_emision'         => trim($_POST['fecha_emision'] ?? $cert['fecha_emision']),
        'fecha_vencimiento'     => $cert['fecha_vencimiento'],
        'fecha_auditoria_1'     => $cert['fecha_auditoria_1'],
        'fecha_auditoria_2'     => $cert['fecha_auditoria_2'],
        'estado'                => $_POST['estado'] ?? 'en_proceso',
        'observaciones'         => trim($_POST['observaciones'] ?? ''),
    ];

    // Recalcular fechas si cambia fecha_emision
    $cambio_emision = $data['fecha_emision'] !== $cert['fecha_emision'];
    $cambio_norma   = (int)$data['norma_iso_id'] !== (int)$cert['norma_iso_id'];
    if (!empty($data['fecha_emision']) && ($cambio_emision || $cambio_norma)) {
        // Vencimiento/auditorías según la vigencia de la norma (BPL=1 año, ISO=3 años)
        $fechas = cert_calcular_fechas($data['fecha_emision'], cert_meses_vigencia($pdo, $data['norma_iso_id']));
        $data['fecha_vencimiento'] = $fechas['fecha_vencimiento'];
        $data['fecha_auditoria_1'] = $fechas['fecha_auditoria_1'];
        $data['fecha_auditoria_2'] = $fechas['fecha_auditoria_2'];
    }

    if (!$data['cliente_id'])   $errors['cliente_id']   = 'Selecciona un cliente.';
    if (!$data['norma_iso_id']) $errors['norma_iso_id'] = 'Selecciona una norma ISO.';

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE certificados SET
              cliente_id=?, norma_iso_id=?, casa_certificadora_id=?,
              numero_certificado=?, fecha_emision=?,
              fecha_vencimiento=?, fecha_auditoria_1=?, fecha_auditoria_2=?,
              estado=?, observaciones=?
            WHERE id=?
        ")->execute([
            $data['cliente_id'], $data['norma_iso_id'], $data['casa_certificadora_id'] ?: null,
            $data['numero_certificado'] ?: null,
            $data['fecha_emision'] ?: null,
            $data['fecha_vencimiento'] ?: null, $data['fecha_auditoria_1'] ?: null, $data['fecha_auditoria_2'] ?: null,
            $data['estado'], $data['observaciones'] ?: null, $id
        ]);

        // Procesar archivos
        $cl = $pdo->prepare("SELECT ruc FROM clientes WHERE id=?");
        $cl->execute([$data['cliente_id']]);
        $ruc = $cl->fetchColumn();
        $carpeta = rtrim(UPLOAD_PATH, '/') . '/' . $ruc . '/certificados/';
        if (!is_dir($carpeta)) { mkdir($carpeta, 0755, true); }

        // BPL solo se emite en español → no se procesa el archivo en inglés.
        $mapaArchivos = ['cert_es' => 'certificado_archivo'];
        if (!cert_norma_solo_es($pdo, (int)$data['norma_iso_id'])) {
            $mapaArchivos['cert_en'] = 'certificado_archivo_en';
        }
        foreach ($mapaArchivos as $field => $col) {
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

        // ── Gestionar auditorías automáticamente ──
        // Si se asignó fecha_emision por primera vez o cambió → crear/actualizar auditorías
        $fecha_emision_nueva  = $data['fecha_emision'] ?: null;
        $fecha_emision_previa = $cert['fecha_emision'] ?: null;

        if ($fecha_emision_nueva && $fecha_emision_nueva !== $fecha_emision_previa) {
            // Verificar auditorías existentes de seguimiento para este certificado
            $auds_exist = $pdo->prepare("
                SELECT id, tipo FROM auditorias
                WHERE certificado_id = ? AND tipo IN ('seguimiento_1','seguimiento_2')
                ORDER BY tipo ASC
            ");
            $auds_exist->execute([$id]);
            $auds = $auds_exist->fetchAll();
            $tipos_existentes = array_column($auds, 'tipo');

            // Actualizar las que existen
            foreach ($auds as $aud) {
                $nueva_fecha = $aud['tipo'] === 'seguimiento_1'
                    ? $data['fecha_auditoria_1']
                    : $data['fecha_auditoria_2'];
                $pdo->prepare("
                    UPDATE auditorias SET fecha_programada=?, resultado='pendiente'
                    WHERE id=?
                ")->execute([$nueva_fecha, $aud['id']]);
            }

            // Crear las que no existen
            if (!in_array('seguimiento_1', $tipos_existentes) && $data['fecha_auditoria_1']) {
                $pdo->prepare("INSERT INTO auditorias (certificado_id, tipo, fecha_programada, registrado_por) VALUES (?,?,?,?)")
                    ->execute([$id, 'seguimiento_1', $data['fecha_auditoria_1'], $user['id']]);
            }
            if (!in_array('seguimiento_2', $tipos_existentes) && $data['fecha_auditoria_2']) {
                $pdo->prepare("INSERT INTO auditorias (certificado_id, tipo, fecha_programada, registrado_por) VALUES (?,?,?,?)")
                    ->execute([$id, 'seguimiento_2', $data['fecha_auditoria_2'], $user['id']]);
            }

            log_actividad('auditorias', 'auto', "Auditorías actualizadas por cambio de fecha en certificado #$id");
        }

        log_actividad('certificados', 'editar', 'Certificado actualizado ID: ' . $id);

        // ── Notificar al ejecutivo cuando el certificado pasa a VIGENTE ──
        //    (se completó/cargó). Solo en la transición en_proceso→vigente,
        //    para no re-notificar en cada edición de un cert ya vigente.
        //    Reutiliza el evento 'certificados_cargados' (regla COMPANY_EXECUTIVE).
        if ($data['estado'] === 'vigente' && ($cert['estado'] ?? '') !== 'vigente') {
            require_once __DIR__ . '/../../core/NotificationService.php';
            $cl_cert = $pdo->prepare("SELECT razon_social FROM clientes WHERE id=?");
            $cl_cert->execute([$data['cliente_id']]);
            $razon = $cl_cert->fetchColumn() ?: '';
            NotificationService::dispatch('certificados_cargados', [
                'id'           => (int)$id,
                'cliente_id'   => (int)$data['cliente_id'],
                'triggered_by' => $user['id'],
                'title'        => "Certificados cargados — " . $razon,
                'message'      => "El certificado de " . $razon . " ya está vigente y cargado.",
                'push_body'    => "Certificado ISO vigente y cargado.",
            ]);
        }

        // ── Trigger de envío: si el nuevo estado es vigente, verificar si todos
        //    los certs de la propuesta ya son activos → crear envío
        if ($data['estado'] === 'vigente') {
            require_once __DIR__ . '/../envios/auto_generar.php';
            $envio_auto = verificar_envio_por_cotizacion($pdo, $id, $user['id']);
            if ($envio_auto) {
                log_actividad('envios', 'auto_crear',
                    "Envío #{$envio_auto} generado automáticamente al editar certificado #{$id}");
            }
        }

        $redirect = str_contains($cancel_url, '?')
            ? $cancel_url . '&editado=1'
            : $cancel_url . '?editado=1';
        header('Location: ' . $redirect);
        exit;
    }
}

$page_title  = 'Editar Certificado';
$active_menu = 'certificados';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .form-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; }
  .form-section { padding:20px 24px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .section-title svg { color:var(--verde); }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:span 2; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field input[type=text], .field input[type=date], .field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:14px; font-family:'Inter',sans-serif; color:var(--gris-dark);
    background:var(--bg-surface2); outline:none; width:100%; -webkit-appearance:none;
  }
  .field input:focus, .field textarea:focus { border-color:var(--azul); background:var(--bg-surface); }
  .field input.error, .field select.error { border-color:var(--red); }
  .field .err-msg { font-size:11px; color:var(--red); }
  .field textarea { resize:vertical; min-height:80px; }
  /* Fechas auto-calculadas — igual que crear */
  .field input[readonly] { background:var(--s-ok-bg) !important; color:#229133 !important; border-color:#2DB14044 !important; cursor:default; }
  .field label .auto { font-size:10px; color:var(--verde-dark); font-weight:600; margin-left:6px; }
  /* Select con ícono */
  .select-wrap select { width:100%; padding:10px 36px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:14px; font-family:'Inter',sans-serif; color:var(--gris-dark); background-color:var(--bg-surface2); outline:none; cursor:pointer; }
  .select-wrap select:focus { border-color:var(--azul); background-color:var(--bg-surface); }
  /* Dropzone */
  .dropzone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; overflow:hidden; }
  .dropzone:hover { border-color:var(--azul); background:var(--s-info-bg); }
  .dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; margin:0; padding:0; }
  /* Archivo actual */
  .file-actual { display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--s-ok-bg); border:1px solid #b7e4c0; border-radius:9px; margin-bottom:8px; min-width:0; overflow:hidden; }
  .file-actual-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  /* Preview nuevo archivo */
  .file-info { display:none; align-items:center; gap:8px; padding:10px 14px; background:var(--s-info-bg); border:1px solid #b3c8f7; border-radius:9px; margin-top:6px; min-width:0; }
  .file-info.show { display:flex; }
  .file-info-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .form-actions { padding:16px 24px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  @media (max-width:640px) {
    .form-section { padding:16px; }
    .form-grid { grid-template-columns:1fr; }
    .span-2 { grid-column:span 1; }
    .form-actions { flex-direction:column; }
    .form-actions .btn { width:100%; justify-content:center; }
  }
</style>

<?php ggi_breadcrumb([
    ['label' => 'Empresas',     'href' => APP_URL.'/modules/clientes/index.php',    'ss' => 'ggi_ss_clientes'],
    ['label' => sanitize($cert['razon_social']), 'href' => APP_URL.'/modules/clientes/ver.php?id='.(int)$cert['cl_id']],
    ['label' => 'Certificados', 'href' => APP_URL.'/modules/certificados/index.php', 'ss' => 'ggi_ss_certificados'],
    ['label' => sanitize($cert['norma_codigo']), 'href' => APP_URL.'/modules/certificados/ver.php?id='.$id],
    ['label' => 'Editar'],
]); ?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1><?= sanitize($cert['razon_social']) ?></h1>
  </div>
</div>

<form method="POST" id="form-cert-editar" enctype="multipart/form-data" novalidate>
<?= csrf_field() ?>
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
        <input type="hidden" name="cliente_id" value="<?= $cert['cliente_id'] ?>">
        <input type="text" value="<?= sanitize($cert['ruc'] ? $cert['ruc'].' — '.$cert['razon_social'] : $cert['razon_social']) ?>" readonly style="background:var(--s-ok-bg);border-color:#b7e4c0;color:#229133;font-weight:600;cursor:default;">
      </div>
      <div class="field">
        <label>Norma ISO
          <?php if ($tiene_cotizacion): ?>
          <span style="font-size:10px;font-weight:500;color:var(--s-warn-tx);background:var(--s-warn-bg);border:1px solid #fde68a;border-radius:4px;padding:1px 7px;margin-left:4px;">
            🔒 Fijada por propuesta
          </span>
          <?php endif; ?>
        </label>
        <?php if ($tiene_cotizacion): ?>
          <?php $norma_actual = array_values(array_filter($normas, fn($n) => $n['id'] == $cert['norma_iso_id']))[0] ?? null; ?>
          <input type="hidden" name="norma_iso_id" id="norma_iso_id" value="<?= (int)$cert['norma_iso_id'] ?>" data-meses="<?= (int)($norma_actual['meses_vigencia'] ?? 36) ?>">
          <input type="text" value="<?= $norma_actual ? sanitize($norma_actual['codigo'].' — '.$norma_actual['nombre']) : '' ?>"
                 disabled style="background:var(--bg-surface2);color:var(--gris-texto);cursor:not-allowed;border-color:var(--gris-borde);">
        <?php else: ?>
        <div class="select-wrap">
          <select name="norma_iso_id" id="norma_iso_id" data-no-search>
            <?php foreach ($normas as $n): ?>
            <option value="<?= $n['id'] ?>" data-meses="<?= (int)$n['meses_vigencia'] ?>" <?= ($data['norma_iso_id']==$n['id'])?'selected':'' ?>><?= sanitize($n['codigo'].' — '.$n['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>
      <div class="field">
        <label>Casa certificadora
          <?php if ($tiene_cotizacion): ?>
          <span style="font-size:10px;font-weight:500;color:var(--s-warn-tx);background:var(--s-warn-bg);border:1px solid #fde68a;border-radius:4px;padding:1px 7px;margin-left:4px;">
            🔒 Fijada por propuesta
          </span>
          <?php else: ?>
          <span style="font-size:10px;color:var(--gris-texto);font-weight:400;">(opcional)</span>
          <?php endif; ?>
        </label>
        <?php if ($tiene_cotizacion): ?>
          <?php $casa_actual = array_values(array_filter($casas, fn($c) => $c['id'] == $cert['casa_certificadora_id']))[0] ?? null; ?>
          <input type="hidden" name="casa_certificadora_id" value="<?= (int)$cert['casa_certificadora_id'] ?>">
          <input type="text" value="<?= $casa_actual ? sanitize($casa_actual['nombre']) : '— Sin asignar —' ?>"
                 disabled style="background:var(--bg-surface2);color:var(--gris-texto);cursor:not-allowed;border-color:var(--gris-borde);">
        <?php else: ?>
        <div class="select-wrap">
          <select name="casa_certificadora_id" id="casa_certificadora_id">
            <option value="">— Sin asignar —</option>
            <?php
            $casa_sel_editar = (int)($data['casa_certificadora_id'] ?? 0) ?: 1; // QRO por defecto
            foreach ($casas as $cc): ?>
            <option value="<?= $cc['id'] ?>" <?= $casa_sel_editar == $cc['id'] ? 'selected' : '' ?>><?= sanitize($cc['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>
      <div class="field">
        <label>N° de certificado</label>
        <input type="text" name="numero_certificado" value="<?= sanitize($data['numero_certificado']??'') ?>">
      </div>
    </div>
  </div>

  <!-- Fechas (solo lectura) -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Fechas
    </div>
    <div class="form-grid">
      <div class="field">
        <label>Fecha de emisión <span style="font-size:10px;color:var(--gris-texto);font-weight:400;">(opcional — se puede completar luego)</span></label>
        <input type="date" name="fecha_emision" id="fecha_emision" value="<?= $data['fecha_emision'] ?? '' ?>" onchange="calcularFechas()">
      </div>
      <div class="field">
        <label>Vencimiento <span class="auto" id="lbl-venc-auto">AUTO</span></label>
        <input type="date" name="fecha_vencimiento" id="fecha_vencimiento" value="<?= $data['fecha_vencimiento'] ?? '' ?>" readonly>
      </div>
      <div class="field">
        <label>Auditoría seguimiento 1 <span class="auto">AUTO +1 año</span></label>
        <input type="date" name="fecha_auditoria_1" id="fecha_auditoria_1" value="<?= $data['fecha_auditoria_1'] ?? '' ?>" readonly>
      </div>
      <div class="field">
        <label>Auditoría seguimiento 2 <span class="auto">AUTO +2 años</span></label>
        <input type="date" name="fecha_auditoria_2" id="fecha_auditoria_2" value="<?= $data['fecha_auditoria_2'] ?? '' ?>" readonly>
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
        <div style="font-size:12px;font-weight:600;color:var(--azul);margin-bottom:8px;">Certificado en Español</div>
        <?php if (!empty($cert['certificado_archivo'])): ?>
          <div class="file-actual">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span class="file-actual-name"><?= sanitize(basename($cert['certificado_archivo'])) ?></span>
            <a href="<?= rtrim(UPLOAD_URL,'/') . '/' . $cert['certificado_archivo'] ?>" target="_blank" data-ggi-preview data-title="Certificado" class="btn btn-outline btn-sm" style="flex-shrink:0;padding:4px 10px;font-size:11px;">Ver</a>
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

      <!-- Inglés (no aplica a BPL: solo español) -->
      <?php if (!$es_solo_es): ?>
      <div style="flex:1;min-width:240px;max-width:100%;">
        <div style="font-size:12px;font-weight:600;color:var(--azul);margin-bottom:8px;">Certificado en Inglés</div>
        <?php if (!empty($cert['certificado_archivo_en'])): ?>
          <div class="file-actual">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span class="file-actual-name"><?= sanitize(basename($cert['certificado_archivo_en'])) ?></span>
            <a href="<?= rtrim(UPLOAD_URL,'/') . '/' . $cert['certificado_archivo_en'] ?>" target="_blank" data-ggi-preview data-title="Certificado (EN)" class="btn btn-outline btn-sm" style="flex-shrink:0;padding:4px 10px;font-size:11px;">Ver</a>
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
      <?php endif; ?>

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
    <button type="button" class="btn btn-danger" style="margin-left:auto;" onclick="eliminarCertificado(<?= $id ?>)">Eliminar certificado</button>
    <?php endif; ?>
  </div>
</div>
</form>

<!-- Modal eliminar -->
<script>
function eliminarCertificado(id) {
  GGIConfirm.show({
    icon: 'danger',
    title: '¿Eliminar certificado?',
    message: 'Esta acción eliminará el certificado, sus archivos y auditorías. No se puede deshacer.',
    confirmText: 'Sí, eliminar',
    confirmColor: '#ef4444',
    onConfirm: () => {
      fetch('<?= APP_URL ?>/modules/certificados/eliminar.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(Object.assign(document.createElement('form'), {
          innerHTML: '<input name="id" value="' + id + '"><input name="csrf_token" value="<?= csrf_token() ?>">'
        }))
      }).then(r => r.json()).then(d => {
        if (d.ok) {
          if (typeof showToast === 'function') showToast('Certificado eliminado.', 'ok');
          setTimeout(() => location.href = '<?= $cancel_url ?>', 1000);
        } else {
          if (typeof showToast === 'function') showToast(d.msg || 'Error al eliminar.', 'error');
        }
      }).catch(e => {
        if (typeof showToast === 'function') showToast('Error: ' + e.message, 'error');
      });
    }
  });
}

// Setea una fecha respetando flatpickr (el input visible es el altInput; hay que
// usar setDate() o el campo calculado se queda vacío al hacer input.value = ...).
function _setFechaCalc(id, val) {
  const el = document.getElementById(id);
  if (!el) return;
  if (el._flatpickr) el._flatpickr.setDate(val || null, false);
  else el.value = val || '';
}
// Vigencia (meses) de la norma seleccionada (select) o fija (input hidden). Def. 36.
function _mesesVigenciaSel() {
  const el = document.getElementById('norma_iso_id');
  if (!el) return 36;
  let m;
  if (el.options && el.selectedIndex >= 0) m = parseInt(el.options[el.selectedIndex].getAttribute('data-meses'), 10);
  else m = parseInt(el.getAttribute('data-meses'), 10);
  return (m && m > 0) ? m : 36;
}
function _addMeses(d, meses) { const r = new Date(d + 'T00:00:00'); r.setMonth(r.getMonth()+meses); r.setDate(r.getDate()-1); return r.toISOString().split('T')[0]; }
function calcularFechas() {
  const meses = _mesesVigenciaSel();
  const lbl = document.getElementById('lbl-venc-auto');
  if (lbl) lbl.textContent = 'AUTO +' + (meses % 12 === 0 ? (meses/12) + (meses === 12 ? ' año' : ' años') : meses + ' meses');
  const el = document.getElementById('fecha_emision');
  const v = el ? el.value : '';
  if (!v) {
    _setFechaCalc('fecha_vencimiento', '');
    _setFechaCalc('fecha_auditoria_1', '');
    _setFechaCalc('fecha_auditoria_2', '');
    return;
  }
  _setFechaCalc('fecha_vencimiento', _addMeses(v, meses));
  // Auditorías de seguimiento (+1/+2 años) solo en ciclos multianuales (≥ 24 meses)
  if (meses >= 24) {
    const addY = (d, y) => { const r = new Date(d + 'T00:00:00'); r.setFullYear(r.getFullYear()+y); r.setDate(r.getDate()-1); return r.toISOString().split('T')[0]; };
    _setFechaCalc('fecha_auditoria_1', addY(v, 1));
    _setFechaCalc('fecha_auditoria_2', addY(v, 2));
  } else {
    _setFechaCalc('fecha_auditoria_1', '');
    _setFechaCalc('fecha_auditoria_2', '');
  }
}
// Enganchar al onChange de flatpickr en fecha_emisión (el onchange inline no siempre
// dispara con flatpickr, que se monta después de este script en DOMContentLoaded).
(function _hookFechaEmision() {
  const el = document.getElementById('fecha_emision');
  if (el && el._flatpickr) {
    el._flatpickr.config.onChange.push(function () { calcularFechas(); });
    const norma = document.getElementById('norma_iso_id');
    if (norma && norma.tagName === 'SELECT') norma.addEventListener('change', calcularFechas);
    // Al cargar: solo fijar la etiqueta AUTO (NO recalcular un vencimiento ya guardado).
    const meses = _mesesVigenciaSel();
    const lbl = document.getElementById('lbl-venc-auto');
    if (lbl) lbl.textContent = 'AUTO +' + (meses % 12 === 0 ? (meses/12) + (meses === 12 ? ' año' : ' años') : meses + ' meses');
  } else {
    setTimeout(_hookFechaEmision, 150);
  }
})();
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
  .selectRequired('norma_iso_id', 'Selecciona una norma ISO.')
  .init();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
<?php if (isset($_GET['editado'])): ?>
<script>
ggiReady(function() {
  showToast('Cambios guardados exitosamente.', 'ok');
  history.replaceState({}, '', window.location.pathname + '?id=<?= (int)$id ?>');
});
</script>
<?php endif; ?>
