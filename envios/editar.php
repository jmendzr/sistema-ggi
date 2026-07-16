<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

$id   = (int)($_GET['id'] ?? 0);
$from = $_GET['from'] ?? $_POST['from'] ?? 'detalle';
if (!$id) { header('Location: ' . APP_URL . '/modules/envios/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT e.*, cl.razon_social, cl.direccion, cl.ubigeo
    FROM envios e
    JOIN clientes cl ON cl.id = e.cliente_id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$env = $stmt->fetch();
if (!$env) { header('Location: ' . APP_URL . '/modules/envios/index.php'); exit; }

// Certificados actuales del envío
$certs_actuales_stmt = $pdo->prepare("
    SELECT ec.certificado_id, c.id, n.codigo AS norma_codigo, c.numero_certificado
    FROM envios_certificados ec
    JOIN certificados c ON c.id = ec.certificado_id
    JOIN normas_iso n   ON n.id = c.norma_iso_id
    WHERE ec.envio_id = ?
");
$certs_actuales_stmt->execute([$id]);
$certs_actuales     = $certs_actuales_stmt->fetchAll();
$certs_actuales_ids = array_column($certs_actuales, 'certificado_id');

// Todos los certificados del cliente
$certs_cliente_stmt = $pdo->prepare("
    SELECT c.id, n.codigo AS norma_codigo, c.numero_certificado,
           EXISTS (
               SELECT 1
               FROM envios_certificados ec
               JOIN envios e ON e.id = ec.envio_id
               WHERE ec.certificado_id = c.id
               AND e.id != ?
           ) AS en_transito
    FROM certificados c
    JOIN normas_iso n ON n.id = c.norma_iso_id
    WHERE c.cliente_id = ? AND c.estado NOT IN ('vencido','suspendido')
    ORDER BY n.codigo
");
$certs_cliente_stmt->execute([$id, $env['cliente_id']]);
$certs_cliente = $certs_cliente_stmt->fetchAll();

$errors  = [];
$data    = $env;
$user    = auth_user();
$agencias = $pdo->query("SELECT id, nombre FROM agencias_envio WHERE activo=1 ORDER BY nombre")->fetchAll();
$estados  = $pdo->query("SELECT id, nombre, color_hex FROM estados_envio WHERE activo=1 ORDER BY orden")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $usa_dir_fiscal   = isset($_POST['usa_dir_fiscal']);
    $certificados_ids = array_filter(array_map('intval', $_POST['certificados_ids'] ?? []));

    $data = [
        'agencia_envio_id'  => (int)($_POST['agencia_envio_id'] ?? 0),
        'estado_envio_id'   => (int)($_POST['estado_envio_id'] ?? 0),
        'codigo_envio'      => trim($_POST['codigo_envio'] ?? ''),
        'numero_orden'      => trim($_POST['numero_orden'] ?? ''),
        'codigo_recojo'     => trim($_POST['codigo_recojo'] ?? ''),
        'fecha_envio'       => $_POST['fecha_envio'] ?? '',
        'fecha_recibido'    => $_POST['fecha_recibido'] ?? '',
        'direccion_destino' => $usa_dir_fiscal ? '' : trim($_POST['direccion_destino'] ?? ''),
        'destinatario'      => trim($_POST['destinatario'] ?? ''),
        'destinatario_dni'  => trim($_POST['destinatario_dni'] ?? ''),
        'observaciones'     => trim($_POST['observaciones'] ?? ''),
    ];

    // Subir comprobante
    $comprobante_archivo = $env['comprobante_archivo'] ?? null;
    if (!empty($_FILES['comprobante']['name'])) {
        $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','webp'];
        if (in_array($ext, $allowed) && $_FILES['comprobante']['size'] <= 10*1024*1024) {
            $ruc_dir = '/home/groupglo/domains/sistema.groupglobaliso.com/public_html/documentaciones_clientes/' . ($env['ruc'] ?? 'sin_ruc') . '/envios/';
            if (!is_dir($ruc_dir)) @mkdir($ruc_dir, 0755, true);
            $filename = 'comprobante_envio_' . $id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $ruc_dir . $filename)) {
                // Borrar anterior
                if ($comprobante_archivo && file_exists($ruc_dir . $comprobante_archivo)) @unlink($ruc_dir . $comprobante_archivo);
                $comprobante_archivo = $filename;
            }
        }
    }

    if (!$data['agencia_envio_id'])  $errors['agencia_envio_id'] = 'Selecciona una agencia.';
    if (!$data['estado_envio_id'])   $errors['estado_envio_id']  = 'Selecciona un estado.';
    if (empty($data['fecha_envio'])) $errors['fecha_envio']      = 'Fecha de envío requerida.';
    if (empty($certificados_ids))    $errors['certificados_ids'] = 'Selecciona al menos un certificado.';

    if ($usa_dir_fiscal) {
        $data['direccion_destino'] = trim(($env['direccion'] ?? '') . ' ' . ($env['ubigeo'] ?? ''));
    }

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE envios SET agencia_envio_id=?, estado_envio_id=?, codigo_envio=?,
              numero_orden=?, codigo_recojo=?, fecha_envio=?, fecha_recibido=?,
              direccion_destino=?, destinatario=?, destinatario_dni=?, observaciones=?
            WHERE id=?
        ")->execute([
            $data['agencia_envio_id'], $data['estado_envio_id'],
            $data['codigo_envio']      ?: null,
            $data['numero_orden']      ?: null,
            $data['codigo_recojo']     ?: null,
            $data['fecha_envio'],
            $data['fecha_recibido']    ?: null,
            $data['direccion_destino'] ?: null,
            $data['destinatario']      ?: null,
            $data['destinatario_dni']  ?: null,
            $data['observaciones']     ?: null,
            $id
        ]);

        // Actualizar certificados: eliminar todos y reinsertar
        $pdo->prepare("DELETE FROM envios_certificados WHERE envio_id=?")->execute([$id]);
        $stmt_cert = $pdo->prepare("INSERT INTO envios_certificados (envio_id, certificado_id) VALUES (?,?)");
        foreach ($certificados_ids as $cert_id) {
            $stmt_cert->execute([$id, $cert_id]);
        }

        log_actividad('envios', 'editar', 'Envío actualizado ID: ' . $id);
        if ($from === 'listado') {
            header('Location: ' . APP_URL . '/modules/envios/index.php?msg=' . urlencode('Envío actualizado correctamente.'));
        } else {
            header('Location: ' . APP_URL . '/modules/envios/ver.php?id=' . $id . '&editado=1');
        }
        exit;
    }

    // En caso de error, usar los IDs del POST
    $certs_actuales_ids = array_values($certificados_ids);
}

