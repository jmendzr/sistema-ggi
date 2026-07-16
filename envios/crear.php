<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$pdo    = Database::get();
$errors = [];
$user   = auth_user();
$from      = $_POST['from'] ?? $_GET['from'] ?? 'listado';
$cert_id_from = (int)($_GET['certificado_id'] ?? 0);
$cliente_id_from = (int)($_GET['cliente_id'] ?? 0);
// Determinar URL de regreso
if ($from === 'dashboard') {
    $back_url = APP_URL . '/modules/dashboard/index.php';
} elseif ($from === 'ficha' && $cert_id_from) {
    $back_url = APP_URL . '/modules/certificados/ver.php?id=' . $cert_id_from;
} elseif ($from === 'ficha' && $cliente_id_from) {
    $back_url = APP_URL . '/modules/clientes/ver.php?id=' . $cliente_id_from;
} else {
    $back_url = APP_URL . '/modules/envios/index.php';
}

$cliente_id_pre = (int)($_GET['cliente_id'] ?? 0);

$clientes = $pdo->query("SELECT id, ruc, razon_social, direccion, ubigeo FROM clientes WHERE estado='activo' ORDER BY razon_social")->fetchAll();
$agencias = $pdo->query("SELECT id, nombre FROM agencias_envio WHERE activo=1 ORDER BY nombre")->fetchAll();
$estados  = $pdo->query("SELECT id, nombre, color_hex FROM estados_envio WHERE activo=1 ORDER BY orden")->fetchAll();
$estado_default = $estados[0]['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $usa_dir_fiscal   = isset($_POST['usa_dir_fiscal']);
    $certificados_ids = array_filter(array_map('intval', $_POST['certificados_ids'] ?? []));

    $data = [
        'cliente_id'       => (int)($_POST['cliente_id'] ?? 0),
        'agencia_envio_id' => (int)($_POST['agencia_envio_id'] ?? 0),
        'estado_envio_id'  => (int)($_POST['estado_envio_id'] ?? 0),
        'codigo_envio'     => trim($_POST['codigo_envio'] ?? ''),
        'numero_orden'     => trim($_POST['numero_orden'] ?? ''),
        'codigo_recojo'    => trim($_POST['codigo_recojo'] ?? ''),
        'fecha_envio'      => $_POST['fecha_envio'] ?? '',
        'fecha_recibido'   => $_POST['fecha_recibido'] ?? '',
        'direccion_destino'=> $usa_dir_fiscal ? '' : trim($_POST['direccion_destino'] ?? ''),
        'destinatario'     => trim($_POST['destinatario'] ?? ''),
        'destinatario_dni' => trim($_POST['destinatario_dni'] ?? ''),
        'observaciones'    => trim($_POST['observaciones'] ?? ''),
    ];

    // Subir comprobante
    $comprobante_archivo = null;
    if (!empty($_FILES['comprobante']['name'])) {
        $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','webp'];
        if (in_array($ext, $allowed) && $_FILES['comprobante']['size'] <= 10*1024*1024) {
            // RUC se obtiene después de validar cliente
            $ruc_row = $pdo->prepare('SELECT ruc FROM clientes WHERE id=?');
            $ruc_row->execute([$data['cliente_id']]);
            $ruc_val = $ruc_row->fetchColumn() ?? 'sin_ruc';
            $ruc_dir = '/home/groupglo/domains/sistema.groupglobaliso.com/public_html/documentaciones_clientes/' . $ruc_val . '/envios/';
            if (!is_dir($ruc_dir)) @mkdir($ruc_dir, 0755, true);
            $filename = 'comprobante_envio_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $ruc_dir . $filename)) {
                $comprobante_archivo = $filename;
            }
        }
    }

    if (!$data['cliente_id'])        $errors['cliente_id']        = 'Selecciona un cliente.';
    if (empty($certificados_ids))    $errors['certificados_ids']  = 'Selecciona al menos un certificado.';
    if (!$data['agencia_envio_id'])  $errors['agencia_envio_id']  = 'Selecciona una agencia.';
    if (!$data['estado_envio_id'])   $errors['estado_envio_id']   = 'Selecciona un estado.';
    if (empty($data['fecha_envio'])) $errors['fecha_envio']       = 'Fecha de envío requerida.';

    if ($usa_dir_fiscal && $data['cliente_id']) {
        $cl = $pdo->prepare("SELECT direccion, ubigeo FROM clientes WHERE id=?");
        $cl->execute([$data['cliente_id']]);
        $cl = $cl->fetch();
        $data['direccion_destino'] = trim(($cl['direccion'] ?? '') . ' ' . ($cl['ubigeo'] ?? ''));
    }

    if (empty($errors)) {
        $pdo->prepare("
            INSERT INTO envios (cliente_id, agencia_envio_id, estado_envio_id,
              codigo_envio, numero_orden, codigo_recojo, fecha_envio, fecha_recibido,
              direccion_destino, destinatario, destinatario_dni, observaciones, comprobante_archivo, registrado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $data['cliente_id'], $data['agencia_envio_id'], $data['estado_envio_id'],
            $data['codigo_envio']      ?: null,
            $data['numero_orden']      ?: null,
            $data['codigo_recojo']     ?: null,
            $data['fecha_envio'],
            $data['fecha_recibido']    ?: null,
            $data['direccion_destino'] ?: null,
            $data['destinatario']      ?: null,
            $data['destinatario_dni']  ?: null,
            $data['observaciones']     ?: null,
            $comprobante_archivo,
            $user['id'],
        ]);
        $envio_id = $pdo->lastInsertId();

        // Insertar certificados relacionados
        $stmt_cert = $pdo->prepare("INSERT INTO envios_certificados (envio_id, certificado_id) VALUES (?,?)");
        foreach ($certificados_ids as $cert_id) {
            $stmt_cert->execute([$envio_id, $cert_id]);
        }

        log_actividad('envios', 'crear', 'Envío ID: ' . $envio_id);
        header('Location: ' . APP_URL . '/modules/envios/ver.php?id=' . $envio_id . '&nuevo=1');
        exit;
    }
}

