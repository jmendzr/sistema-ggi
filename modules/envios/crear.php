<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones','gestor_logistico','delivery','administracion_finanzas');

$pdo    = Database::get();
$errors = [];
$user   = auth_user();
$_ggi_embed = !empty($_GET['embed']) || !empty($_POST['_embed']);
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
$ejecutivos = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='ejecutivo_ventas' ORDER BY nombre")->fetchAll();
$gestores   = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='gestor_logistico' ORDER BY nombre")->fetchAll();

$clientes = $pdo->query("SELECT id, ruc, razon_social, direccion, ubigeo FROM clientes WHERE estado='activo' ORDER BY razon_social")->fetchAll();
$agencias = $pdo->query("SELECT id, nombre, sede_modo FROM agencias_envio WHERE activo=1 ORDER BY nombre")->fetchAll();
$estados  = $pdo->query("SELECT id, nombre, color_hex FROM estados_envio WHERE activo=1 ORDER BY orden")->fetchAll();
// Estado inicial por defecto: "Por preparar". Fallback al primero si no existe.
$_est_default_row = array_filter($estados, fn($e) => strtolower(trim($e['nombre'])) === 'por preparar');
$estado_default   = $_est_default_row ? reset($_est_default_row)['id'] : ($estados[0]['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $usa_dir_fiscal   = isset($_POST['usa_dir_fiscal']);
    $sin_datos        = isset($_POST['sin_datos_envio']);   // El cliente no compartió datos de envío
    $certificados_ids = array_filter(array_map('intval', $_POST['certificados_ids'] ?? []));

    $data = [
        'cliente_id'       => (int)($_POST['cliente_id'] ?? 0),
        'ejecutivo_id'     => ($user['rol'] === 'ejecutivo_ventas' ? $user['id'] : ((int)($_POST['ejecutivo_id'] ?? 0) ?: null)),
        'agente_id'        => ($user['rol'] === 'gestor_logistico' ? $user['id'] : ((int)($_POST['agente_id'] ?? 0) ?: null)),
        'agencia_envio_id' => (int)($_POST['agencia_envio_id'] ?? 0),
        'estado_envio_id'  => (int)($_POST['estado_envio_id'] ?? 0),
        'codigo_envio'     => trim($_POST['codigo_envio'] ?? ''),
        'numero_orden'     => trim($_POST['numero_orden'] ?? ''),
        'codigo_recojo'    => trim($_POST['codigo_recojo'] ?? ''),
        'sede_registro'    => trim($_POST['sede_registro'] ?? ''),
        'tienda_destino'   => trim($_POST['tienda_destino'] ?? ''),
        'fecha_envio'      => $_POST['fecha_envio'] ?? '',
        'fecha_recibido'   => $_POST['fecha_recibido'] ?? '',
        'direccion_destino'=> $usa_dir_fiscal ? '' : trim($_POST['direccion_destino'] ?? ''),
        'destinatario'     => trim($_POST['destinatario'] ?? ''),
        'destinatario_dni' => trim($_POST['destinatario_dni'] ?? ''),
        'observaciones'    => trim($_POST['observaciones'] ?? ''),
        'sin_datos_envio'  => $sin_datos ? 1 : 0,
    ];

    // Integraciones (sede_modo='api'): el destino se define por la sede, NO por dirección libre.
    $_ag_row    = array_values(array_filter($agencias, fn($a) => (int)$a['id'] === $data['agencia_envio_id']))[0] ?? null;
    $_ag_es_api  = $_ag_row && ($_ag_row['sede_modo'] ?? 'manual') === 'api';
    $_ag_es_olva = $_ag_row && stripos($_ag_row['nombre'] ?? '', 'olva') !== false;
    if ($_ag_es_api) {
        $data['direccion_destino'] = '';
        $usa_dir_fiscal = false;
    }

    // "No compartió datos de envío": se anulan todos los datos de destino/destinatario/seguimiento
    // y los campos de seguimiento (estado/fechas) no aplican (no hay envío que rastrear);
    // solo queda la observación (no se obliga a inventar datos).
    if ($sin_datos) {
        $usa_dir_fiscal = false;
        foreach (['codigo_envio','numero_orden','codigo_recojo','sede_registro','tienda_destino',
                  'direccion_destino','destinatario','destinatario_dni','fecha_recibido'] as $_k) {
            $data[$_k] = '';
        }
        // Estado y fecha de envío son NOT NULL → se fijan a valores neutros, no editables por el usuario.
        $data['estado_envio_id'] = $estado_default;
        $data['fecha_envio']     = date('Y-m-d');
    }

    // Subir comprobante
    $comprobante_archivo = null;
    if (!empty($_FILES['comprobante']['name'])) {
        $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','webp'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($_FILES['comprobante']['tmp_name']);
        $pdf_mimes     = ['application/pdf','application/x-pdf','application/acrobat','application/vnd.pdf','text/pdf','text/x-pdf'];
        $allowed_mimes = array_merge(['image/jpeg','image/png','image/webp'], $pdf_mimes);
        $mime_ok = in_array($mime, $allowed_mimes) || ($ext === 'pdf' && $mime === 'application/octet-stream');
        if (in_array($ext, $allowed) && $mime_ok && $_FILES['comprobante']['size'] <= 10*1024*1024) {
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

    // Si ejecutivo_id no está asignado, heredar del cliente
    if (!$data['ejecutivo_id'] && $data['cliente_id']) {
        $ej_cli = $pdo->prepare("SELECT ejecutivo_id FROM clientes WHERE id=?");
        $ej_cli->execute([$data['cliente_id']]);
        $data['ejecutivo_id'] = $ej_cli->fetchColumn() ?: null;
    }

    if (!$data['cliente_id'])        $errors['cliente_id']        = 'Selecciona un cliente.';
    if (empty($certificados_ids))    $errors['certificados_ids']  = 'Selecciona al menos un certificado.';
    if (!$data['agencia_envio_id'])  $errors['agencia_envio_id']  = 'Selecciona una agencia.';
    if (!$data['estado_envio_id'])   $errors['estado_envio_id']   = 'Selecciona un estado.';
    if (empty($data['fecha_envio'])) $errors['fecha_envio']       = 'Fecha de envío requerida.';
    // Si el cliente no compartió datos, exigir una observación que lo explique
    if ($sin_datos && $data['observaciones'] === '') $errors['observaciones'] = 'Indica una observación (el cliente no compartió datos de envío).';
    // Olva: la tienda/punto de recojo es obligatoria (salvo que no se compartieran datos)
    if ($_ag_es_olva && !$sin_datos && $data['tienda_destino'] === '') $errors['tienda_destino'] = 'Selecciona el punto de recojo de Olva.';

    if ($usa_dir_fiscal && $data['cliente_id']) {
        $cl = $pdo->prepare("SELECT direccion, ubigeo FROM clientes WHERE id=?");
        $cl->execute([$data['cliente_id']]);
        $cl = $cl->fetch();
        $data['direccion_destino'] = trim(($cl['direccion'] ?? '') . ' ' . ($cl['ubigeo'] ?? ''));
    }

    // Auto-avanzar a "Preparado" si se ingresó código de envío y el estado sigue en "Por preparar"
    if (!empty($data['codigo_envio']) && empty($errors)) {
        $_st_pp = array_filter($estados, fn($e) => strtolower(trim($e['nombre'])) === 'por preparar');
        $_st_pr = array_filter($estados, fn($e) => strtolower(trim($e['nombre'])) === 'preparado');
        if ($_st_pp && $_st_pr) {
            $id_por_preparar = reset($_st_pp)['id'];
            $id_preparado    = reset($_st_pr)['id'];
            if ($data['estado_envio_id'] == $id_por_preparar) {
                $data['estado_envio_id'] = $id_preparado;
            }
        }
    }

    if (empty($errors)) {
        $pdo->prepare("
            INSERT INTO envios (cliente_id, agencia_envio_id, sede_registro, tienda_destino, estado_envio_id,
              codigo_envio, numero_orden, codigo_recojo, fecha_envio, fecha_recibido,
              direccion_destino, destinatario, destinatario_dni, observaciones, sin_datos_envio, comprobante_archivo, ejecutivo_id, agente_id, registrado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $data['cliente_id'], $data['agencia_envio_id'],
            $data['sede_registro']     ?: null,
            $data['tienda_destino']    ?: null,
            $data['estado_envio_id'],
            $data['codigo_envio']      ?: null,
            $data['numero_orden']      ?: null,
            $data['codigo_recojo']     ?: null,
            $data['fecha_envio'],
            $data['fecha_recibido']    ?: null,
            $data['direccion_destino'] ?: null,
            $data['destinatario']      ?: null,
            $data['destinatario_dni']  ?: null,
            $data['observaciones']     ?: null,
            $data['sin_datos_envio'],
            $comprobante_archivo,
            $data['ejecutivo_id'],
            $data['agente_id'],
            $user['id'],
        ]);
        $envio_id = $pdo->lastInsertId();

        // Insertar certificados relacionados
        $stmt_cert = $pdo->prepare("INSERT INTO envios_certificados (envio_id, certificado_id) VALUES (?,?)");
        foreach ($certificados_ids as $cert_id) {
            $stmt_cert->execute([$envio_id, $cert_id]);
        }

        log_actividad('envios', 'crear', 'Envío ID: ' . $envio_id);

        $_env_cl = array_values(array_filter($clientes, fn($c) => (int)$c['id'] === $data['cliente_id']));
        $empresa_env = $_env_cl[0]['razon_social'] ?? '';
        // Normas certificadas del envío (sin año), para la plantilla ggi_envio_registrado_v2 {{2}}
        $_st_norm = $pdo->prepare("
            SELECT GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(n.codigo, ':', 1)
                     ORDER BY SUBSTRING_INDEX(n.codigo, ':', 1) SEPARATOR ', ')
              FROM envios_certificados ec
              JOIN certificados cert ON cert.id = ec.certificado_id
              JOIN normas_iso n      ON n.id = cert.norma_iso_id
             WHERE ec.envio_id = ?
        ");
        $_st_norm->execute([$envio_id]);
        $normas_env = gg_normas_compact((string)($_st_norm->fetchColumn() ?: ''));
        NotificationService::dispatch('envio_registrado', [
            'id'           => (int)$envio_id,
            'cliente_id'   => $data['cliente_id'],
            'codigo_envio' => $data['codigo_envio'] ?: '',
            'normas'       => $normas_env,
            'triggered_by' => $user['id'],
            'title'        => "Envío registrado — {$empresa_env}",
            'message'      => "Se registró un envío de certificados para {$empresa_env}.",
            'push_body'    => "Envío de certificados registrado.",
        ]);

        flash('Envío registrado exitosamente.');
        if ($_ggi_embed) {
            ?><!DOCTYPE html><html><body><script>
            window.parent.postMessage({ggi:'created',module:'envios'},'*');
            </script></body></html><?php
            exit;
        }
        header('Location: ' . APP_URL . '/modules/envios/ver.php?id=' . $envio_id);
        exit;
    }
}

// Pre-cargar datos desde un envío existente (función duplicar)
$preload = [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_GET['duplicar'])) {
    $dup_stmt = $pdo->prepare("
        SELECT e.*, GROUP_CONCAT(ec.certificado_id) AS cert_ids_str
        FROM envios e
        LEFT JOIN envios_certificados ec ON ec.envio_id = e.id
        WHERE e.id = ? GROUP BY e.id
    ");
    $dup_stmt->execute([(int)$_GET['duplicar']]);
    if ($dup_row = $dup_stmt->fetch()) {
        $preload = $dup_row;
        $preload['cert_ids'] = $dup_row['cert_ids_str']
            ? array_map('intval', explode(',', $dup_row['cert_ids_str'])) : [];
        $cliente_id_pre = (int)$dup_row['cliente_id'];
    }
}
// Pre-fill desde cotización aceptada
if (empty($preload) && !empty($_GET['cotizacion_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $cq = $pdo->prepare("SELECT cot.*, COALESCE(cl.razon_social, cot.empresa_nombre) AS nombre_emp FROM cotizaciones cot LEFT JOIN clientes cl ON cl.id=cot.cliente_id WHERE cot.id=? AND cot.estado='aceptada'");
    $cq->execute([(int)$_GET['cotizacion_id']]);
    if ($cq_row = $cq->fetch()) {
        if (!isset($data)) $data = [];
        $data['cliente_id']   = $cq_row['cliente_id'];
        $data['ejecutivo_id'] = $cq_row['ejecutivo_id'];
        $data['observaciones']= "Generado desde cotización {$cq_row['numero']}";
        $page_title = 'Crear envío — ' . $cq_row['numero'];
        $cliente_id_pre = (int)$cq_row['cliente_id'];
    }
}

// Inicializar $data para el caso GET (evita errores en campos de selects que lo usan)
if (!isset($data)) {
    $data = [
        'ejecutivo_id' => $preload['ejecutivo_id'] ?? null,
        'agente_id'    => $preload['agente_id']    ?? null,
    ];
}
$page_title  = isset($page_title) ? $page_title : (!empty($preload) ? 'Duplicar Envío' : 'Registrar Envío');
$active_menu = 'envios';
require_once __DIR__ . '/../../templates/layout.php';
?>
<style>
  .form-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:900px; }
  .form-section { padding:22px 26px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .section-title svg { color:var(--verde); }
  /* Toggle "No compartió datos de envío" */
  .sindatos-toggle { display:flex; align-items:flex-start; gap:9px; padding:10px 13px; margin-bottom:14px; border:1px solid var(--s-warn-brd,#fcd34d); background:var(--s-warn-bg); border-radius:10px; font-size:12.5px; color:var(--gris-dark); cursor:pointer; }
  .sindatos-toggle input { width:16px; height:16px; margin-top:1px; flex-shrink:0; accent-color:var(--azul); cursor:pointer; }
  .campo-anulado { opacity:.45; }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:span 2; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field .err-msg { font-size:11px; color:#ef4444; }
  .field input[type=text],.field input[type=date],.field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:13px; font-family:'Inter',sans-serif; color:var(--gris-dark);
    background:var(--bg-surface2); outline:none; width:100%; transition:border-color 0.2s;
  }
  .field input:focus,.field textarea:focus { border-color:var(--azul); background:var(--bg-surface); }
  .field input.error,.field select.error { border-color:#ef4444; }
  .field textarea { resize:vertical; min-height:70px; }
  .select-wrap { position:relative; }
  .select-wrap select { width:100%; padding:10px 30px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:13px; font-family:'Inter',sans-serif; color:var(--gris-dark); background-color:var(--bg-surface2); outline:none; cursor:pointer; transition:border-color 0.2s; }
  .select-wrap select:focus { border-color:var(--azul); background-color:var(--bg-surface); }
  .select-wrap select.error { border-color:#ef4444; }
  .dir-check { display:flex; align-items:flex-start; gap:12px; padding:13px 16px; border-radius:10px; border:1.5px solid #b7e4c0; background:var(--s-ok-bg); cursor:pointer; margin-bottom:12px; transition:all 0.2s; }
  .dir-check:hover { border-color:#2DB140; }
  .dir-check input[type=checkbox] { width:17px; height:17px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; margin-top:1px; }
  .dir-preview { background:var(--s-info-bg); border:1px solid #b3c8f7; border-radius:9px; padding:10px 14px; font-size:13px; color:var(--azul); margin-bottom:12px; display:none; }
  .campo-bloqueado { opacity:0.45; pointer-events:none; }
  .dropzone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; overflow:hidden; }
  .dropzone:hover { border-color:var(--azul); background:var(--s-info-bg); }
  .dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; margin:0; padding:0; }
  .file-info { display:none; align-items:center; gap:8px; padding:10px 14px; background:var(--s-info-bg); border:1px solid #b3c8f7; border-radius:9px; margin-top:6px; min-width:0; }
  .file-info.show { display:flex; }
  .file-info-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .form-actions { padding:16px 26px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
  /* Certificados checkboxes */
  .certs-grid { display:flex; flex-direction:column; gap:8px; }
  .cert-check-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid var(--gris-borde); border-radius:9px; background:var(--bg-surface2); cursor:pointer; transition:all 0.15s; }
  .cert-check-item:hover { border-color:var(--azul); background:var(--s-info-bg); }
  .cert-check-item input[type=checkbox] { width:16px; height:16px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; }
  .cert-check-item.checked { border-color:var(--azul); background:var(--s-info-bg); }
  .cert-check-norma { font-size:12px; font-weight:700; color:var(--azul); background:var(--azul-light,#e8edf8); padding:2px 8px; border-radius:100px; }
  .cert-check-num { font-size:12px; color:var(--gris-texto); }
  .certs-empty { font-size:13px; color:var(--gris-texto); padding:12px; text-align:center; }
  @media(max-width:640px){.form-grid{grid-template-columns:1fr;}.span-2{grid-column:span 1;}}
  /* Sede searchable dropdown */
  .sede-search-wrap { position:relative; }
  .sede-search-input { width:100%; box-sizing:border-box; border:1px solid var(--borde,#d0d5dd); border-radius:8px; padding:9px 36px 9px 12px; font-size:14px; color:var(--gris-dark,#1a1a2e); background:var(--blanco,#fff); outline:none; transition:border .15s; }
  .sede-search-input:focus { border-color:var(--azul,#2563eb); box-shadow:0 0 0 3px rgba(37,99,235,.1); }
  .sede-search-input::placeholder { color:var(--gris-texto,#666); }
  .sede-search-wrap::after { content:''; position:absolute; right:12px; top:50%; transform:translateY(-50%); width:10px; height:10px; border-right:2px solid var(--gris-texto,#888); border-bottom:2px solid var(--gris-texto,#888); transform:translateY(-65%) rotate(45deg); pointer-events:none; }
  .sede-dropdown { display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:200; background:var(--blanco,#fff); border:1px solid var(--borde,#d0d5dd); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.12); max-height:260px; overflow-y:auto; }
  .sede-dropdown.open { display:block; }
  .sede-opt { padding:9px 14px; font-size:13px; cursor:pointer; color:var(--gris-dark,#1a1a2e); line-height:1.4; border-bottom:1px solid var(--borde,#f0f0f0); transition:background .1s; }
  .sede-opt:last-child { border-bottom:none; }
  .sede-opt:hover, .sede-opt.focused { background:var(--azul-light,var(--s-info-bg)); }
  .sede-opt mark { background:transparent; color:var(--azul,#2563eb); font-weight:700; }
  .sede-opt-dep { font-size:11px; color:var(--gris-texto,#888); display:block; margin-top:1px; }
  .sede-empty { padding:14px; font-size:13px; color:var(--gris-texto,#888); text-align:center; }
  .sede-selected-label { font-size:12px; color:var(--azul,#2563eb); margin-top:5px; padding:4px 10px; background:var(--azul-light,var(--s-info-bg)); border-radius:6px; font-weight:600; }
</style>

<?php if (!$_ggi_embed): ?>
<?php ggi_breadcrumb([
    ['label' => 'Envíos', 'href' => APP_URL.'/modules/envios/index.php', 'ss' => 'ggi_ss_envios'],
    ['label' => 'Registrar envío'],
]); ?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div><h1>Registrar envío</h1><p>Registra el despacho de certificados</p></div>
</div>
<?php endif; ?>

<form method="POST" id="form-envio-crear" enctype="multipart/form-data" data-ggi-draft="envio-crear" novalidate>
<?= csrf_field() ?>
<input type="hidden" name="from" value="<?= sanitize($from) ?>">
<?php if ($_ggi_embed): ?><input type="hidden" name="_embed" value="1"><?php endif; ?>
<div class="form-card">

  <!-- 1. Cliente -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Empresa
    </div>
    <div class="field">
      <label>Empresa <span class="req">*</span></label>
      <!-- Autocomplete visible -->
      <div class="ss-wrap" id="empresa-autocomplete-wrap">
        <svg class="ss-icon" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="empresa-search-input" class="ss-input <?= isset($errors['cliente_id'])?'error':'' ?>" autocomplete="off"
               placeholder="Escribe nombre o RUC para buscar...">
        <div id="empresa-dropdown" class="ss-dropdown"></div>
      </div>
      <!-- Select oculto — mantiene toda la lógica existente (dirección, certificados, validación) -->
      <select name="cliente_id" id="sel-cliente" onchange="onClienteChange(this)"
              style="display:none;" aria-hidden="true">
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
    <!-- Autocompletar con los datos del último envío del cliente -->
    <div id="ultimo-envio-chip" onclick="usarUltimoEnvio()" style="display:none;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;cursor:pointer;">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
      <span>Usar datos del último envío: <strong id="ultimo-envio-texto"></strong></span>
    </div>
    <?php $sin_datos_pre = !empty($preload['sin_datos_envio']) || isset($_POST['sin_datos_envio']); ?>
    <label class="sindatos-toggle">
      <input type="checkbox" name="sin_datos_envio" id="chk-sin-datos" onchange="onSinDatosToggle(this)" <?= $sin_datos_pre ? 'checked' : '' ?>>
      <span><strong>El cliente no compartió datos de envío</strong> — anula los datos de destino/seguimiento; solo deja una observación.</span>
    </label>
    <div class="form-grid" id="grid-datos-envio">
      <div class="field">
        <label>Agencia de envío <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="agencia_envio_id" id="sel-agencia" onchange="onAgenciaChange(this.value)" class="<?= isset($errors['agencia_envio_id'])?'error':'' ?>">
            <option value="">Selecciona courier...</option>
            <?php foreach ($agencias as $ag): $acfg = ggi_agencia_envio_config($ag['nombre'], $ag['sede_modo'] ?? 'manual'); ?>
            <option value="<?= $ag['id'] ?>"
                    data-sede-modo="<?= $acfg['sede_modo'] ?>"
                    data-orden="<?= $acfg['tiene_numero_orden']?1:0 ?>"
                    data-recojo="<?= $acfg['tiene_codigo_recojo']?1:0 ?>"
                    data-lbl-orden="<?= sanitize($acfg['label_numero_orden']) ?>"
                    data-lbl-recojo="<?= sanitize($acfg['label_codigo_recojo']) ?>"
                    data-lbl-seg="<?= sanitize($acfg['label_codigo_seguimiento']) ?>"
                    data-olva="<?= stripos($ag['nombre'],'olva')!==false?1:0 ?>"
                    <?= (($preload['agencia_envio_id'] ?? $_POST['agencia_envio_id'] ?? 0)==$ag['id'])?'selected':'' ?>><?= sanitize($ag['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (isset($errors['agencia_envio_id'])): ?><div class="err-msg"><?= $errors['agencia_envio_id'] ?></div><?php endif; ?>
      </div>
      <?php $sedeActual = $preload['sede_registro'] ?? $_POST['sede_registro'] ?? ''; ?>
      <div class="field" id="campo-sede" style="display:none;">
        <label>Sede de destino</label>
        <div class="sede-search-wrap" id="sede-wrap">
          <input type="text" id="sede-search" autocomplete="off" placeholder="Buscar sede..." aria-label="Buscar sede..." class="sede-search-input">
          <div class="sede-dropdown" id="sede-dropdown"></div>
        </div>
        <input type="hidden" name="sede_registro" id="sede-val" value="<?= sanitize($sedeActual) ?>">
        <?php if ($sedeActual): ?>
        <div id="sede-selected-label" class="sede-selected-label"><?= sanitize($sedeActual) ?></div>
        <?php else: ?>
        <div id="sede-selected-label" class="sede-selected-label" style="display:none;"></div>
        <?php endif; ?>
      </div>
      <?php $tiendaActual = $preload['tienda_destino'] ?? $_POST['tienda_destino'] ?? ''; ?>
      <!-- Punto de recojo Olva: buscador (mismo componente que Sede); se llena tras elegir el distrito -->
      <div class="field" id="campo-tienda" style="display:none;">
        <label>Punto de recojo <span class="req">*</span></label>
        <div class="sede-search-wrap" id="tienda-wrap">
          <input type="text" id="tienda-search" class="sede-search-input" autocomplete="off" placeholder="Selecciona primero el distrito…" aria-label="Buscar punto de recojo" value="<?= sanitize($tiendaActual) ?>" disabled>
          <div class="sede-dropdown" id="tienda-drop"></div>
        </div>
        <input type="hidden" name="tienda_destino" id="tienda-val" value="<?= sanitize($tiendaActual) ?>">
        <input type="text" id="tienda-manual" name="tienda_destino" style="display:none;" disabled
               placeholder="Escribe el punto de recojo (tienda / oficina Olva)" maxlength="500">
        <?php if (isset($errors['tienda_destino'])): ?><div class="err-msg"><?= $errors['tienda_destino'] ?></div><?php endif; ?>
      </div>
      <!-- Sede manual (couriers sin integración): texto libre -->
      <div class="field" id="campo-sede-manual" style="display:none;">
        <label>Sede / punto de destino</label>
        <input type="text" name="sede_registro" id="sede-manual" value="<?= sanitize($sedeActual) ?>" placeholder="Ej: Agencia Av. La Marina 123, Lima" disabled>
      </div>
      <!-- Dirección de destino (texto libre, opcional) -->
      <div class="field" id="campo-direccion" style="display:none;">
        <label>Dirección de destino</label>
        <input type="text" name="direccion_destino" id="input-direccion" value="<?= sanitize($preload['direccion_destino'] ?? $_POST['direccion_destino'] ?? '') ?>" placeholder="Dirección completa de entrega">
      </div>
      <div class="field" id="campo-estado">
        <label>Estado del envío <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="estado_envio_id" id="sel-estado" class="<?= isset($errors['estado_envio_id'])?'error':'' ?>">
            <option value="">— Seleccionar estado —</option>
            <?php foreach ($estados as $est): ?>
            <option value="<?= $est['id'] ?>" <?= (($preload['estado_envio_id'] ?? $_POST['estado_envio_id'] ?? $estado_default)==$est['id'])?'selected':'' ?>><?= sanitize($est['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (isset($errors['estado_envio_id'])): ?><div class="err-msg"><?= $errors['estado_envio_id'] ?></div><?php endif; ?>
      </div>
      <div class="field" id="campo-orden" style="display:none;">
        <label id="lbl-orden">N° de Orden</label>
        <input type="text" name="numero_orden" id="input-orden" value="<?= sanitize($preload['numero_orden'] ?? $_POST['numero_orden'] ?? '') ?>" placeholder="Número de orden">
      </div>
      <div class="field">
        <label id="lbl-seguimiento">Código de seguimiento</label>
        <input type="text" name="codigo_envio" value="<?= sanitize($preload['codigo_envio'] ?? $_POST['codigo_envio'] ?? '') ?>" placeholder="Ej: OL-2025-001234">
      </div>
      <?php $codigo_recojo_auto = substr(date('Y'), 2) . substr(date('Y'), 0, 2); // e.g. 2026 → "2620" ?>
      <div class="field" id="campo-recojo" style="display:none;">
        <label id="lbl-recojo">Código de recojo</label>
        <input type="text" name="codigo_recojo" id="input-recojo"
               value="<?= sanitize($preload['codigo_recojo'] ?? $_POST['codigo_recojo'] ?? $codigo_recojo_auto) ?>"
               readonly style="background:var(--gris-bg);cursor:not-allowed;opacity:.75;"
               title="Código automático: año al revés (<?= $codigo_recojo_auto ?>)">
        <span style="font-size:11px;color:var(--gris-texto);">Generado automáticamente: año <?= date('Y') ?> → <?= $codigo_recojo_auto ?></span>
      </div>
      <div class="field" id="campo-fecha-envio">
        <label>Fecha de envío <span class="req">*</span></label>
        <input type="date" name="fecha_envio" id="input-fecha-envio" value="<?= sanitize($_POST['fecha_envio'] ?? date('Y-m-d')) ?>" class="<?= isset($errors['fecha_envio'])?'error':'' ?>">
        <?php if (isset($errors['fecha_envio'])): ?><div class="err-msg"><?= $errors['fecha_envio'] ?></div><?php endif; ?>
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
                 value="<?= sanitize($preload['destinatario_dni'] ?? $_POST['destinatario_dni'] ?? '') ?>" style="flex:1;"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();consultarDNI();}">
          <button type="button" onclick="consultarDNI()" id="btn-dni"
                  style="display:flex;align-items:center;gap:6px;padding:10px 14px;background:var(--azul);color:#fff;border:none;border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:background .15s;"
                  onmouseover="this.style.background='var(--azul-dark,#122870)'" onmouseout="this.style.background='var(--azul)'">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            Consultar DNI
          </button>
        </div>
        <div id="dni-msg" style="font-size:11px;margin-top:3px;display:none;"></div>
      </div>
      <div class="field">
        <label>Nombre del destinatario</label>
        <input type="text" name="destinatario" id="input-destinatario"
               placeholder="Se autocompleta al consultar DNI"
               value="<?= sanitize($preload['destinatario'] ?? $_POST['destinatario'] ?? '') ?>">
      </div>
    </div>
  </div>

  <!-- 6. Asignación -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Asignación
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

      <!-- Campo: Ejecutivo de ventas -->
      <div class="field">
        <label>Ejecutivo de ventas</label>
        <?php if ($user['rol'] === 'ejecutivo_ventas'): ?>
          <?php $ej_p = array_filter($ejecutivos, fn($e) => $e['id'] == $user['id']); $ej_p = reset($ej_p); ?>
          <input type="hidden" name="ejecutivo_id" value="<?= $user['id'] ?>">
          <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s-ok-bg);border:1.5px solid #b7e4c0;border-radius:9px;">
            <div style="width:28px;height:28px;border-radius:50%;background:#1D9E75;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;"><?= $ej_p ? strtoupper(substr($ej_p['nombre'],0,1).substr($ej_p['apellido'],0,1)) : '?' ?></div>
            <span style="font-size:13px;font-weight:600;color:#229133;"><?= $ej_p ? sanitize($ej_p['nombre'].' '.$ej_p['apellido']) : 'Tú' ?></span>
            <span style="margin-left:auto;font-size:11px;color:#0F6E56;background:var(--s-ok-bg);padding:2px 8px;border-radius:100px;">Auto</span>
          </div>
        <?php elseif ($user['rol'] === 'gestor_logistico'): ?>
          <!-- Gestor no puede asignar ejecutivo — campo solo lectura -->
          <input type="hidden" name="ejecutivo_id" value="">
          <div style="padding:10px 14px;background:var(--s-info-bg);border:1.5px solid #e2e6f0;border-radius:9px;font-size:13px;color:var(--gris-texto);">— Sin asignar —</div>
          <span style="font-size:11px;color:var(--gris-texto);">Solo el ejecutivo de ventas puede asignarse.</span>
        <?php else: ?>
          <div class="select-wrap">
            <select name="ejecutivo_id">
              <option value="">— Sin asignar —</option>
              <?php foreach ($ejecutivos as $ej): ?>
              <option value="<?= $ej['id'] ?>" <?= ($data['ejecutivo_id']==$ej['id'])?'selected':'' ?>><?= sanitize($ej['nombre'].' '.$ej['apellido']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>

      <!-- Campo: Gestor logístico -->
      <div class="field">
        <label>Gestor logístico</label>
        <?php if ($user['rol'] === 'gestor_logistico'): ?>
          <?php $gl_p = array_filter($gestores, fn($g) => $g['id'] == $user['id']); $gl_p = reset($gl_p); ?>
          <input type="hidden" name="agente_id" value="<?= $user['id'] ?>">
          <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s-ok-bg);border:1.5px solid #b7e4c0;border-radius:9px;">
            <div style="width:28px;height:28px;border-radius:50%;background:#1D9E75;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;"><?= $gl_p ? strtoupper(substr($gl_p['nombre'],0,1).substr($gl_p['apellido'],0,1)) : '?' ?></div>
            <span style="font-size:13px;font-weight:600;color:#229133;"><?= $gl_p ? sanitize($gl_p['nombre'].' '.$gl_p['apellido']) : 'Tú' ?></span>
            <span style="margin-left:auto;font-size:11px;color:#0F6E56;background:var(--s-ok-bg);padding:2px 8px;border-radius:100px;">Auto</span>
          </div>
        <?php else: ?>
          <!-- Ejecutivos de ventas Y gerentes pueden asignar gestor -->
          <div class="select-wrap">
            <select name="agente_id">
              <option value="">— Sin asignar —</option>
              <?php foreach ($gestores as $g): ?>
              <option value="<?= $g['id'] ?>" <?= (($data['agente_id'] ?? null)==$g['id'])?'selected':'' ?>><?= sanitize($g['nombre'].' '.$g['apellido']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- 7. Comprobante -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
      Comprobante de envío
    </div>
    <div class="form-grid">
      <div class="field span-2">
        <p style="font-size:11px;color:var(--gris-texto);margin:0 0 6px;">Opcional. Adjunta una foto o PDF del comprobante:</p>
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

  <!-- 8. Observaciones -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      Observaciones
    </div>
    <div class="field">
      <textarea name="observaciones" placeholder="Notas adicionales sobre este envío..."><?= sanitize($preload['observaciones'] ?? $_POST['observaciones'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Guardar envío
    </button>
    <?php if (!$_ggi_embed): ?>
    <a href="<?= $back_url ?>" class="btn btn-outline">Cancelar</a>
    <?php endif; ?>
  </div>
</div>
</form>

<script>
var APP_URL = '<?= APP_URL ?>';
var selectedCerts = new Set(<?= json_encode(array_map('intval', $_POST['certificados_ids'] ?? $preload['cert_ids'] ?? [])) ?>);

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
  cargarUltimoEnvio(sel.value);
}

// ── Autocompletar con el último envío del cliente ──
var _ultimoEnvio = null;
function cargarUltimoEnvio(clienteId) {
  const chip = document.getElementById('ultimo-envio-chip');
  _ultimoEnvio = null;
  if (chip) chip.style.display = 'none';
  if (!clienteId) return;
  fetch(`${APP_URL}/modules/envios/ajax_ultimo_envio.php?cliente_id=${clienteId}`)
    .then(r => r.json())
    .then(d => {
      if (!d.ok || !d.data) return;
      _ultimoEnvio = d.data;
      const resumen = [d.data.agencia, d.data.sede_registro || d.data.direccion_destino, d.data.destinatario]
        .filter(Boolean).join(' · ');
      document.getElementById('ultimo-envio-texto').textContent = resumen || 'último envío';
      if (chip) chip.style.display = 'flex';
    })
    .catch(() => {});
}

async function usarUltimoEnvio() {
  const d = _ultimoEnvio;
  if (!d) return;
  const selAg = document.getElementById('sel-agencia');
  if (!selAg) return;
  // Fijar valores que onAgenciaChange preseleccionará al cargar sedes/tienda
  selAg.value = String(d.agencia_envio_id);
  document.getElementById('sede-val').value    = d.sede_registro || '';
  document.getElementById('tienda-val').value  = d.tienda_destino || '';
  // Render de campos + carga de sedes (y, si Olva, tienda) — como init para preservar valores
  await onAgenciaChange(String(d.agencia_envio_id), true);
  // Sede manual (couriers sin integración) y dirección
  const sm = document.getElementById('sede-manual');
  if (sm && sm.offsetParent !== null) sm.value = d.sede_registro || '';
  const idr = document.getElementById('input-direccion');
  if (idr && !idr.disabled) idr.value = d.direccion_destino || '';
  // Destinatario y DNI
  const idest = document.getElementById('input-destinatario'); if (idest) idest.value = d.destinatario || '';
  const idni  = document.getElementById('input-dni');          if (idni)  idni.value  = d.destinatario_dni || '';
  const chip = document.getElementById('ultimo-envio-chip'); if (chip) chip.style.display = 'none';
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
        <div class="cert-check-item" style="opacity:0.5;cursor:not-allowed;background:var(--bg-surface2);">
          <input type="checkbox" disabled style="cursor:not-allowed;">
          <span class="cert-check-norma">${c.codigo}</span>
          <span style="font-size:13px;font-weight:600;color:var(--gris-dark);flex:1;">${c.numero || c.norma_nombre || '—'}</span>
          <span style="font-size:11px;color:var(--s-warn-tx);font-weight:600;white-space:nowrap;">Ya enviado</span>
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

async function onAgenciaChange(agenciaId, isInit) {
  if (!agenciaId) return;
  // Config desde los data-* del <option> (server-rendered) → instantáneo y sin depender de AJAX.
  const _sel = document.getElementById('sel-agencia');
  const _opt = _sel ? _sel.querySelector('option[value="'+agenciaId+'"]') : null;
  const cfg = {
    tiene_numero_orden:  _opt ? _opt.dataset.orden  === '1' : false,
    tiene_codigo_recojo: _opt ? _opt.dataset.recojo === '1' : false,
    tiene_sede: true,
    sede_modo: (_opt && _opt.dataset.sedeModo) ? _opt.dataset.sedeModo : 'manual',
    label_numero_orden:       (_opt && _opt.dataset.lblOrden)  ? _opt.dataset.lblOrden  : 'N° de Orden',
    label_codigo_recojo:      (_opt && _opt.dataset.lblRecojo) ? _opt.dataset.lblRecojo : 'Código de recojo',
    label_codigo_seguimiento: (_opt && _opt.dataset.lblSeg)    ? _opt.dataset.lblSeg    : 'Código de seguimiento',
  };
  // ¿Es Olva? → tras elegir el distrito se habilita el selector de punto de recojo (tienda)
  _esOlva = !!(_opt && _opt.dataset.olva === '1');
  const _tval = document.getElementById('tienda-val');
  if (isInit && _esOlva && _tval && _tval.value) {
    // recarga (error de validación) con tienda ya elegida → conservarla (buscador con el valor)
    document.getElementById('campo-tienda').style.display = 'flex';
    const _ts = document.getElementById('tienda-search');
    if (_ts) { _ts.disabled = false; _ts.value = _tval.value; _ts.placeholder = 'Buscar punto de recojo…'; }
  } else {
    resetTiendaOlva();  // ocultar/limpiar hasta que se elija una sede
  }

  const campoOrden  = document.getElementById('campo-orden');
  const campoRecojo = document.getElementById('campo-recojo');
  const campoSede   = document.getElementById('campo-sede');
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
  }
  const campoSedeManual = document.getElementById('campo-sede-manual');
  const campoDireccion  = document.getElementById('campo-direccion');
  const sedeVal    = document.getElementById('sede-val');
  const sedeManual = document.getElementById('sede-manual');
  const inputDir   = document.getElementById('input-direccion');
  // Dirección de destino: solo para couriers SIN integración (modo manual).
  // En integraciones (Olva/Shalom) el destino se define por la sede, no por dirección libre.
  const dirPermitida = !(cfg.tiene_sede && cfg.sede_modo === 'api');
  if (campoDireccion) {
    campoDireccion.style.display = dirPermitida ? 'flex' : 'none';
    if (inputDir) { inputDir.disabled = !dirPermitida; if (!dirPermitida) inputDir.value = ''; }
  }

  if (cfg.tiene_sede && cfg.sede_modo === 'api') {
    // ── Modo API: buscador de sedes (Olva/Shalom) ──
    campoSede.style.display = 'flex';
    if (campoSedeManual) campoSedeManual.style.display = 'none';
    if (sedeManual) sedeManual.disabled = true;          // no enviar el manual
    if (sedeVal)    sedeVal.disabled = false;
    const searchInput = document.getElementById('sede-search');
    searchInput.value = '';
    searchInput.placeholder = 'Cargando sedes...';
    searchInput.disabled = true;
    fetch(`${APP_URL}/modules/envios/ajax_sedes.php?agencia_id=${agenciaId}`)
      .then(res => res.json())
      .then(data => {
        searchInput.disabled = false;
        searchInput.placeholder = 'Buscar sede...';
        const preSede = document.getElementById('sede-val').value;
        initSedeDropdown(data.sedes, preSede);
        // Olva: si hay sede + tienda prefijadas (autocompletar/recarga), cargar la lista del distrito y conservar la tienda
        if (_esOlva) {
          const preTienda = document.getElementById('tienda-val').value;
          if (preSede && preTienda) {
            const _m = (data.sedes || []).find(s => s.valor === preSede);
            loadTiendasOlva(_m ? _m.ubigeo : '', preTienda);
          }
        }
      })
      .catch(() => { searchInput.placeholder = 'Error al cargar'; });
  } else if (cfg.tiene_sede) {
    // ── Modo manual: sede de texto libre (couriers sin integración) ──
    campoSede.style.display = 'none';
    resetSedeDropdown();
    if (sedeVal) sedeVal.disabled = true;                // desactivar el hidden de API
    if (campoSedeManual) campoSedeManual.style.display = 'flex';
    if (sedeManual) sedeManual.disabled = false;
  } else {
    campoSede.style.display = 'none';
    resetSedeDropdown();
    if (campoSedeManual) campoSedeManual.style.display = 'none';
    if (sedeVal)    sedeVal.disabled = true;
    if (sedeManual) sedeManual.disabled = true;
  }
  // Re-aplicar el estado del toggle "sin datos" si está activo
  var _sd = document.getElementById('chk-sin-datos');
  if (_sd && _sd.checked) onSinDatosToggle(_sd);
}

// "No compartió datos de envío": anula/atenúa los datos de destino, destinatario y
// seguimiento; deja solo la observación (obligatoria en ese caso).
function onSinDatosToggle(chk) {
  const off = chk.checked;
  ['campo-sede','campo-tienda','campo-sede-manual','campo-direccion','campo-orden','campo-recojo',
   'campo-estado','campo-fecha-envio'].forEach(function(id){
    const el = document.getElementById(id); if (el) el.style.opacity = off ? '.45' : '';
  });
  // Inputs de datos del cliente: limpiar + deshabilitar
  ['sede-search','input-direccion','input-destinatario','input-dni','input-orden'].forEach(function(id){
    const el = document.getElementById(id); if (el) { if (off) el.value = ''; el.disabled = off; }
  });
  const cod = document.querySelector('[name="codigo_envio"]');
  if (cod) { if (off) cod.value = ''; cod.disabled = off; }
  // Sede: cuando se anula, deshabilitar ambos para que no se envíe nada
  const sv = document.getElementById('sede-val'), sm = document.getElementById('sede-manual');
  if (off) { if (sv) { sv.value=''; sv.disabled = true; } if (sm) { sm.value=''; sm.disabled = true; } }
  // Punto de recojo Olva: limpiar/deshabilitar cuando no se comparten datos
  const tval = document.getElementById('tienda-val');
  if (tval && off) { tval.value=''; tval.disabled = true; }
  const tsrch = document.getElementById('tienda-search');
  if (tsrch && off) { tsrch.value=''; tsrch.disabled = true; }
  const tman = document.getElementById('tienda-manual');
  if (tman && off) { tman.value=''; tman.disabled = true; }
  // Estado y fecha de envío: no hay envío que rastrear → se bloquean en valores neutros,
  // pero quedan habilitados (no readonly el select) para que el form envíe un valor válido.
  const est = document.getElementById('sel-estado');
  if (est) {
    if (off) {
      const pp = Array.from(est.options).find(function(o){ return /por preparar/i.test(o.textContent); });
      if (pp) est.value = pp.value;
      else est.selectedIndex = est.querySelector('option[value=""]') ? 1 : 0;
    }
    est.style.pointerEvents = off ? 'none' : ''; est.tabIndex = off ? -1 : 0;
  }
  const fe = document.getElementById('input-fecha-envio');
  if (fe) { if (off && !fe.value) fe.value = new Date().toISOString().slice(0,10); fe.readOnly = off; fe.style.pointerEvents = off ? 'none' : ''; }
  // Observación obligatoria en este modo
  const obs = document.querySelector('[name="observaciones"]');
  if (obs) { obs.required = off; if (off) setTimeout(function(){ obs.focus(); }, 50); }
  // Al reactivar, restaurar la lógica de sede según la agencia elegida
  if (!off) {
    const sel = document.getElementById('sel-agencia');
    if (sel && sel.value) onAgenciaChange(sel.value);
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
  btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Consultando...'; btn.disabled = true; msg.style.display = 'none';
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
  finally { btn.innerHTML='<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg> Consultar DNI'; btn.disabled=false; }
}

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

// ── Autocomplete empresa ──
var CLIENTES_ENV = <?= json_encode(array_map(fn($cl) => ['id'=>$cl['id'], 'label'=>($cl['ruc']?$cl['ruc'].' — ':'').$cl['razon_social']], $clientes), JSON_UNESCAPED_UNICODE) ?>;
initSearchSelect({
  textInputId:    'empresa-search-input',
  dropdownId:     'empresa-dropdown',
  hiddenSelectId: 'sel-cliente',
  data: CLIENTES_ENV
});

ggiReady(() => {
  const clienteId = document.getElementById('sel-cliente').value;
  if (clienteId) cargarCertificados(clienteId);
  const agenciaId = document.getElementById('sel-agencia').value;
  if (agenciaId) onAgenciaChange(agenciaId, true);
  // Chip "usar último envío" solo si aún no hay agencia elegida (form vacío)
  if (clienteId && !agenciaId) cargarUltimoEnvio(clienteId);
  const chkDir = document.getElementById('chk-dir');
  if (chkDir && chkDir.checked) onDirCheck(chkDir);
  const sd = document.getElementById('chk-sin-datos');
  if (sd && sd.checked) onSinDatosToggle(sd);
});

// ── Sede searchable dropdown ──────────────────────────────────────────────────
var _sedeAll = [];
var _esOlva  = false;

function initSedeDropdown(sedes, preVal) {
  _sedeAll = sedes;
  const inp  = document.getElementById('sede-search');
  const drop = document.getElementById('sede-dropdown');
  const val  = document.getElementById('sede-val');
  const lbl  = document.getElementById('sede-selected-label');

  // Si ya hay un valor guardado, mostrarlo en el input
  if (preVal) {
    inp.value = preVal;
    lbl.textContent = preVal;
    lbl.style.display = '';
  }

  inp.oninput = function() {
    renderSedeOptions(inp.value.trim());
    if (!inp.value.trim()) { val.value = ''; lbl.style.display = 'none'; }
    drop.classList.add('open');
  };
  inp.onfocus = function() {
    renderSedeOptions(inp.value.trim());
    drop.classList.add('open');
  };
  inp.onkeydown = function(e) {
    const items = drop.querySelectorAll('.sede-opt');
    const cur   = drop.querySelector('.sede-opt.focused');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      const next = cur ? cur.nextElementSibling : items[0];
      if (next) { cur?.classList.remove('focused'); next.classList.add('focused'); next.scrollIntoView({block:'nearest'}); }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      const prev = cur ? cur.previousElementSibling : items[items.length - 1];
      if (prev) { cur?.classList.remove('focused'); prev.classList.add('focused'); prev.scrollIntoView({block:'nearest'}); }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (cur) cur.click();
    } else if (e.key === 'Escape') {
      drop.classList.remove('open');
    }
  };

  document.addEventListener('click', function sedeOutside(e) {
    if (!document.getElementById('sede-wrap')?.contains(e.target)) {
      drop.classList.remove('open');
    }
  }, { capture: true });
}

function renderSedeOptions(q) {
  const drop = document.getElementById('sede-dropdown');
  const qLow = q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const matches = q ? _sedeAll.filter(s => {
    const hay = s.label.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    return hay.includes(qLow);
  }) : _sedeAll;

  if (!matches.length) {
    drop.innerHTML = '<div class="sede-empty">Sin resultados para "' + q + '"</div>';
    return;
  }
  drop.innerHTML = matches.slice(0, 80).map(s => {
    const hi = q ? s.label.replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi'), '<mark>$1</mark>') : s.label;
    return `<div class="sede-opt" data-val="${s.valor.replace(/"/g,'&quot;')}" data-ubigeo="${s.ubigeo||''}">${hi}</div>`;
  }).join('');
  drop.querySelectorAll('.sede-opt').forEach(opt => {
    opt.addEventListener('click', function() {
      const v = this.dataset.val;
      document.getElementById('sede-val').value = v;
      document.getElementById('sede-search').value = v;
      const lbl = document.getElementById('sede-selected-label');
      lbl.textContent = v; lbl.style.display = '';
      drop.classList.remove('open');
      // Olva: cargar todas las tiendas del distrito elegido
      if (_esOlva) loadTiendasOlva(this.dataset.ubigeo);
    });
  });
}

// ── Puntos de recojo Olva (buscador, mismo componente que Sede) ──
// Alterna entre el buscador (API) y un input de texto libre (fallback si la API falla).
var _tiendaAll = [];
function setTiendaManual(on, placeholder) {
  const wrap = document.getElementById('tienda-wrap');
  const man  = document.getElementById('tienda-manual');
  const val  = document.getElementById('tienda-val');
  if (on) {
    if (wrap) wrap.style.display = 'none';
    if (val)  val.disabled = true;               // no enviar el hidden
    if (man)  { man.style.display = ''; man.disabled = false; if (placeholder) man.placeholder = placeholder; }
  } else {
    if (wrap) wrap.style.display = '';
    if (val)  val.disabled = false;
    if (man)  { man.style.display = 'none'; man.disabled = true; man.value = ''; }
  }
}
function resetTiendaOlva() {
  const campo = document.getElementById('campo-tienda');
  if (campo) campo.style.display = 'none';
  _tiendaAll = [];
  const s = document.getElementById('tienda-search');
  if (s) { s.value = ''; s.disabled = true; s.placeholder = 'Selecciona primero el distrito…'; }
  const v = document.getElementById('tienda-val'); if (v) v.value = '';
  const d = document.getElementById('tienda-drop'); if (d) { d.innerHTML = ''; d.classList.remove('open'); }
  setTiendaManual(false);
}
function loadTiendasOlva(ubigeo, preSel) {
  const campo = document.getElementById('campo-tienda');
  const s     = document.getElementById('tienda-search');
  const v     = document.getElementById('tienda-val');
  if (!campo || !s) return;
  campo.style.display = 'flex';
  setTiendaManual(false);
  if (v) v.value = '';
  s.value = ''; s.disabled = true; s.placeholder = 'Cargando puntos de recojo…';
  if (!ubigeo) { setTiendaManual(true, 'Escribe el punto de recojo (distrito sin código)'); if (preSel) document.getElementById('tienda-manual').value = preSel; return; }
  fetch(`${APP_URL}/modules/envios/ajax_tiendas_olva.php?ubigeo=${encodeURIComponent(ubigeo)}`)
    .then(r => r.json())
    .then(d => {
      const items = d.tiendas || [];
      if (!items.length) { setTiendaManual(true, 'Sin tiendas en el distrito: escribe el punto de recojo'); if (preSel) document.getElementById('tienda-manual').value = preSel; return; }
      _tiendaAll = items.map(t => t.nombre || '').filter(Boolean);
      s.disabled = false; s.placeholder = 'Buscar punto de recojo…';
      initTiendaDropdown();
      if (preSel) {
        if (_tiendaAll.indexOf(preSel) !== -1) { if (v) v.value = preSel; s.value = preSel; }
        else { setTiendaManual(true); document.getElementById('tienda-manual').value = preSel; }
      }
    })
    .catch(() => { setTiendaManual(true, 'Olva no disponible: escribe el punto de recojo'); if (preSel) document.getElementById('tienda-manual').value = preSel; });
}
function renderTiendaOptions(q) {
  const drop = document.getElementById('tienda-drop');
  const val  = document.getElementById('tienda-val');
  const qLow = q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
  const matches = q ? _tiendaAll.filter(n => n.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').includes(qLow)) : _tiendaAll;
  if (!matches.length) { drop.innerHTML = '<div class="sede-empty">Sin resultados</div>'; return; }
  drop.innerHTML = matches.slice(0,60).map(n => {
    const hi = q ? n.replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>') : n;
    return `<div class="sede-opt" data-val="${n.replace(/"/g,'&quot;')}">${hi}</div>`;
  }).join('');
  drop.querySelectorAll('.sede-opt').forEach(opt => {
    opt.addEventListener('click', function() {
      val.value = this.dataset.val;
      document.getElementById('tienda-search').value = this.dataset.val;
      drop.classList.remove('open');
    });
  });
}
function initTiendaDropdown() {
  const inp  = document.getElementById('tienda-search');
  const drop = document.getElementById('tienda-drop');
  const val  = document.getElementById('tienda-val');
  inp.oninput = function() { renderTiendaOptions(inp.value.trim()); if (!inp.value.trim()) val.value = ''; drop.classList.add('open'); };
  inp.onfocus = function() { renderTiendaOptions(inp.value.trim()); drop.classList.add('open'); };
  document.addEventListener('click', function(e) {
    if (!document.getElementById('tienda-wrap')?.contains(e.target)) drop.classList.remove('open');
  }, { capture: true });
}

function resetSedeDropdown() {
  _sedeAll = [];
  document.getElementById('sede-search').value = '';
  document.getElementById('sede-val').value = '';
  const lbl = document.getElementById('sede-selected-label');
  if (lbl) { lbl.textContent = ''; lbl.style.display = 'none'; }
  const drop = document.getElementById('sede-dropdown');
  if (drop) { drop.innerHTML = ''; drop.classList.remove('open'); }
}
</script>
<script>
// validacion.js (define GGIForm) se carga vía layout_end, DESPUÉS de este inline →
// diferir el init a DOMContentLoaded si GGIForm aún no existe. Sin esto, la validación
// de cliente quedaba muerta (ReferenceError silencioso). Nota: NO se exige 'destinatario'
// (el servidor tampoco lo exige: hay envíos "a domicilio"/"por asignar" sin destinatario).
function initEnvioValidacion() {
  new GGIForm('form-envio-crear')
    .selectRequired('cliente_id',      'Selecciona un cliente.')
    .selectRequired('agencia_envio_id','Selecciona una agencia de envío.')
    .selectRequired('estado_envio_id', 'Selecciona el estado del envío.')
    .dateRequired('fecha_envio',       'La fecha de envío es requerida.')
    .init();
}
if (typeof GGIForm === 'undefined') document.addEventListener('DOMContentLoaded', initEnvioValidacion, { once: true });
else initEnvioValidacion();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