$page_title  = 'Editar Envío';
$active_menu = 'envios';
require_once __DIR__ . '/../../templates/layout.php';

$dir_fiscal_completa = trim(($env['direccion'] ?? '') . ' ' . ($env['ubigeo'] ?? ''));
$usa_dir_actual = $env['direccion_destino'] === $dir_fiscal_completa && !empty($dir_fiscal_completa);
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
  .field .err-msg { font-size:11px; color:#ef4444; }
  .field input[type=text],.field input[type=date],.field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:14px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark);
    background:#fafbfe; outline:none; width:100%; transition:border-color 0.2s;
  }
  .field input:focus,.field textarea:focus { border-color:var(--azul); background:#fff; }
  .field textarea { resize:vertical; min-height:70px; }
  .select-wrap { position:relative; }
  .select-wrap svg { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--gris-texto); pointer-events:none; }
  .select-wrap select { width:100%; padding:10px 36px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:14px; font-family:'Plus Jakarta Sans',sans-serif; color:var(--gris-dark); background:#fafbfe; outline:none; appearance:none; -webkit-appearance:none; cursor:pointer; }
  .select-wrap select:focus { border-color:var(--azul); }
  .dir-check { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:9px; border:1.5px solid var(--gris-borde); background:#fafbfe; cursor:pointer; margin-bottom:10px; }
  .dir-check input[type=checkbox] { width:17px; height:17px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; }
  .dir-fiscal-preview { background:#e8edf8; border:1px solid #b3c8f7; border-radius:9px; padding:10px 14px; font-size:13px; color:var(--azul); display:none; margin-bottom:6px; }
  .dropzone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; overflow:hidden; }
  .dropzone:hover { border-color:var(--azul); background:#f0f4ff; }
  .dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; margin:0; padding:0; }
  .file-actual { display:flex; align-items:center; gap:10px; padding:10px 14px; background:#f0faf2; border:1px solid #b7e4c0; border-radius:9px; margin-bottom:8px; min-width:0; overflow:hidden; }
  .file-actual-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .file-info { display:none; align-items:center; gap:8px; padding:10px 14px; background:#e8edf8; border:1px solid #b3c8f7; border-radius:9px; margin-top:6px; min-width:0; }
  .file-info.show { display:flex; }
  .file-info-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .form-actions { padding:16px 24px; background:#fafbfe; border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
  .cert-check-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid var(--gris-borde); border-radius:9px; background:#fafbfe; cursor:pointer; transition:all 0.15s; margin-bottom:6px; }
  .cert-check-item:hover { border-color:var(--azul); background:#f0f4ff; }
  .cert-check-item input[type=checkbox] { width:16px; height:16px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; }
  .cert-check-item.checked { border-color:var(--azul); background:#e8edf8; }
  .cert-check-norma { font-size:12px; font-weight:700; color:var(--azul); background:#e8edf8; padding:2px 8px; border-radius:100px; }
  @media(max-width:640px){.form-grid{grid-template-columns:1fr;}.span-2{grid-column:span 1;}}
</style>

<div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:16px;">
  <a href="<?= APP_URL ?>/modules/envios/index.php" class="btn btn-outline btn-sm">← Volver</a>
</div>

<form method="POST" id="form-envio-editar" enctype="multipart/form-data" novalidate>
<?= csrf_field() ?>
<input type="hidden" name="from" value="<?= sanitize($from) ?>">
<div class="form-card">

  <!-- Certificados -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
      Certificados incluidos *
    </div>
    <?php if (empty($certs_cliente)): ?>
    <div style="font-size:13px;color:var(--gris-texto);">Este cliente no tiene certificados activos.</div>
    <?php else: ?>
    <?php foreach ($certs_cliente as $c):
      $checked      = in_array($c['id'], $certs_actuales_ids);
      $en_transito  = (bool)$c['en_transito'];
    ?>
    <?php if ($en_transito): ?>
    <div class="cert-check-item" style="opacity:0.5;cursor:not-allowed;background:#f9f9f9;">
      <input type="checkbox" disabled>
      <span class="cert-check-norma"><?= sanitize($c['norma_codigo']) ?></span>
      <span style="font-size:13px;font-weight:600;color:var(--gris-dark);flex:1;"><?= sanitize($c['numero_certificado'] ?: '—') ?></span>
      <span style="font-size:11px;color:#b45309;font-weight:600;white-space:nowrap;">Ya enviado</span>
    </div>
    <?php else: ?>
    <label class="cert-check-item <?= $checked?'checked':'' ?>" onclick="toggleCert(this)">
      <input type="checkbox" name="certificados_ids[]" value="<?= $c['id'] ?>" <?= $checked?'checked':'' ?>>
      <span class="cert-check-norma"><?= sanitize($c['norma_codigo']) ?></span>
      <span style="font-size:13px;font-weight:600;color:var(--gris-dark);flex:1;"><?= sanitize($c['numero_certificado'] ?: '—') ?></span>
    </label>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if (isset($errors['certificados_ids'])): ?><div class="err-msg"><?= $errors['certificados_ids'] ?></div><?php endif; ?>
  </div>

  <!-- Dirección -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      Dirección de destino
    </div>
    <label class="dir-check">
      <input type="checkbox" name="usa_dir_fiscal" id="chk-dir-fiscal" onchange="toggleDireccion(this)" <?= $usa_dir_actual?'checked':'' ?>>
      <div>
        <div style="font-size:13px;font-weight:600;color:var(--gris-dark);">Usar dirección fiscal del cliente</div>
        <div style="font-size:11px;color:var(--gris-texto);"><?= sanitize($dir_fiscal_completa ?: 'Sin dirección registrada') ?></div>
      </div>
    </label>
    <div class="dir-fiscal-preview" id="dir-fiscal-preview"><?= sanitize($dir_fiscal_completa) ?></div>
    <div id="campo-dir-nueva">
      <div class="field">
        <label>Dirección de entrega</label>
        <input type="text" name="direccion_destino" id="input-dir" placeholder="Calle, número, distrito, ciudad..."
               value="<?= sanitize($data['direccion_destino'] ?? '') ?>">
      </div>
    </div>
    <div class="form-grid" style="margin-top:12px;">
      <div class="field">
        <label>Destinatario</label>
        <input type="text" name="destinatario" placeholder="Nombre de quien recibe" value="<?= sanitize($data['destinatario'] ?? '') ?>">
      </div>
      <div class="field">
        <label>DNI del destinatario</label>
        <input type="text" name="destinatario_dni" placeholder="Ej: 12345678" maxlength="8" value="<?= sanitize($data['destinatario_dni'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- Agencia y seguimiento -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
      Agencia y seguimiento
    </div>
    <div class="form-grid">
      <div class="field">
        <label>Agencia *</label>
        <div class="select-wrap">
          <select name="agencia_envio_id" id="sel-agencia" onchange="actualizarCamposAgencia(this.value)" class="<?= isset($errors['agencia_envio_id'])?'error':'' ?>">
            <?php foreach ($agencias as $ag): ?>
            <option value="<?= $ag['id'] ?>" <?= ($data['agencia_envio_id']==$ag['id'])?'selected':'' ?>><?= sanitize($ag['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <?php if (isset($errors['agencia_envio_id'])): ?><div class="err-msg"><?= $errors['agencia_envio_id'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Estado *</label>
        <div class="select-wrap">
          <select name="estado_envio_id" class="<?= isset($errors['estado_envio_id'])?'error':'' ?>">
            <?php foreach ($estados as $est): ?>
            <option value="<?= $est['id'] ?>" <?= ($data['estado_envio_id']==$est['id'])?'selected':'' ?>><?= sanitize($est['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </div>
      <div class="field" id="campo-numero-orden" style="display:none;">
        <label id="lbl-numero-orden">N° de Orden</label>
        <input type="text" name="numero_orden" id="input-numero-orden" value="<?= sanitize($data['numero_orden'] ?? '') ?>" placeholder="Número de orden">
      </div>
      <div class="field">
        <label id="lbl-codigo-seguimiento">Código de seguimiento</label>
        <input type="text" name="codigo_envio" value="<?= sanitize($data['codigo_envio'] ?? '') ?>" placeholder="Código de tracking">
      </div>
      <div class="field" id="campo-codigo-recojo" style="display:none;">
        <label id="lbl-codigo-recojo">Código de recojo</label>
        <input type="text" name="codigo_recojo" id="input-codigo-recojo" value="<?= sanitize($data['codigo_recojo'] ?? '') ?>" placeholder="Código de recojo">
      </div>
      <div class="field">
        <label>Fecha de envío *</label>
        <input type="date" name="fecha_envio" value="<?= $data['fecha_envio'] ?? '' ?>" class="<?= isset($errors['fecha_envio'])?'error':'' ?>">
        <?php if (isset($errors['fecha_envio'])): ?><div class="err-msg"><?= $errors['fecha_envio'] ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label>Fecha de recepción</label>
        <input type="date" name="fecha_recibido" value="<?= $data['fecha_recibido'] ?? '' ?>">
      </div>
      <div class="field span-2">
        <label>Observaciones</label>
        <textarea name="observaciones"><?= sanitize($data['observaciones'] ?? '') ?></textarea>
      </div>

      <div class="field span-2">
        <label>Comprobante de envío</label>
        <?php
          $comp_archivo = $env['comprobante_archivo'] ?? null;
          $ruc_env = $env['ruc'] ?? 'sin_ruc';
          $comp_url = $comp_archivo ? rtrim(UPLOAD_URL,'/') . '/' . $ruc_env . '/envios/' . $comp_archivo : null;
        ?>
        <?php if ($comp_url): ?>
        <div class="file-actual">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="#2DB140" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span class="file-actual-name"><?= sanitize(basename($comp_archivo)) ?></span>
          <a href="<?= $comp_url ?>" target="_blank" class="btn btn-outline btn-sm" style="flex-shrink:0;padding:4px 10px;font-size:11px;">Ver</a>
        </div>
        <p style="font-size:11px;color:var(--gris-texto);margin:6px 0 4px;">Para reemplazar sube uno nuevo:</p>
        <?php else: ?>
        <p style="font-size:11px;color:var(--gris-texto);margin:0 0 6px;">Sin comprobante. Puedes subir una foto o PDF:</p>
        <?php endif; ?>
        <!-- Sin capture: en móvil muestra opciones de cámara, galería y archivos -->
        <div class="dropzone" id="dz-comprobante">
          <input type="file" name="comprobante" id="file-comprobante"
                 accept="image/*,.pdf"
                 onchange="showComprobante(this)">
          <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--azul);opacity:0.35;margin-bottom:4px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          <div style="font-size:12px;font-weight:600;color:var(--gris-dark);">Tomar foto o adjuntar archivo</div>
          <div style="font-size:11px;color:var(--gris-texto);">JPG, PNG, PDF — máx 10MB</div>
        </div>
        <div class="file-info" id="info-comprobante">
          <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="#1A3A8F" stroke-width="2" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <span id="name-comprobante" class="file-info-name"></span>
          <button type="button" onclick="removeComprobante()" style="background:none;border:none;cursor:pointer;color:var(--gris-texto);flex-shrink:0;font-size:14px;">✕</button>
        </div>
      </div>

    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Guardar cambios</button>
    <a href="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $id ?>" class="btn btn-outline">Cancelar</a>
  </div>
</div>
</form>

<script>
const dirFiscal = <?= json_encode($dir_fiscal_completa) ?>;

function toggleCert(label) {
  const chk = label.querySelector('input[type=checkbox]');
  label.classList.toggle('checked', chk.checked);
}

function toggleDireccion(chk) {
  const preview  = document.getElementById('dir-fiscal-preview');
  const campo    = document.getElementById('campo-dir-nueva');
  const inputDir = document.getElementById('input-dir');
  if (chk.checked) {
    preview.style.display = 'block';
    inputDir.value = dirFiscal;
    campo.style.opacity = '0.5'; campo.style.pointerEvents = 'none';
  } else {
    preview.style.display = 'none';
    inputDir.value = '';
    campo.style.opacity = '1'; campo.style.pointerEvents = 'auto';
  }
}

async function actualizarCamposAgencia(agenciaId) {
  if (!agenciaId) return;
  try {
    const res = await fetch(`<?= APP_URL ?>/modules/envios/ajax_agencia_config.php?id=${agenciaId}`);
    const cfg = await res.json();
    const campoOrden  = document.getElementById('campo-numero-orden');
    const campoRecojo = document.getElementById('campo-codigo-recojo');
    campoOrden.style.display  = cfg.tiene_numero_orden  ? 'flex' : 'none';
    campoRecojo.style.display = cfg.tiene_codigo_recojo ? 'flex' : 'none';
    if (cfg.tiene_numero_orden)  document.getElementById('lbl-numero-orden').textContent   = cfg.label_numero_orden;
    if (cfg.tiene_codigo_recojo) document.getElementById('lbl-codigo-recojo').textContent  = cfg.label_codigo_recojo;
    document.getElementById('lbl-codigo-seguimiento').textContent = cfg.label_codigo_seguimiento;
  } catch(e) { console.error(e); }
}

if (document.getElementById('chk-dir-fiscal').checked) toggleDireccion(document.getElementById('chk-dir-fiscal'));

function showComprobante(input) {
  const info = document.getElementById('info-comprobante');
  const name = document.getElementById('name-comprobante');
  if (input.files && input.files[0]) {
    name.textContent = input.files[0].name;
    info.classList.add('show');
  }
}
function removeComprobante() {
  document.getElementById('file-comprobante').value = '';
  document.getElementById('info-comprobante').classList.remove('show');
}
const ag = document.getElementById('sel-agencia')?.value;
if (ag) actualizarCamposAgencia(ag);
</script>
<script>
new GGIForm('form-envio-editar')
  .selectRequired('cliente_id',      'Selecciona un cliente.')
  .selectRequired('agencia_envio_id','Selecciona una agencia de envío.')
  .selectRequired('estado_envio_id', 'Selecciona el estado del envío.')
  .dateRequired('fecha_envio',       'La fecha de envío es requerida.')
  .required('destinatario',          'El nombre del destinatario es requerido.')
  .init();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