$page_title  = 'Registrar Envío';
$active_menu = 'envios';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .form-card { background:#fff; border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:900px; }
  .form-section { padding:22px 26px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Outfit',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .section-title svg { color:var(--verde); }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:span 2; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field .err-msg { font-size:11px; color:#ef4444; }
  .field input[type=text],.field input[type=date],.field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark);
    background:#fafbfe; outline:none; width:100%; transition:border-color 0.2s;
  }
  .field input:focus,.field textarea:focus { border-color:var(--azul); background:#fff; }
  .field input.error,.field select.error { border-color:#ef4444; }
  .field textarea { resize:vertical; min-height:70px; }
  .select-wrap { position:relative; }
  .select-wrap svg { position:absolute; right:11px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .select-wrap select { width:100%; padding:10px 34px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark); background:#fafbfe; outline:none; appearance:none; -webkit-appearance:none; cursor:pointer; transition:border-color 0.2s; }
  .select-wrap select:focus { border-color:var(--azul); background:#fff; }
  .select-wrap select.error { border-color:#ef4444; }
  .dir-check { display:flex; align-items:flex-start; gap:12px; padding:13px 16px; border-radius:10px; border:1.5px solid #b7e4c0; background:#f0faf2; cursor:pointer; margin-bottom:12px; transition:all 0.2s; }
  .dir-check:hover { border-color:#2DB140; }
  .dir-check input[type=checkbox] { width:17px; height:17px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; margin-top:1px; }
  .dir-preview { background:#e8edf8; border:1px solid #b3c8f7; border-radius:9px; padding:10px 14px; font-size:13px; color:var(--azul); margin-bottom:12px; display:none; }
  .campo-bloqueado { opacity:0.45; pointer-events:none; }
  .dropzone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; overflow:hidden; }
  .dropzone:hover { border-color:var(--azul); background:#f0f4ff; }
  .dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; margin:0; padding:0; }
  .file-info { display:none; align-items:center; gap:8px; padding:10px 14px; background:#e8edf8; border:1px solid #b3c8f7; border-radius:9px; margin-top:6px; min-width:0; }
  .file-info.show { display:flex; }
  .file-info-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .form-actions { padding:16px 26px; background:#fafbfe; border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
  /* Certificados checkboxes */
  .certs-grid { display:flex; flex-direction:column; gap:8px; }
  .cert-check-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid var(--gris-borde); border-radius:9px; background:#fafbfe; cursor:pointer; transition:all 0.15s; }
  .cert-check-item:hover { border-color:var(--azul); background:#f0f4ff; }
  .cert-check-item input[type=checkbox] { width:16px; height:16px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; }
  .cert-check-item.checked { border-color:var(--azul); background:#e8edf8; }
  .cert-check-norma { font-size:12px; font-weight:700; color:var(--azul); background:var(--azul-light,#e8edf8); padding:2px 8px; border-radius:100px; }
  .cert-check-num { font-size:12px; color:var(--gris-texto); }
  .certs-empty { font-size:13px; color:var(--gris-texto); padding:12px; text-align:center; }
  @media(max-width:640px){.form-grid{grid-template-columns:1fr;}.span-2{grid-column:span 1;}}
</style>

<div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:16px;">
  <a href="<?= $back_url ?>" class="btn btn-outline btn-sm">← Volver</a>
</div>

<form method="POST" id="form-envio-crear" enctype="multipart/form-data" novalidate>
<?= csrf_field() ?>
<input type="hidden" name="from" value="<?= sanitize($from) ?>">
<div class="form-card">

  <!-- 1. Cliente -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Empresa
    </div>
    <div class="field">
      <label>Empresa *</label>
      <div class="select-wrap">
        <select name="cliente_id" id="sel-cliente" onchange="onClienteChange(this)" class="<?= isset($errors['cliente_id'])?'error':'' ?>">
          <option value="">— Seleccionar empresa —</option>
          <?php foreach ($clientes as $cl): ?>
          <option value="<?= $cl['id'] ?>"
            data-dir="<?= sanitize($cl['direccion'] ?? '') ?>"
            data-ubigeo="<?= sanitize($cl['ubigeo'] ?? '') ?>"
            <?= (($_POST['cliente_id']??$cliente_id_pre)==$cl['id'])?'selected':'' ?>>
            <?= sanitize($cl['ruc'].' — '.$cl['razon_social']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
      </div>
      <?php if (isset($errors['cliente_id'])): ?><div class="err-msg"><?= $errors['cliente_id'] ?></div><?php endif; ?>
    </div>
  </div>

  <!-- 2. Certificados -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      Certificados a enviar *
    </div>
    <div id="certs-container">
      <div class="certs-empty">Selecciona primero un cliente para ver sus certificados.</div>
    </div>
    <?php if (isset($errors['certificados_ids'])): ?><div class="err-msg" style="margin-top:6px;"><?= $errors['certificados_ids'] ?></div><?php endif; ?>
  </div>

  <!-- 3. Agencia y seguimiento -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
      Agencia y seguimiento
    </div>
    <div class="form-grid">
      <div class="field">
        <label>Agencia de envío *</label>
        <div class="select-wrap">
          <select name="agencia_envio_id" id="sel-agencia" onchange="onAgenciaChange(this.value)" class="<?= isset($errors['agencia_envio_id'])?'error':'' ?>">
            <option value="">— Seleccionar agencia —</option>
            <?php foreach ($agencias as $ag): ?>
            <option value="<?= $ag['id'] ?>" <?= (($_POST['agencia_envio_id']??0)==$ag['id'])?'selected':'' ?>><?= sanitize($ag['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <?php if (isset($errors['agencia_envio_id'])): ?><div class="err-msg"><?= $errors['agencia_envio_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Estado del envío *</label>
        <div class="select-wrap">
          <select name="estado_envio_id" class="<?= isset($errors['estado_envio_id'])?'error':'' ?>">
            <option value="">— Seleccionar estado —</option>
            <?php foreach ($estados as $est): ?>
            <option value="<?= $est['id'] ?>" <?= (($_POST['estado_envio_id']??$estado_default)==$est['id'])?'selected':'' ?>><?= sanitize($est['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <?php if (isset($errors['estado_envio_id'])): ?><div class="err-msg"><?= $errors['estado_envio_id'] ?></div><?php endif; ?>
      </div>
      <div class="field" id="campo-orden" style="display:none;">
        <label id="lbl-orden">N° de Orden</label>
        <input type="text" name="numero_orden" id="input-orden" value="<?= sanitize($_POST['numero_orden']??'') ?>" placeholder="Número de orden">
      </div>
      <div class="field">
        <label id="lbl-seguimiento">Código de seguimiento</label>
        <input type="text" name="codigo_envio" value="<?= sanitize($_POST['codigo_envio']??'') ?>" placeholder="Ej: OL-2025-001234">
      </div>
      <div class="field" id="campo-recojo" style="display:none;">
        <label id="lbl-recojo">Código de recojo</label>
        <input type="text" name="codigo_recojo" id="input-recojo" value="<?= sanitize($_POST['codigo_recojo']??'') ?>" placeholder="Código de recojo">
      </div>
      <div class="field">
        <label>Fecha de envío *</label>
        <input type="date" name="fecha_envio" value="<?= $_POST['fecha_envio'] ?? date('Y-m-d') ?>" class="<?= isset($errors['fecha_envio'])?'error':'' ?>">
        <?php if (isset($errors['fecha_envio'])): ?><div class="err-msg"><?= $errors['fecha_envio'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Fecha de recepción</label>
        <input type="date" name="fecha_recibido" value="<?= $_POST['fecha_recibido']??'' ?>">
        <span style="font-size:11px;color:var(--gris-texto);">Dejar vacío si aún no fue recibido</span>
      </div>
    </div>
  </div>

  <!-- 4. Destinatario -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      Datos del destinatario
    </div>
    <div class="form-grid">
      <div class="field">
        <label>DNI del destinatario</label>
        <div style="display:flex;gap:8px;">
          <input type="text" name="destinatario_dni" id="input-dni" placeholder="12345678" maxlength="8"
                 value="<?= sanitize($_POST['destinatario_dni']??'') ?>" style="flex:1;"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();consultarDNI();}">
          <button type="button" onclick="consultarDNI()" id="btn-dni"
                  style="padding:10px 14px;background:var(--azul);color:#fff;border:none;border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0;">
            Consultar
          </button>
        </div>
        <div id="dni-msg" style="font-size:11px;margin-top:3px;display:none;"></div>
      </div>
      <div class="field">
        <label>Nombre del destinatario</label>
        <input type="text" name="destinatario" id="input-destinatario"
               placeholder="Se autocompleta al consultar DNI"
               value="<?= sanitize($_POST['destinatario']??'') ?>">
      </div>
    </div>
  </div>

  <!-- 5. Dirección -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      Dirección de destino
    </div>
    <label class="dir-check" id="lbl-dir-check">
      <input type="checkbox" name="usa_dir_fiscal" id="chk-dir" <?= isset($_POST['usa_dir_fiscal'])?'checked':'' ?> onchange="onDirCheck(this)">
      <div>
        <div style="font-size:13px;font-weight:600;color:var(--gris-dark);">Usar dirección fiscal del cliente</div>
        <div style="font-size:11px;color:var(--gris-texto);margin-top:2px;" id="txt-dir-fiscal">Selecciona un cliente para ver su dirección</div>
      </div>
    </label>
    <div class="dir-preview" id="dir-preview"></div>
    <div id="campos-dir">
      <div class="field">
        <label>Dirección de entrega</label>
        <input type="text" name="direccion_destino" id="input-dir"
               placeholder="Calle, número, distrito, ciudad..."
               value="<?= sanitize($_POST['direccion_destino']??'') ?>">
      </div>
    </div>
  </div>

  <!-- 6. Observaciones -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Observaciones
    </div>
    <div class="field">
      <textarea name="observaciones" placeholder="Notas adicionales sobre este envío..."><?= sanitize($_POST['observaciones']??'') ?></textarea>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Guardar envío
    </button>
    <a href="<?= $back_url ?>" class="btn btn-outline">Cancelar</a>
  </div>
</div>
</form>

<script>
const APP_URL = '<?= APP_URL ?>';
const selectedCerts = new Set(<?= json_encode(array_map('intval', $_POST['certificados_ids'] ?? [])) ?>);

function onClienteChange(sel) {
  const opt    = sel.options[sel.selectedIndex];
  const dir    = opt?.dataset.dir    || '';
  const ubigeo = opt?.dataset.ubigeo || '';
  const completa = [dir, ubigeo].filter(Boolean).join(', ');
  document.getElementById('txt-dir-fiscal').textContent = completa || 'Sin dirección registrada';
  if (document.getElementById('chk-dir').checked) {
    document.getElementById('dir-preview').textContent = completa;
    document.getElementById('input-dir').value = completa;
  }
  cargarCertificados(sel.value);
}

async function cargarCertificados(clienteId) {
  const container = document.getElementById('certs-container');
  if (!clienteId) {
    container.innerHTML = '<div class="certs-empty">Selecciona primero un cliente para ver sus certificados.</div>';
    return;
  }
  container.innerHTML = '<div class="certs-empty">Cargando certificados...</div>';
  const r    = await fetch(`${APP_URL}/modules/envios/ajax_certs.php?cliente_id=${clienteId}`);
  const data = await r.json();
  if (!data.length) {
    container.innerHTML = '<div class="certs-empty">Este cliente no tiene certificados activos.</div>';
    return;
  }
  container.innerHTML = '<div class="certs-grid">' + data.map(c => {
    const enTransito = parseInt(c.en_transito) === 1;
    if (enTransito) {
      return `
        <div class="cert-check-item" style="opacity:0.5;cursor:not-allowed;background:#f9f9f9;">
          <input type="checkbox" disabled style="cursor:not-allowed;">
          <span class="cert-check-norma">${c.codigo}</span>
          <span style="font-size:13px;font-weight:600;color:var(--gris-dark);flex:1;">${c.numero || '—'}</span>
          <span style="font-size:11px;color:#b45309;font-weight:600;white-space:nowrap;">Ya enviado</span>
        </div>`;
    }
    return `
      <label class="cert-check-item ${selectedCerts.has(parseInt(c.id)) ? 'checked' : ''}" onclick="toggleCert(this)">
        <input type="checkbox" name="certificados_ids[]" value="${c.id}" ${selectedCerts.has(parseInt(c.id)) ? 'checked' : ''}>
        <span class="cert-check-norma">${c.codigo}</span>
        <span style="font-size:13px;font-weight:600;color:var(--gris-dark);flex:1;">${c.numero || '—'}</span>
      </label>`;
  }).join('') + '</div>';
}

function toggleCert(label) {
  const chk = label.querySelector('input[type=checkbox]');
  label.classList.toggle('checked', chk.checked);
}

async function onAgenciaChange(agenciaId) {
  if (!agenciaId) return;
  const r   = await fetch(`${APP_URL}/modules/envios/ajax_agencia_config.php?id=${agenciaId}`);
  const cfg = await r.json();
  const campoOrden  = document.getElementById('campo-orden');
  const campoRecojo = document.getElementById('campo-recojo');
  if (cfg.tiene_numero_orden) {
    campoOrden.style.display = 'flex';
    document.getElementById('lbl-orden').textContent = cfg.label_numero_orden;
  } else {
    campoOrden.style.display = 'none';
    document.getElementById('input-orden').value = '';
  }
  document.getElementById('lbl-seguimiento').textContent = cfg.label_codigo_seguimiento;
  if (cfg.tiene_codigo_recojo) {
    campoRecojo.style.display = 'flex';
    document.getElementById('lbl-recojo').textContent = cfg.label_codigo_recojo;
  } else {
    campoRecojo.style.display = 'none';
    document.getElementById('input-recojo').value = '';
  }
}

function onDirCheck(chk) {
  const preview  = document.getElementById('dir-preview');
  const inputDir = document.getElementById('input-dir');
  const campos   = document.getElementById('campos-dir');
  const sel      = document.getElementById('sel-cliente');
  const opt      = sel.options[sel.selectedIndex];
  const dir      = opt?.dataset.dir    || '';
  const ubigeo   = opt?.dataset.ubigeo || '';
  const completa = [dir, ubigeo].filter(Boolean).join(', ');
  if (chk.checked) {
    preview.textContent   = completa || 'Sin dirección registrada';
    preview.style.display = 'block';
    inputDir.value        = completa;
    campos.classList.add('campo-bloqueado');
  } else {
    preview.style.display = 'none';
    inputDir.value        = '';
    campos.classList.remove('campo-bloqueado');
  }
}

async function consultarDNI() {
  const input = document.getElementById('input-dni');
  const btn   = document.getElementById('btn-dni');
  const msg   = document.getElementById('dni-msg');
  const dni   = input.value.replace(/\D/g,'');
  if (dni.length !== 8) { msg.style.display='block'; msg.style.color='#ef4444'; msg.textContent='Ingresa 8 dígitos.'; return; }
  btn.textContent = 'Consultando...'; btn.disabled = true; msg.style.display = 'none';
  try {
    const r    = await fetch(`${APP_URL}/modules/envios/ajax_dni.php?dni=${dni}`);
    const data = await r.json();
    if (data.ok) {
      document.getElementById('input-destinatario').value = data.nombre_completo;
      msg.style.display='block'; msg.style.color='#229133'; msg.textContent='✓ '+data.nombre_completo;
    } else {
      msg.style.display='block'; msg.style.color='#ef4444'; msg.textContent=data.msg||'DNI no encontrado.';
    }
  } catch(e) { msg.style.display='block'; msg.style.color='#ef4444'; msg.textContent='Error de conexión.'; }
  finally { btn.textContent='Consultar'; btn.disabled=false; }
}

window.addEventListener('DOMContentLoaded', () => {
  const clienteId = document.getElementById('sel-cliente').value;
  if (clienteId) cargarCertificados(clienteId);
  const agenciaId = document.getElementById('sel-agencia').value;
  if (agenciaId) onAgenciaChange(agenciaId);
  if (document.getElementById('chk-dir').checked) onDirCheck(document.getElementById('chk-dir'));
});
</script>
<script>
new GGIForm('form-envio-crear')
  .selectRequired('cliente_id',      'Selecciona un cliente.')
  .selectRequired('agencia_envio_id','Selecciona una agencia de envío.')
  .selectRequired('estado_envio_id', 'Selecciona el estado del envío.')
  .dateRequired('fecha_envio',       'La fecha de envío es requerida.')
  .required('destinatario',          'El nombre del destinatario es requerido.')
  .init();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
