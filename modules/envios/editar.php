<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/envio_notificar.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gestor_logistico','delivery');
// Evita que el modal/PWA sirva una versión cacheada del formulario (JS viejo).
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
$user = auth_user();

$id   = (int)($_GET['id'] ?? 0);
$from = $_GET['from'] ?? $_POST['from'] ?? 'detalle';
$_ggi_embed = !empty($_GET['embed']) || !empty($_POST['_embed']);  // abrir dentro del modal (iframe)
if (!$id) { header('Location: ' . APP_URL . '/modules/envios/index.php'); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT e.*, cl.razon_social, cl.ruc, cl.direccion, cl.ubigeo,
           co.numero AS numero_cotizacion,
           est.nombre AS estado_nombre, est.color_hex AS estado_color,
           CONCAT(uru.nombre,' ',uru.apellido) AS registrado_nombre
    FROM envios e
    JOIN clientes cl ON cl.id = e.cliente_id
    LEFT JOIN cotizaciones co   ON co.id  = e.cotizacion_id
    LEFT JOIN estados_envio est ON est.id = e.estado_envio_id
    LEFT JOIN usuarios uru      ON uru.id = e.registrado_por
    WHERE e.id = ?
");
$stmt->execute([$id]);
$env = $stmt->fetch();
$ejecutivos = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='ejecutivo_ventas' ORDER BY nombre")->fetchAll();
$gestores   = $pdo->query("SELECT id, nombre, apellido FROM usuarios WHERE activo=1 AND rol='gestor_logistico' ORDER BY nombre")->fetchAll();
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
    SELECT c.id, n.codigo AS norma_codigo, n.nombre AS norma_nombre, c.numero_certificado,
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
$agencias = $pdo->query("SELECT id, nombre, sede_modo, es_domicilio FROM agencias_envio WHERE activo=1 ORDER BY nombre")->fetchAll();
$estados  = $pdo->query("SELECT id, nombre, color_hex FROM estados_envio WHERE activo=1 ORDER BY orden")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $usa_dir_fiscal   = isset($_POST['usa_dir_fiscal']);
    $sin_datos        = isset($_POST['sin_datos_envio']);
    $certificados_ids = array_filter(array_map('intval', $_POST['certificados_ids'] ?? []));

    $data = [
        'agencia_envio_id'  => (int)($_POST['agencia_envio_id'] ?? 0),
        'estado_envio_id'   => (int)($_POST['estado_envio_id'] ?? 0),
        'codigo_envio'      => trim($_POST['codigo_envio'] ?? ''),
        'numero_orden'      => trim($_POST['numero_orden'] ?? ''),
        'codigo_recojo'     => trim($_POST['codigo_recojo'] ?? ''),
        'sede_registro'     => trim($_POST['sede_registro'] ?? ''),
        'tienda_destino'    => trim($_POST['tienda_destino'] ?? ''),
        'fecha_envio'       => $_POST['fecha_envio'] ?? '',
        'fecha_recibido'    => $_POST['fecha_recibido'] ?? '',
        'direccion_destino' => $usa_dir_fiscal ? '' : trim($_POST['direccion_destino'] ?? ''),
        'destinatario'      => trim($_POST['destinatario'] ?? ''),
        'destinatario_dni'  => trim($_POST['destinatario_dni'] ?? ''),
        'referencia_direccion' => trim($_POST['referencia_direccion'] ?? ''),
        'lat'               => ($_POST['lat'] ?? '') !== '' ? (float) $_POST['lat'] : null,
        'lng'               => ($_POST['lng'] ?? '') !== '' ? (float) $_POST['lng'] : null,
        'observaciones'     => trim($_POST['observaciones'] ?? ''),
        'sin_datos_envio'   => $sin_datos ? 1 : 0,
        'ejecutivo_id'      => ($user['rol'] === 'gestor_logistico' ? $env['ejecutivo_id'] : ($user['rol'] === 'ejecutivo_ventas' ? $env['ejecutivo_id'] : ((int)($_POST['ejecutivo_id'] ?? 0) ?: null))),
        'agente_id'         => ($user['rol'] === 'gestor_logistico' ? $env['agente_id'] : ((int)($_POST['agente_id'] ?? 0) ?: null)),
    ];

    // Integraciones (sede_modo='api'): el destino se define por la sede, NO por dirección libre.
    $_ag_row = array_values(array_filter($agencias, fn($a) => (int)$a['id'] === $data['agencia_envio_id']))[0] ?? null;
    $_ag_es_olva = $_ag_row && stripos($_ag_row['nombre'] ?? '', 'olva') !== false;
    if ($_ag_row && ($_ag_row['sede_modo'] ?? 'manual') === 'api') {
        $data['direccion_destino'] = '';
        $usa_dir_fiscal = false;
    }

    // "Lima a domicilio": el destino es dirección + punto en el mapa (lat/lng).
    $_ag_es_domicilio = $_ag_row && (int) ($_ag_row['es_domicilio'] ?? 0) === 1;
    if ($_ag_es_domicilio) {
        $data['sede_registro'] = ''; $data['tienda_destino'] = '';
        $usa_dir_fiscal = false;
    } else {
        $data['lat'] = null; $data['lng'] = null; $data['referencia_direccion'] = '';
    }

    // "No compartió datos de envío": anular datos de destino/destinatario/seguimiento.
    // Estado y fechas no aplican (no hay envío que rastrear) → valores neutros, no editables.
    if ($sin_datos) {
        $usa_dir_fiscal = false;
        foreach (['codigo_envio','numero_orden','codigo_recojo','sede_registro','tienda_destino',
                  'direccion_destino','destinatario','destinatario_dni','fecha_recibido'] as $_k) {
            $data[$_k] = '';
        }
        $_st_pp = array_filter($estados, fn($e) => strtolower(trim($e['nombre'])) === 'por preparar');
        $data['estado_envio_id'] = $_st_pp ? reset($_st_pp)['id'] : ($estados[0]['id'] ?? $data['estado_envio_id']);
        if (empty($data['fecha_envio'])) $data['fecha_envio'] = date('Y-m-d');
    }

    // Couriers con tracking (Olva/Shalom/Serpost): el estado lo maneja la sincronización
    // automática → se ignora cualquier cambio manual de estado enviado en el formulario.
    if (!$sin_datos && $_ag_row && gg_courier_con_tracking($_ag_row['nombre'] ?? '')) {
        $data['estado_envio_id'] = (int)($env['estado_envio_id'] ?? $data['estado_envio_id']);
    }

    // Subir comprobante
    $comprobante_archivo = $env['comprobante_archivo'] ?? null;
    if (!empty($_FILES['comprobante']['name'])) {
        $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','webp'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($_FILES['comprobante']['tmp_name']);
        $pdf_mimes     = ['application/pdf','application/x-pdf','application/acrobat','application/vnd.pdf','text/pdf','text/x-pdf'];
        $allowed_mimes = array_merge(['image/jpeg','image/png','image/webp'], $pdf_mimes);
        $mime_ok = in_array($mime, $allowed_mimes) || ($ext === 'pdf' && $mime === 'application/octet-stream');
        if (in_array($ext, $allowed) && $mime_ok && $_FILES['comprobante']['size'] <= 10*1024*1024) {
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

    // "No Enviado" exige motivo estructurado (visible para todo el equipo)
    $_nombre_estado_sel = '';
    foreach ($estados as $_st) { if ((int)$_st['id'] === (int)$data['estado_envio_id']) { $_nombre_estado_sel = $_st['nombre']; break; } }
    $_es_no_enviado   = gg_es_estado_no_enviado($_nombre_estado_sel);
    $_motivo_ne       = trim($_POST['motivo_no_envio'] ?? '');
    $_motivo_ne_det   = mb_substr(trim($_POST['motivo_no_envio_detalle'] ?? ''), 0, 500);
    if ($_es_no_enviado) {
        if (!isset(gg_motivos_no_envio()[$_motivo_ne])) {
            $errors['motivo_no_envio'] = 'Indica el motivo por el que no se envió.';
        } elseif ($_motivo_ne === 'otro' && $_motivo_ne_det === '') {
            $errors['motivo_no_envio'] = 'Describe el motivo en el detalle.';
        }
    }
    if (empty($data['fecha_envio'])) $errors['fecha_envio']      = 'Fecha de envío requerida.';
    if (empty($certificados_ids))    $errors['certificados_ids'] = 'Selecciona al menos un certificado.';
    if ($sin_datos && $data['observaciones'] === '') $errors['observaciones'] = 'Indica una observación (el cliente no compartió datos de envío).';
    if ($_ag_es_olva && !$sin_datos && $data['tienda_destino'] === '') $errors['tienda_destino'] = 'Selecciona el punto de recojo de Olva.';

    if ($usa_dir_fiscal) {
        $data['direccion_destino'] = trim(($env['direccion'] ?? '') . ' ' . ($env['ubigeo'] ?? ''));
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
            UPDATE envios SET agencia_envio_id=?, sede_registro=?, tienda_destino=?, estado_envio_id=?, codigo_envio=?,
              numero_orden=?, codigo_recojo=?, fecha_envio=?, fecha_recibido=?,
              direccion_destino=?, destinatario=?, destinatario_dni=?, observaciones=?, sin_datos_envio=?,
              comprobante_archivo=?, ejecutivo_id=?, agente_id=?,
              motivo_no_envio=?, motivo_no_envio_detalle=?,
              lat=?, lng=?, referencia_direccion=?
            WHERE id=?
        ")->execute([
            $data['agencia_envio_id'],
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
            $data['ejecutivo_id']      ?: null,
            $data['agente_id']         ?: null,
            $_es_no_enviado ? $_motivo_ne : null,
            ($_es_no_enviado && $_motivo_ne_det !== '') ? $_motivo_ne_det : null,
            $data['lat'],
            $data['lng'],
            $data['referencia_direccion'] ?: null,
            $id
        ]);

        // Actualizar certificados: eliminar todos y reinsertar
        $pdo->prepare("DELETE FROM envios_certificados WHERE envio_id=?")->execute([$id]);
        $stmt_cert = $pdo->prepare("INSERT INTO envios_certificados (envio_id, certificado_id) VALUES (?,?)");
        foreach ($certificados_ids as $cert_id) {
            $stmt_cert->execute([$id, $cert_id]);
        }

        // Notificar al cliente si el estado cambió respecto al anterior
        $estado_anterior = (int)($env['estado_envio_id'] ?? 0);
        if ((int)$data['estado_envio_id'] !== $estado_anterior) {
            gg_envio_notificar_estado($pdo, $id, (int)$data['estado_envio_id'], $user['id'] ?? null);
        }

        // Push/interna al gerente general SOLO en la transición "sin datos" → "con datos completos"
        $antes_incompleto = empty($env['agencia_envio_id']) || trim((string)($env['destinatario'] ?? '')) === '';
        $ahora_completo   = !$data['sin_datos_envio'] && (int)$data['agencia_envio_id'] > 0 && trim((string)$data['destinatario']) !== '';
        if ($antes_incompleto && $ahora_completo) {
            try {
                NotificationService::dispatch('envio_datos_registrados', [
                    'id'         => (int)$id,
                    'cliente_id' => (int)$env['cliente_id'],
                    'origen'     => 'sistema',
                    'triggered_by' => $user['id'] ?? null,
                    'title'      => 'Datos de envío registrados',
                    'message'    => "Se registraron los datos de envío de {$env['razon_social']} desde el sistema.",
                    'push_body'  => "Datos de envío de {$env['razon_social']} registrados (sistema).",
                ]);
            } catch (\Throwable $e) {
                error_log('[envios/editar] notif datos error: ' . $e->getMessage());
            }
        }

        log_actividad('envios', 'editar', 'Envío actualizado ID: ' . $id);
        flash('Envío actualizado correctamente.');
        if ($_ggi_embed) {
            ?><!DOCTYPE html><html><body><script>
            window.parent.postMessage({ggi:'updated',module:'envios'},'*');
            </script></body></html><?php
            exit;
        }
        if ($from === 'listado') {
            header('Location: ' . APP_URL . '/modules/envios/index.php');
        } else {
            header('Location: ' . APP_URL . '/modules/envios/ver.php?id=' . $id);
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

// Config de la agencia GUARDADA → para mostrar el campo correcto ya en el render
// (robusto aunque el JS llegue cacheado o falle): api → muestra Sede; Olva → tienda.
$_saved_ag  = array_values(array_filter($agencias, fn($a) => (int)$a['id'] === (int)($data['agencia_envio_id'] ?? 0)))[0] ?? null;
$_saved_cfg = $_saved_ag ? ggi_agencia_envio_config($_saved_ag['nombre'], $_saved_ag['sede_modo'] ?? 'manual') : null;
$_saved_is_api  = $_saved_cfg && ($_saved_cfg['sede_modo'] ?? 'manual') === 'api';
$_saved_is_olva = $_saved_ag && stripos($_saved_ag['nombre'], 'olva') !== false;
$_saved_is_manual_sede = $_saved_cfg && ($_saved_cfg['sede_modo'] ?? 'manual') !== 'api';
?>
<style>
  .form-card { background:var(--bg-surface); border-radius:12px; border:1px solid var(--gris-borde); overflow:hidden; max-width:860px; }
  .form-section { padding:20px 24px; border-bottom:1px solid var(--gris-borde); }
  .form-section:last-child { border-bottom:none; }
  .section-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:var(--azul); margin-bottom:16px; display:flex; align-items:center; gap:8px; }
  .section-title svg { color:var(--verde); }
  .sindatos-toggle { display:flex; align-items:flex-start; gap:9px; padding:10px 13px; margin-bottom:14px; border:1px solid var(--s-warn-brd,#fcd34d); background:var(--s-warn-bg); border-radius:10px; font-size:12.5px; color:var(--gris-dark); cursor:pointer; }
  .sindatos-toggle input { width:16px; height:16px; margin-top:1px; flex-shrink:0; accent-color:var(--azul); cursor:pointer; }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .span-2 { grid-column:span 2; }
  .field { display:flex; flex-direction:column; gap:5px; }
  .field label { font-size:12px; font-weight:600; color:var(--azul); }
  .field .err-msg { font-size:11px; color:#ef4444; }
  .field input[type=text],.field input[type=date],.field textarea {
    padding:10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px;
    font-size:14px; font-family:'Inter',sans-serif; color:var(--gris-dark);
    background:var(--bg-surface2); outline:none; width:100%; transition:border-color 0.2s;
  }
  .field input:focus,.field textarea:focus { border-color:var(--azul); background:var(--bg-surface); }
  .field textarea { resize:vertical; min-height:70px; }
  .select-wrap { position:relative; }
  .select-wrap select { width:100%; padding:10px 30px 10px 12px; border:1.5px solid var(--gris-borde); border-radius:9px; font-size:14px; font-family:'Inter',sans-serif; color:var(--gris-dark); background-color:var(--bg-surface2); outline:none; cursor:pointer; }
  .select-wrap select:focus { border-color:var(--azul); }
  .dir-check { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:9px; border:1.5px solid var(--gris-borde); background:var(--bg-surface2); cursor:pointer; margin-bottom:10px; }
  .dir-check input[type=checkbox] { width:17px; height:17px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; }
  .dir-fiscal-preview { background:var(--s-info-bg); border:1px solid #b3c8f7; border-radius:9px; padding:10px 14px; font-size:13px; color:var(--azul); display:none; margin-bottom:6px; }
  .dropzone { border:2px dashed var(--gris-borde); border-radius:10px; padding:20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; overflow:hidden; }
  .dropzone:hover { border-color:var(--azul); background:var(--s-info-bg); }
  .dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; margin:0; padding:0; }
  .file-actual { display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--s-ok-bg); border:1px solid #b7e4c0; border-radius:9px; margin-bottom:8px; min-width:0; overflow:hidden; }
  .file-actual-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .file-info { display:none; align-items:center; gap:8px; padding:10px 14px; background:var(--s-info-bg); border:1px solid #b3c8f7; border-radius:9px; margin-top:6px; min-width:0; }
  .file-info.show { display:flex; }
  .file-info-name { flex:1; font-size:12px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; min-width:0; }
  .form-actions { padding:16px 24px; background:var(--bg-surface2); border-top:1px solid var(--gris-borde); display:flex; gap:10px; flex-wrap:wrap; }
  .cert-check-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid var(--gris-borde); border-radius:9px; background:var(--bg-surface2); cursor:pointer; transition:all 0.15s; margin-bottom:6px; }
  .cert-check-item:hover { border-color:var(--azul); background:var(--s-info-bg); }
  .cert-check-item input[type=checkbox] { width:16px; height:16px; accent-color:var(--azul); cursor:pointer; flex-shrink:0; }
  .cert-check-item.checked { border-color:var(--azul); background:var(--azul-light); }
  .cert-check-norma { font-size:12px; font-weight:700; color:var(--azul); background:var(--azul-light); padding:2px 8px; border-radius:100px; }
  @media(max-width:640px){.form-grid{grid-template-columns:1fr;}.span-2{grid-column:span 1;}}

  /* ── Cabecera contexto envío ───────────────────────────────── */
  .edit-context-card {
    max-width: 860px;
    background: linear-gradient(135deg, #162d78 0%, #1a47c8 100%);
    border-radius: 14px;
    padding: 18px 22px 16px;
    margin-bottom: 22px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(22,45,120,.18), 0 1px 3px rgba(22,45,120,.12);
  }
  /* Ornamento decorativo — sutil, no distrae */
  .edit-context-card::before {
    content: '';
    position: absolute;
    top: -48px; right: -48px;
    width: 148px; height: 148px;
    border-radius: 50%;
    background: rgba(255,255,255,.055);
    pointer-events: none;
  }
  /* Línea izquierda de acento */
  .edit-context-card::after {
    content: '';
    position: absolute;
    left: 0; top: 18px; bottom: 18px;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: rgba(255,255,255,.3);
    pointer-events: none;
  }
  /* Fila 1 — etiqueta */
  .ctx-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .13em;
    color: rgba(255,255,255,.5);
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 5px;
    position: relative;
    z-index: 1;
  }
  /* Fila 2 — nombre empresa */
  .ctx-company {
    font-family: 'Inter', sans-serif;
    font-size: 19px;
    font-weight: 700;
    color: #fff;
    line-height: 1.3;
    letter-spacing: -.015em;
    margin-bottom: 11px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    position: relative;
    z-index: 1;
  }
  /* Fila 3 — badges */
  .ctx-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    margin-bottom: 12px;
    position: relative;
    z-index: 1;
  }
  .ctx-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px 4px 8px;
    border-radius: 100px;
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    backdrop-filter: blur(6px);
  }
  .ctx-badge-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
    box-shadow: 0 0 0 2px rgba(255,255,255,.22);
  }
  .ctx-badge svg { opacity: .7; flex-shrink: 0; }
  /* Fila 4 — meta + acción en la misma línea */
  .ctx-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    position: relative;
    z-index: 1;
  }
  .ctx-footer-meta {
    font-size: 11.5px;
    color: rgba(255,255,255,.42);
    line-height: 1;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }
  .ctx-footer-meta strong { color: rgba(255,255,255,.65); font-weight: 600; }
  .ctx-footer-meta span.sep { opacity: .35; }
  /* Botón "Ver detalle" — ghost discreto */
  .ctx-btn-detail {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 13px;
    border-radius: 8px;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.18);
    font-size: 11.5px;
    font-weight: 600;
    color: rgba(255,255,255,.8);
    text-decoration: none;
    white-space: nowrap;
    transition: background .15s, color .15s;
    flex-shrink: 0;
  }
  .ctx-btn-detail:hover {
    background: rgba(255,255,255,.2);
    color: #fff;
  }
  .ctx-btn-detail svg { opacity: .7; }
  @media (max-width: 600px) {
    .edit-context-card  { padding: 14px 16px 13px; }
    .ctx-company        { font-size: 16px; -webkit-line-clamp: 3; }
    .ctx-footer         { flex-direction: column; align-items: flex-start; gap: 8px; }
  }
  /* Sede searchable dropdown */
  .sede-search-wrap { position:relative; }
  .sede-search-input { width:100%; box-sizing:border-box; border:1px solid var(--borde,#d0d5dd); border-radius:8px; padding:9px 36px 9px 12px; font-size:14px; color:var(--gris-dark,#1a1a2e); background:var(--blanco,#fff); outline:none; transition:border .15s; }
  .sede-search-input:focus { border-color:var(--azul,#2563eb); box-shadow:0 0 0 3px rgba(37,99,235,.1); }
  .sede-search-input::placeholder { color:var(--gris-texto,#666); }
  .sede-search-wrap::after { content:''; position:absolute; right:12px; top:50%; width:10px; height:10px; border-right:2px solid var(--gris-texto,#888); border-bottom:2px solid var(--gris-texto,#888); transform:translateY(-65%) rotate(45deg); pointer-events:none; }
  .sede-dropdown { display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:200; background:var(--blanco,#fff); border:1px solid var(--borde,#d0d5dd); border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.12); max-height:260px; overflow-y:auto; }
  .sede-dropdown.open { display:block; }
  .sede-opt { padding:9px 14px; font-size:13px; cursor:pointer; color:var(--gris-dark,#1a1a2e); line-height:1.4; border-bottom:1px solid var(--borde,#f0f0f0); transition:background .1s; }
  .sede-opt:last-child { border-bottom:none; }
  .sede-opt:hover, .sede-opt.focused { background:var(--azul-light,var(--s-info-bg)); }
  .sede-opt mark { background:transparent; color:var(--azul,#2563eb); font-weight:700; }
  .sede-empty { padding:14px; font-size:13px; color:var(--gris-texto,#888); text-align:center; }
  .sede-selected-label { font-size:12px; color:var(--azul,#2563eb); margin-top:5px; padding:4px 10px; background:var(--azul-light,var(--s-info-bg)); border-radius:6px; font-weight:600; }
  .sede-sugerida-chip { display:inline-flex; align-items:center; gap:6px; margin-top:6px; font-size:12px; color:var(--s-warn-tx); background:var(--s-warn-bg); border:1px solid #fde68a; border-radius:6px; padding:4px 10px; cursor:pointer; transition:background .15s; }
  .sede-sugerida-chip:hover { background:var(--s-warn-bg); }
  .sede-sugerida-chip strong { font-weight:600; }
</style>

<?php
  $_ctx_estado_color  = $env['estado_color']     ?? '#6b7280';
  $_ctx_estado_nombre = $env['estado_nombre']     ?? '—';
  $_ctx_propuesta     = $env['numero_cotizacion'] ?? null;
?>
<?php if (!$_ggi_embed): ?>
<!-- Cabecera de contexto — CRM style (solo en página completa) -->
<div class="edit-context-card">

  <!-- Etiqueta -->
  <div class="ctx-label">
    <svg width="10" height="10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
      <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
    </svg>
    Editando envío
  </div>

  <!-- Nombre empresa -->
  <div class="ctx-company"><?= sanitize($env['razon_social']) ?></div>

  <!-- Badges: solo Estado + Propuesta -->
  <div class="ctx-badges">
    <div class="ctx-badge">
      <span class="ctx-badge-dot" style="background:<?= sanitize($_ctx_estado_color) ?>;"></span>
      <?= sanitize($_ctx_estado_nombre) ?>
    </div>
    <?php if ($_ctx_propuesta): ?>
    <div class="ctx-badge">
      <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
      </svg>
      <?= sanitize($_ctx_propuesta) ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Pie: meta secundaria + botón ver detalle -->
  <div class="ctx-footer">
    <div class="ctx-footer-meta">
      <span>RUC <strong><?= sanitize($env['ruc'] ?? '—') ?></strong></span>
      <span class="sep">·</span>
      <span>Envío <strong>#<?= $id ?></strong></span>
    </div>
    <a href="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $id ?>" class="ctx-btn-detail">
      <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
      </svg>
      Ver detalle
    </a>
  </div>

</div>
<?php else: ?>
<!-- Cabecera compacta y neutral para el modal (sin hero azul ni "Ver detalle") -->
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--gris-borde);">
  <span style="font-size:15px;font-weight:700;color:var(--gris-dark);"><?= sanitize($env['razon_social']) ?></span>
  <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--gris-texto);background:var(--bg-surface2);border:1px solid var(--gris-borde);border-radius:100px;padding:2px 9px;">
    <span style="width:7px;height:7px;border-radius:50%;background:<?= sanitize($_ctx_estado_color) ?>;"></span><?= sanitize($_ctx_estado_nombre) ?>
  </span>
  <?php if ($_ctx_propuesta): ?><span style="font-size:11px;color:var(--gris-texto);"><?= sanitize($_ctx_propuesta) ?></span><?php endif; ?>
  <span style="font-size:11px;color:var(--gris-texto);margin-left:auto;">Envío #<?= $id ?></span>
</div>
<?php endif; ?>

<form method="POST" id="form-envio-editar" enctype="multipart/form-data" novalidate>
<?= csrf_field() ?>
<input type="hidden" name="from" value="<?= sanitize($from) ?>">
<?php if ($_ggi_embed): ?><input type="hidden" name="_embed" value="1"><?php endif; ?>
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
    <div class="cert-check-item" style="opacity:0.5;cursor:not-allowed;background:var(--bg-surface2);">
      <input type="checkbox" disabled>
      <span class="cert-check-norma"><?= sanitize($c['norma_codigo']) ?></span>
      <span style="font-size:13px;font-weight:600;color:var(--gris-dark);flex:1;"><?= sanitize($c['numero_certificado'] ?: $c['norma_nombre'] ?: '—') ?></span>
      <span style="font-size:11px;color:var(--s-warn-tx);font-weight:600;white-space:nowrap;">Ya enviado</span>
    </div>
    <?php else: ?>
    <label class="cert-check-item <?= $checked?'checked':'' ?>" onclick="toggleCert(this)">
      <input type="checkbox" name="certificados_ids[]" value="<?= $c['id'] ?>" <?= $checked?'checked':'' ?>>
      <span class="cert-check-norma"><?= sanitize($c['norma_codigo']) ?></span>
      <span style="font-size:13px;font-weight:600;color:var(--gris-dark);flex:1;"><?= sanitize($c['numero_certificado'] ?: $c['norma_nombre'] ?: '—') ?></span>
    </label>
    <?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if (isset($errors['certificados_ids'])): ?><div class="err-msg"><?= $errors['certificados_ids'] ?></div><?php endif; ?>
  </div>

  <!-- Destinatario -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      Datos del destinatario
    </div>
    <div class="form-grid">
      <div class="field">
        <label>Destinatario</label>
        <input type="text" name="destinatario" id="input-destinatario" placeholder="Se autocompleta al consultar DNI" value="<?= sanitize($data['destinatario'] ?? '') ?>">
      </div>
      <div class="field">
        <label>DNI del destinatario</label>
        <div style="display:flex;gap:8px;">
          <input type="text" name="destinatario_dni" id="input-dni" placeholder="12345678" maxlength="8"
                 value="<?= sanitize($data['destinatario_dni'] ?? '') ?>" style="flex:1;"
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
    </div>
  </div>

  <!-- Agencia y seguimiento -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
      Agencia y seguimiento
    </div>
    <?php $sin_datos_pre = !empty($data['sin_datos_envio']) || isset($_POST['sin_datos_envio']); ?>
    <label class="sindatos-toggle">
      <input type="checkbox" name="sin_datos_envio" id="chk-sin-datos" onchange="onSinDatosToggle(this)" <?= $sin_datos_pre ? 'checked' : '' ?>>
      <span><strong>El cliente no compartió datos de envío</strong> — anula los datos de destino/seguimiento; solo deja una observación.</span>
    </label>
    <div class="form-grid">
      <div class="field">
        <label>Agencia <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="agencia_envio_id" id="sel-agencia" onchange="actualizarCamposAgencia(this.value)" class="<?= isset($errors['agencia_envio_id'])?'error':'' ?>">
            <option value="" <?= empty($data['agencia_envio_id']) ? 'selected' : '' ?>>Selecciona courier...</option>
            <?php foreach ($agencias as $ag): $acfg = ggi_agencia_envio_config($ag['nombre'], $ag['sede_modo'] ?? 'manual'); ?>
            <option value="<?= $ag['id'] ?>"
                    data-sede-modo="<?= $acfg['sede_modo'] ?>"
                    data-orden="<?= $acfg['tiene_numero_orden']?1:0 ?>"
                    data-recojo="<?= $acfg['tiene_codigo_recojo']?1:0 ?>"
                    data-lbl-orden="<?= sanitize($acfg['label_numero_orden']) ?>"
                    data-lbl-recojo="<?= sanitize($acfg['label_codigo_recojo']) ?>"
                    data-lbl-seg="<?= sanitize($acfg['label_codigo_seguimiento']) ?>"
                    data-tracking="<?= gg_courier_con_tracking($ag['nombre'])?1:0 ?>"
                    data-olva="<?= stripos($ag['nombre'],'olva')!==false?1:0 ?>"
                    data-domicilio="<?= (int)($ag['es_domicilio'] ?? 0) ?>"
                    <?= ($data['agencia_envio_id']==$ag['id'])?'selected':'' ?>><?= sanitize($ag['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (isset($errors['agencia_envio_id'])): ?><div class="err-msg"><?= $errors['agencia_envio_id'] ?></div><?php endif; ?>
      </div>
      <div class="field" id="campo-sede" style="display:<?= $_saved_is_api ? 'flex' : 'none' ?>;">
        <label>Sede de destino</label>
        <div class="sede-search-wrap" id="sede-wrap">
          <input type="text" id="sede-search" autocomplete="off" placeholder="Buscar sede..." aria-label="Buscar sede..." class="sede-search-input"
                 value="<?= sanitize($data['sede_registro'] ?? '') ?>">
          <div class="sede-dropdown" id="sede-dropdown"></div>
        </div>
        <input type="hidden" name="sede_registro" id="sede-val" value="<?= sanitize($data['sede_registro'] ?? '') ?>">
        <div id="sede-sugerida-chip" class="sede-sugerida-chip" style="display:none;" onclick="usarSedeSugerida()">
          <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
          Usar sede anterior: <strong id="sede-sugerida-texto"></strong>
        </div>
      </div>
      <!-- Punto de recojo Olva: buscador (mismo componente que Sede); se llena tras elegir el distrito -->
      <div class="field" id="campo-tienda" style="display:<?= ($_saved_is_olva && !empty($data['tienda_destino'])) ? 'flex' : 'none' ?>;">
        <label>Punto de recojo <span class="req">*</span></label>
        <div class="sede-search-wrap" id="tienda-wrap">
          <input type="text" id="tienda-search" class="sede-search-input" autocomplete="off" placeholder="Selecciona primero el distrito…" aria-label="Buscar punto de recojo" value="<?= sanitize($data['tienda_destino'] ?? '') ?>" disabled>
          <div class="sede-dropdown" id="tienda-drop"></div>
        </div>
        <input type="hidden" name="tienda_destino" id="tienda-val" value="<?= sanitize($data['tienda_destino'] ?? '') ?>">
        <input type="text" id="tienda-manual" name="tienda_destino" style="display:none;" disabled
               placeholder="Escribe el punto de recojo (tienda / oficina Olva)" maxlength="500">
        <?php if (isset($errors['tienda_destino'])): ?><div class="err-msg"><?= $errors['tienda_destino'] ?></div><?php endif; ?>
      </div>
      <!-- Sede manual (couriers sin integración) -->
      <div class="field" id="campo-sede-manual" style="display:<?= $_saved_is_manual_sede ? 'flex' : 'none' ?>;">
        <label>Sede / punto de destino</label>
        <input type="text" name="sede_registro" id="sede-manual" value="<?= sanitize($data['sede_registro'] ?? '') ?>" placeholder="Ej: Agencia Av. La Marina 123, Lima" <?= $_saved_is_manual_sede ? '' : 'disabled' ?>>
      </div>
      <!-- Dirección de destino (texto libre) -->
      <div class="field" id="campo-direccion" style="display:<?= ($_saved_ag && !$_saved_is_api) ? 'flex' : 'none' ?>;">
        <label>Dirección de destino</label>
        <input type="text" name="direccion_destino" id="input-direccion" value="<?= sanitize($data['direccion_destino'] ?? '') ?>" placeholder="Dirección completa de entrega">
      </div>

      <!-- Lima a domicilio: referencia + punto exacto en el mapa -->
      <?php $_saved_es_dom = $_saved_ag && (int)($_saved_ag['es_domicilio'] ?? 0) === 1; ?>
      <div class="field span-2" id="campo-domicilio" style="display:<?= $_saved_es_dom ? 'flex' : 'none' ?>;flex-direction:column;">
        <label>Referencia</label>
        <input type="text" name="referencia_direccion" id="input-referencia" maxlength="300"
               value="<?= sanitize($env['referencia_direccion'] ?? '') ?>" placeholder="Ej: portón azul, 2do piso">
        <label style="margin-top:12px">Punto exacto en el mapa</label>
        <div style="display:flex;gap:8px;margin:4px 0 8px">
          <input type="text" id="dom-buscar-in" placeholder="Buscar dirección para ubicarla…" autocomplete="off"
                 style="flex:1;padding:9px 11px;border:1px solid var(--gris-borde);border-radius:8px;font:inherit">
          <button type="button" id="dom-geo" class="btn btn-outline" style="white-space:nowrap">Mi ubicación</button>
        </div>
        <div id="dom-geores" style="display:none;border:1px solid var(--gris-borde);border-radius:8px;max-height:170px;overflow:auto;margin-bottom:8px"></div>
        <div id="dom-mapa" style="height:290px;border-radius:10px;border:1px solid var(--gris-borde);overflow:hidden;background:#eef2f7"></div>
        <div id="dom-coords" style="margin-top:6px;font-size:12px;color:var(--gris-texto)"></div>
        <input type="hidden" name="lat" id="dom-lat" value="<?= sanitize((string)($env['lat'] ?? '')) ?>">
        <input type="hidden" name="lng" id="dom-lng" value="<?= sanitize((string)($env['lng'] ?? '')) ?>">
      </div>
      <div class="field" id="campo-estado">
        <label>Estado <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="estado_envio_id" id="sel-estado" class="<?= isset($errors['estado_envio_id'])?'error':'' ?>">
            <?php foreach ($estados as $est): ?>
            <option value="<?= $est['id'] ?>" <?= ($data['estado_envio_id']==$est['id'])?'selected':'' ?>><?= sanitize($est['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="estado-tracking-nota" class="hint" style="display:none;margin-top:6px;font-size:.85em;color:var(--tx3,#7C8694);">
          El estado de este courier se actualiza automáticamente por su tracking; no se edita manualmente.
        </div>
      </div>
      <?php
        // Motivo de "No Enviado": visible solo cuando el estado seleccionado es No Enviado.
        // El equipo ve este motivo en el listado y la ficha (por qué no salió el envío).
        $_ne_ids = array_values(array_map(fn($x) => (int)$x['id'], array_filter($estados, fn($x) => gg_es_estado_no_enviado($x['nombre']))));
        $_motivo_val = $_POST['motivo_no_envio'] ?? ($env['motivo_no_envio'] ?? '');
        $_motivo_det_val = $_POST['motivo_no_envio_detalle'] ?? ($env['motivo_no_envio_detalle'] ?? '');
      ?>
      <div class="field" id="campo-motivo-ne" style="display:none;">
        <label>Motivo de no envío <span class="req">*</span></label>
        <div class="select-wrap">
          <select name="motivo_no_envio" id="sel-motivo-ne" class="<?= isset($errors['motivo_no_envio'])?'error':'' ?>">
            <option value="">— Selecciona el motivo —</option>
            <?php foreach (gg_motivos_no_envio() as $_mcod => $_mlbl): ?>
            <option value="<?= $_mcod ?>" <?= $_motivo_val === $_mcod ? 'selected' : '' ?>><?= sanitize($_mlbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <input type="text" name="motivo_no_envio_detalle" id="input-motivo-ne-det" maxlength="500"
               value="<?= sanitize($_motivo_det_val) ?>"
               placeholder="Detalle (opcional; obligatorio en “Otro motivo”)" style="margin-top:7px;">
        <?php if (isset($errors['motivo_no_envio'])): ?><div class="err-msg"><?= $errors['motivo_no_envio'] ?></div><?php endif; ?>
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
      <div class="field" id="campo-fecha-envio">
        <label>Fecha de envío <span class="req">*</span></label>
        <input type="date" name="fecha_envio" id="input-fecha-envio" value="<?= $data['fecha_envio'] ?? '' ?>" class="<?= isset($errors['fecha_envio'])?'error':'' ?>">
        <?php if (isset($errors['fecha_envio'])): ?><div class="err-msg"><?= $errors['fecha_envio'] ?></div><?php endif; ?>
      </div>
      <div class="field" id="campo-fecha-recibido">
        <label>Fecha de recepción</label>
        <input type="date" name="fecha_recibido" id="input-fecha-recibido" value="<?= $data['fecha_recibido'] ?? '' ?>">
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

  <!-- Asignación -->
  <div class="form-section">
    <div class="section-title">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Asignación
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

      <!-- Ejecutivo de ventas -->
      <div class="field">
        <label>Ejecutivo de ventas</label>
        <?php if (in_array($user['rol'], ['ejecutivo_ventas', 'gestor_logistico'])): ?>
          <?php
            $ej_p = array_filter($ejecutivos, fn($e) => $e['id'] == $env['ejecutivo_id']);
            $ej_p = reset($ej_p) ?: null;
          ?>
          <input type="hidden" name="ejecutivo_id" value="<?= $env['ejecutivo_id'] ?? '' ?>">
          <?php if ($ej_p): ?>
          <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s-ok-bg);border:1.5px solid #b7e4c0;border-radius:9px;">
            <div style="width:28px;height:28px;border-radius:50%;background:#1D9E75;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;"><?= strtoupper(substr($ej_p['nombre'],0,1).substr($ej_p['apellido'],0,1)) ?></div>
            <span style="font-size:13px;font-weight:600;color:#229133;"><?= sanitize($ej_p['nombre'].' '.$ej_p['apellido']) ?></span>
          </div>
          <?php else: ?>
          <div style="padding:10px 14px;background:var(--s-info-bg);border:1.5px solid #e2e6f0;border-radius:9px;font-size:13px;color:var(--gris-texto);">— Sin asignar —</div>
          <?php endif; ?>
          <span style="font-size:11px;color:var(--gris-texto);">Solo el ejecutivo de ventas puede asignarse.</span>
        <?php else: ?>
          <div class="select-wrap">
            <select name="ejecutivo_id" id="sel-ejecutivo-hidden">
              <option value="">— Sin asignar —</option>
              <?php foreach ($ejecutivos as $ej): ?>
              <option value="<?= $ej['id'] ?>" <?= ($env['ejecutivo_id']==$ej['id'])?'selected':'' ?>><?= sanitize($ej['nombre'].' '.$ej['apellido']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>

      <!-- Gestor logístico -->
      <div class="field">
        <label>Gestor logístico</label>
        <?php if ($user['rol'] === 'gestor_logistico'): ?>
          <?php
            $gl_p = array_filter($gestores, fn($g) => $g['id'] == ($env['agente_id'] ?? null));
            $gl_p = reset($gl_p) ?: null;
          ?>
          <input type="hidden" name="agente_id" value="<?= $env['agente_id'] ?? '' ?>">
          <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--s-ok-bg);border:1.5px solid #b7e4c0;border-radius:9px;">
            <div style="width:28px;height:28px;border-radius:50%;background:#1D9E75;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;"><?= $gl_p ? strtoupper(substr($gl_p['nombre'],0,1).substr($gl_p['apellido'],0,1)) : '?' ?></div>
            <span style="font-size:13px;font-weight:600;color:#229133;"><?= $gl_p ? sanitize($gl_p['nombre'].' '.$gl_p['apellido']) : '—' ?></span>
          </div>
          <span style="font-size:11px;color:var(--gris-texto);">Solo gerentes pueden reasignar el gestor.</span>
        <?php else: ?>
          <!-- Ejecutivos de ventas Y gerentes pueden asignar gestor -->
          <div class="select-wrap">
            <select name="agente_id" id="sel-agente-hidden">
              <option value="">— Sin asignar —</option>
              <?php foreach ($gestores as $g): ?>
              <option value="<?= $g['id'] ?>" <?= (($env['agente_id'] ?? null)==$g['id'])?'selected':'' ?>><?= sanitize($g['nombre'].' '.$g['apellido']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Guardar cambios</button>
    <?php if (!$_ggi_embed): ?>
    <a href="<?= APP_URL ?>/modules/envios/ver.php?id=<?= $id ?>" class="btn btn-outline">Cancelar</a>
    <?php endif; ?>
  </div>
</div>
</form>

<script>
var dirFiscal   = <?= json_encode($dir_fiscal_completa) ?>;
var CLIENTE_ID  = <?= (int)($env['cliente_id'] ?? 0) ?>;
// Agencia y sede ORIGINALES del envío: para restaurar la sede guardada al reabrir o al
// volver a la agencia original (una sede de Shalom no aplica a Olva y viceversa).
var ORIG_AGENCIA = <?= (int)($env['agencia_envio_id'] ?? 0) ?>;
var ORIG_SEDE    = <?= json_encode((string)($env['sede_registro'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var ORIG_TIENDA  = <?= json_encode((string)($env['tienda_destino'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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

async function actualizarCamposAgencia(agenciaId, isInit) {
  if (!agenciaId) return;
  try {
    // Config desde los data-* del <option> (server-rendered) → instantáneo, sin depender
    // de un AJAX que en el iframe podía fallar y dejar Sede/Orden/Recojo ocultos.
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
    // Couriers con tracking: el estado lo maneja la sincronización → bloquear edición manual.
    const _tracking  = _opt ? _opt.dataset.tracking === '1' : false;
    const selEstado  = document.getElementById('sel-estado');
    const notaEstado = document.getElementById('estado-tracking-nota');
    if (selEstado) selEstado.disabled = _tracking;
    if (notaEstado) notaEstado.style.display = _tracking ? 'block' : 'none';

    // ── Lima a domicilio: dirección + referencia + mapa; se ocultan sede/tienda ──
    var esDom = !!(_opt && _opt.dataset.domicilio === '1');
    var campoDom = document.getElementById('campo-domicilio');
    if (esDom) {
      document.getElementById('campo-direccion').style.display = 'flex';
      var _id = document.getElementById('input-direccion'); if (_id) _id.disabled = false;
      if (campoDom) campoDom.style.display = 'flex';
      ['campo-sede','campo-sede-manual','campo-tienda','campo-numero-orden','campo-codigo-recojo'].forEach(function(f){
        var el = document.getElementById(f); if (el) el.style.display = 'none';
      });
      var _sv = document.getElementById('sede-val');    if (_sv) _sv.disabled = true;
      var _sm = document.getElementById('sede-manual'); if (_sm) _sm.disabled = true;
      _esOlva = false; resetTiendaOlva();
      domInitMapa();
      var _sd0 = document.getElementById('chk-sin-datos'); if (_sd0 && _sd0.checked) onSinDatosToggle(_sd0);
      return;
    }
    if (campoDom) campoDom.style.display = 'none';

    // ¿Es Olva? → tras elegir el distrito se habilita el selector de punto de recojo (tienda)
    _esOlva = !!(_opt && _opt.dataset.olva === '1');
    const _tval = document.getElementById('tienda-val');
    // Conservar el punto de recojo guardado en init, o al volver a la agencia Olva original.
    const _tOrig = _esOlva && (parseInt(agenciaId, 10) === ORIG_AGENCIA) && ORIG_TIENDA;
    if (_esOlva && (_tOrig || (isInit && _tval && _tval.value))) {
      const _keep = _tOrig ? ORIG_TIENDA : _tval.value;
      document.getElementById('campo-tienda').style.display = 'flex';
      const _ts = document.getElementById('tienda-search');
      if (_ts) { _ts.disabled = false; _ts.value = _keep; _ts.placeholder = 'Buscar punto de recojo…'; }
      if (_tval) _tval.value = _keep;
    } else {
      resetTiendaOlva();
    }

    const campoOrden  = document.getElementById('campo-numero-orden');
    const campoRecojo = document.getElementById('campo-codigo-recojo');
    const campoSede   = document.getElementById('campo-sede');
    campoOrden.style.display  = cfg.tiene_numero_orden  ? 'flex' : 'none';
    campoRecojo.style.display = cfg.tiene_codigo_recojo ? 'flex' : 'none';
    if (cfg.tiene_numero_orden)  document.getElementById('lbl-numero-orden').textContent  = cfg.label_numero_orden;
    if (cfg.tiene_codigo_recojo) document.getElementById('lbl-codigo-recojo').textContent = cfg.label_codigo_recojo;
    document.getElementById('lbl-codigo-seguimiento').textContent = cfg.label_codigo_seguimiento;
    const campoSedeManual = document.getElementById('campo-sede-manual');
    const campoDireccion  = document.getElementById('campo-direccion');
    const sedeVal    = document.getElementById('sede-val');
    const sedeManual = document.getElementById('sede-manual');
    const inputDir   = document.getElementById('input-direccion');
    // Dirección de destino: solo para couriers SIN integración (modo manual).
    // En integraciones (Olva/Shalom) el destino se define por la sede.
    const dirPermitida = !(cfg.tiene_sede && cfg.sede_modo === 'api');
    if (campoDireccion) {
      campoDireccion.style.display = dirPermitida ? 'flex' : 'none';
      if (inputDir) { inputDir.disabled = !dirPermitida; if (!dirPermitida) inputDir.value = ''; }
    }

    if (cfg.tiene_sede && cfg.sede_modo === 'api') {
      campoSede.style.display = 'flex';
      if (campoSedeManual) campoSedeManual.style.display = 'none';
      if (sedeManual) sedeManual.disabled = true;
      if (sedeVal)    sedeVal.disabled = false;
      const searchInput = document.getElementById('sede-search');
      const sedesUrl = `<?= APP_URL ?>/modules/envios/ajax_sedes.php?agencia_id=${agenciaId}&cliente_id=${CLIENTE_ID}&envio_id=<?= (int)$id ?>`;
      // Preservar la sede guardada SOLO si la agencia seleccionada es la ORIGINAL del
      // envío (la sede de un courier no aplica a otro). Así, al reabrir o al volver a la
      // agencia original, el destino guardado reaparece y se envía; en otra agencia se resetea.
      const esOrig   = (parseInt(agenciaId, 10) === ORIG_AGENCIA);
      const preserve = esOrig ? (ORIG_SEDE || sedeVal.value) : '';
      if (!preserve) resetSedeDropdown();
      // Mostrar/enviar el valor de inmediato (robusto aunque el fetch sea lento o falle).
      if (preserve) { searchInput.value = preserve; if (sedeVal) sedeVal.value = preserve; }
      searchInput.disabled = true; searchInput.placeholder = 'Cargando sedes...';
      fetch(sedesUrl)
        .then(res => res.json()).then(data => {
          searchInput.disabled = false; searchInput.placeholder = 'Buscar sede...';
          initSedeDropdown(data.sedes || [], preserve);
          if (!preserve && data.sede_sugerida) aplicarSedeSugerida(data.sede_sugerida);
        })
        .catch(() => { searchInput.disabled = false; searchInput.placeholder = preserve ? 'Buscar sede...' : 'Error al cargar'; });
    } else if (cfg.tiene_sede) {
      // Modo manual: sede de texto libre
      campoSede.style.display = 'none';
      resetSedeDropdown();
      if (sedeVal) sedeVal.disabled = true;
      if (campoSedeManual) campoSedeManual.style.display = 'flex';
      if (sedeManual) sedeManual.disabled = false;
    } else {
      campoSede.style.display = 'none';
      resetSedeDropdown();
      if (campoSedeManual) campoSedeManual.style.display = 'none';
      if (sedeVal)    sedeVal.disabled = true;
      if (sedeManual) sedeManual.disabled = true;
    }
    var _sd = document.getElementById('chk-sin-datos');
    if (_sd && _sd.checked) onSinDatosToggle(_sd);
  } catch(e) { console.error(e); }
}

// "No compartió datos de envío" — anula datos de destino/destinatario/seguimiento
function onSinDatosToggle(chk) {
  const off = chk.checked;
  ['campo-sede','campo-tienda','campo-sede-manual','campo-direccion','campo-numero-orden','campo-codigo-recojo',
   'campo-estado','campo-fecha-envio','campo-fecha-recibido'].forEach(function(id){
    const el = document.getElementById(id); if (el) el.style.opacity = off ? '.45' : '';
  });
  ['sede-search','input-direccion','input-destinatario','input-dni','input-numero-orden'].forEach(function(id){
    const el = document.getElementById(id); if (el) { if (off) el.value = ''; el.disabled = off; }
  });
  const cod = document.querySelector('[name="codigo_envio"]');
  if (cod) { if (off) cod.value = ''; cod.disabled = off; }
  const sv = document.getElementById('sede-val'), sm = document.getElementById('sede-manual');
  if (off) { if (sv) { sv.value=''; sv.disabled = true; } if (sm) { sm.value=''; sm.disabled = true; } }
  const tval = document.getElementById('tienda-val');
  if (tval && off) { tval.value=''; tval.disabled = true; }
  const tsrch = document.getElementById('tienda-search');
  if (tsrch && off) { tsrch.value=''; tsrch.disabled = true; }
  const tman = document.getElementById('tienda-manual');
  if (tman && off) { tman.value=''; tman.disabled = true; }
  // Estado y fechas: no hay envío que rastrear → se bloquean en valores neutros.
  const est = document.getElementById('sel-estado');
  if (est) {
    if (off) {
      const pp = Array.from(est.options).find(function(o){ return /por preparar/i.test(o.textContent); });
      if (pp) est.value = pp.value;
    }
    est.style.pointerEvents = off ? 'none' : ''; est.tabIndex = off ? -1 : 0;
  }
  const fe = document.getElementById('input-fecha-envio');
  if (fe) { if (off && !fe.value) fe.value = new Date().toISOString().slice(0,10); fe.readOnly = off; fe.style.pointerEvents = off ? 'none' : ''; }
  const fr = document.getElementById('input-fecha-recibido');
  if (fr) { if (off) fr.value = ''; fr.readOnly = off; fr.style.pointerEvents = off ? 'none' : ''; }
  const obs = document.querySelector('[name="observaciones"]');
  if (obs) { obs.required = off; if (off) setTimeout(function(){ obs.focus(); }, 50); }
  if (!off) { const sel = document.getElementById('sel-agencia'); if (sel && sel.value) actualizarCamposAgencia(sel.value, false); }
}

const _chkDir = document.getElementById('chk-dir-fiscal');
if (_chkDir && _chkDir.checked) toggleDireccion(_chkDir);

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
// NOTA: el init del formulario se ejecuta al FINAL del siguiente bloque <script>,
// porque necesita funciones definidas allí (resetTiendaOlva/initSedeDropdown/
// resetSedeDropdown/aplicarSedeSugerida). Si corría aquí lanzaba ReferenceError y
// abortaba actualizarCamposAgencia → selector muerto y campos ocultos en la carga.

async function consultarDNI() {
  const input = document.getElementById('input-dni');
  const btn   = document.getElementById('btn-dni');
  const msg   = document.getElementById('dni-msg');
  const dni   = input.value.replace(/\D/g,'');
  if (dni.length !== 8) { msg.style.display='block'; msg.style.color='#ef4444'; msg.textContent='Ingresa 8 dígitos.'; return; }
  btn.innerHTML = '<svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Consultando...'; btn.disabled = true; msg.style.display = 'none';
  try {
    const r    = await fetch(`<?= APP_URL ?>/modules/envios/ajax_dni.php?dni=${dni}`);
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
</script>
<script>
<?php
// Detectar si el envío tiene tracking automático activo
$_agencia_lower   = strtolower($env['agencia_nombre'] ?? $env['agencia'] ?? '');
$_ag_row = $pdo->prepare("SELECT nombre FROM agencias_envio WHERE id=?");
$_ag_row->execute([$env['agencia_envio_id']]);
$_ag_nombre = strtolower($_ag_row->fetchColumn() ?? '');
$_is_olva   = str_contains($_ag_nombre, 'olva');
$_is_shalom = str_contains($_ag_nombre, 'shalom');
$_has_tracking = ($_is_olva && !empty($env['codigo_envio']))
              || ($_is_shalom && !empty($env['numero_orden']) && !empty($env['codigo_envio']));
$_tracking_agency = $_is_olva ? 'Olva' : ($_is_shalom ? 'Shalom' : '');
// ID del estado "Entregado"
$_id_entregado = 0;
foreach ($estados as $_est) {
    if (strtolower(trim($_est['nombre'])) === 'entregado') { $_id_entregado = (int)$_est['id']; break; }
}
?>
const HAS_TRACKING   = <?= $_has_tracking ? 'true' : 'false' ?>;
const AGENCY_NAME    = <?= json_encode($_tracking_agency) ?>;
const ID_ENTREGADO   = <?= $_id_entregado ?>;
const ESTADO_ACTUAL  = <?= (int)$env['estado_envio_id'] ?>;

// Advertencia si se intenta marcar como Entregado manualmente con tracking activo
if (HAS_TRACKING && ID_ENTREGADO) {
  const selEstado = document.querySelector('select[name="estado_envio_id"]');
  const inpFecha  = document.querySelector('input[name="fecha_recibido"]');

  function advertirSiEntregado(nuevoId) {
    if (parseInt(nuevoId) === ID_ENTREGADO && parseInt(nuevoId) !== ESTADO_ACTUAL) {
      ggiConfirm(
        'Tracking automático activo',
        'Este envío tiene tracking automático con ' + AGENCY_NAME + '. El estado se actualizará solo cuando ' + AGENCY_NAME + ' confirme la entrega. ¿Deseas igualmente cambiar el estado a "Entregado" manualmente?',
        { type: 'warning', confirmText: 'Sí, cambiar' }
      ).then(function(ok) {
        if (!ok) selEstado.value = ESTADO_ACTUAL;
      });
    }
  }

  // ── Motivo de "No Enviado": mostrar el campo solo cuando aplica ──
  const NE_IDS = <?= json_encode($_ne_ids ?? []) ?>;
  const campoMotivoNe = document.getElementById('campo-motivo-ne');
  function toggleMotivoNe() {
    if (!campoMotivoNe || !selEstado) return;
    campoMotivoNe.style.display = NE_IDS.includes(parseInt(selEstado.value)) ? '' : 'none';
  }
  toggleMotivoNe(); // estado inicial (p.ej. al reabrir un envío ya No Enviado)

  if (selEstado) {
    selEstado.addEventListener('change', function() {
      advertirSiEntregado(this.value);
      toggleMotivoNe();
    });
  }
  if (inpFecha) {
    inpFecha.addEventListener('change', function() {
      if (this.value && parseInt(selEstado?.value) !== ID_ENTREGADO) {
        const _inp = this;
        ggiConfirm(
          'Confirmar recepción manual',
          'Estás ingresando una fecha de recepción en un envío con tracking automático (' + AGENCY_NAME + '). ¿Confirmas que el envío ya fue entregado y quieres registrarlo manualmente?',
          { type: 'warning', confirmText: 'Confirmar' }
        ).then(function(ok) {
          if (!ok) _inp.value = '';
        });
      }
    });
  }
}

new GGIForm('form-envio-editar')
  .selectRequired('cliente_id',      'Selecciona un cliente.')
  .selectRequired('agencia_envio_id','Selecciona una agencia de envío.')
  .selectRequired('estado_envio_id', 'Selecciona el estado del envío.')
  .dateRequired('fecha_envio',       'La fecha de envío es requerida.')
  .required('destinatario',          'El nombre del destinatario es requerido.')
  .init();

// ── Sede searchable dropdown ──────────────────────────────────────────────────
var _sedeAll = [];
var _esOlva  = false;

function initSedeDropdown(sedes, preVal) {
  _sedeAll = sedes;
  const inp  = document.getElementById('sede-search');
  const drop = document.getElementById('sede-dropdown');
  const val  = document.getElementById('sede-val');
  if (preVal) { inp.value = preVal; if (val) val.value = preVal; }  // fija visible + hidden (se envía)
  inp.oninput = function() {
    renderSedeOptions(inp.value.trim());
    if (!inp.value.trim()) { val.value = ''; }
    drop.classList.add('open');
  };
  inp.onfocus = function() { renderSedeOptions(inp.value.trim()); drop.classList.add('open'); };
  inp.onkeydown = function(e) {
    const items = drop.querySelectorAll('.sede-opt');
    const cur   = drop.querySelector('.sede-opt.focused');
    if (e.key === 'ArrowDown') { e.preventDefault(); const n = cur ? cur.nextElementSibling : items[0]; if (n) { cur?.classList.remove('focused'); n.classList.add('focused'); n.scrollIntoView({block:'nearest'}); } }
    else if (e.key === 'ArrowUp') { e.preventDefault(); const p = cur ? cur.previousElementSibling : items[items.length-1]; if (p) { cur?.classList.remove('focused'); p.classList.add('focused'); p.scrollIntoView({block:'nearest'}); } }
    else if (e.key === 'Enter') { e.preventDefault(); if (cur) cur.click(); }
    else if (e.key === 'Escape') { drop.classList.remove('open'); }
  };
  document.addEventListener('click', function(e) {
    if (!document.getElementById('sede-wrap')?.contains(e.target)) drop.classList.remove('open');
  }, { capture: true });
}

function renderSedeOptions(q) {
  const drop = document.getElementById('sede-dropdown');
  const qLow = q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
  const matches = q ? _sedeAll.filter(s => s.label.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').includes(qLow)) : _sedeAll;
  if (!matches.length) { drop.innerHTML = '<div class="sede-empty">Sin resultados para "' + q + '"</div>'; return; }
  drop.innerHTML = matches.slice(0,80).map(s => {
    const hi = q ? s.label.replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>') : s.label;
    return `<div class="sede-opt" data-val="${s.valor.replace(/"/g,'&quot;')}" data-ubigeo="${s.ubigeo||''}">${hi}</div>`;
  }).join('');
  drop.querySelectorAll('.sede-opt').forEach(opt => {
    opt.addEventListener('click', function() {
      const v = this.dataset.val;
      document.getElementById('sede-val').value = v;
      document.getElementById('sede-search').value = v;
      drop.classList.remove('open');
      if (_esOlva) loadTiendasOlva(this.dataset.ubigeo);
    });
  });
}

// ── Puntos de recojo Olva (buscador, mismo componente que Sede) ──
var _tiendaAll = [];
function setTiendaManual(on, placeholder) {
  const wrap = document.getElementById('tienda-wrap');
  const man  = document.getElementById('tienda-manual');
  const val  = document.getElementById('tienda-val');
  if (on) {
    if (wrap) wrap.style.display = 'none';
    if (val)  val.disabled = true;
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
function loadTiendasOlva(ubigeo) {
  const campo = document.getElementById('campo-tienda');
  const s     = document.getElementById('tienda-search');
  const v     = document.getElementById('tienda-val');
  if (!campo || !s) return;
  campo.style.display = 'flex';
  setTiendaManual(false);
  if (v) v.value = '';
  s.value = ''; s.disabled = true; s.placeholder = 'Cargando puntos de recojo…';
  if (!ubigeo) { setTiendaManual(true, 'Escribe el punto de recojo (distrito sin código)'); return; }
  fetch(`<?= APP_URL ?>/modules/envios/ajax_tiendas_olva.php?ubigeo=${encodeURIComponent(ubigeo)}`)
    .then(r => r.json())
    .then(d => {
      const items = d.tiendas || [];
      if (!items.length) { setTiendaManual(true, 'Sin tiendas en el distrito: escribe el punto de recojo'); return; }
      _tiendaAll = items.map(t => t.nombre || '').filter(Boolean);
      s.disabled = false; s.placeholder = 'Buscar punto de recojo…';
      initTiendaDropdown();
    })
    .catch(() => { setTiendaManual(true, 'Olva no disponible: escribe el punto de recojo'); });
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

var _sedeSugerida = null;
function aplicarSedeSugerida(sede) {
  _sedeSugerida = sede;
  const chip  = document.getElementById('sede-sugerida-chip');
  const texto = document.getElementById('sede-sugerida-texto');
  if (chip && texto) { texto.textContent = sede; chip.style.display = 'inline-flex'; }
}
function usarSedeSugerida() {
  if (!_sedeSugerida) return;
  document.getElementById('sede-val').value    = _sedeSugerida;
  document.getElementById('sede-search').value = _sedeSugerida;
  const chip = document.getElementById('sede-sugerida-chip');
  if (chip) chip.style.display = 'none';
}

function resetSedeDropdown() {
  _sedeAll = []; _sedeSugerida = null;
  const inp = document.getElementById('sede-search');
  if (inp) inp.value = '';
  const val = document.getElementById('sede-val');
  if (val) val.value = '';
  const drop = document.getElementById('sede-dropdown');
  if (drop) { drop.innerHTML = ''; drop.classList.remove('open'); }
  const chip = document.getElementById('sede-sugerida-chip');
  if (chip) chip.style.display = 'none';
}

// ── Init del formulario (movido aquí: ya están definidas TODAS las funciones del
//    selector). Antes corría en el bloque anterior y lanzaba ReferenceError al
//    llamar resetTiendaOlva/initSedeDropdown → abortaba y dejaba el selector muerto
//    y los campos (sede/orden/recojo) ocultos al cargar. ──
(function initEnvioForm() {
  var ag = document.getElementById('sel-agencia')?.value;
  if (ag) actualizarCamposAgencia(ag, true);
  var _sdInit = document.getElementById('chk-sin-datos');
  if (_sdInit && _sdInit.checked) onSinDatosToggle(_sdInit);
})();
</script>

<!-- Mapa de "Lima a domicilio" (Leaflet + OpenStreetMap / CartoDB) -->
<link rel="stylesheet" href="<?= APP_URL ?>/assets/leaflet/leaflet.css?v=<?= @filemtime(__DIR__ . '/../../assets/leaflet/leaflet.css') ?>">
<script src="<?= APP_URL ?>/assets/leaflet/leaflet.js?v=<?= @filemtime(__DIR__ . '/../../assets/leaflet/leaflet.js') ?>"></script>
<script>
(function(){
  var _map = null, _mk = null, base = '<?= APP_URL ?>/assets/leaflet/images/';
  function setPunto(lat, lng, centrar){
    document.getElementById('dom-lat').value = lat.toFixed(7);
    document.getElementById('dom-lng').value = lng.toFixed(7);
    document.getElementById('dom-coords').textContent = 'Punto: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
    if (_mk) _mk.setLatLng([lat, lng]);
    if (centrar && _map) _map.setView([lat, lng], 16);
  }
  window.domInitMapa = function(){
    if (typeof L === 'undefined') return;
    if (_map) {
      setTimeout(function(){ _map.invalidateSize(); }, 150);
      var la=parseFloat(document.getElementById('dom-lat').value), ln=parseFloat(document.getElementById('dom-lng').value);
      if (!isNaN(la) && !isNaN(ln)) { _mk.setLatLng([la,ln]); _map.setView([la,ln],16); }
      return;
    }
    var icon = L.icon({ iconUrl: base+'marker-icon.png', iconRetinaUrl: base+'marker-icon-2x.png',
      shadowUrl: base+'marker-shadow.png', iconSize:[25,41], iconAnchor:[12,41], shadowSize:[41,41] });
    var hay = !!document.getElementById('dom-lat').value;
    var lat0 = parseFloat(document.getElementById('dom-lat').value) || -12.0464;
    var lng0 = parseFloat(document.getElementById('dom-lng').value) || -77.0428;
    _map = L.map('dom-mapa').setView([lat0,lng0], hay?16:12);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
      { maxZoom:20, subdomains:'abcd', attribution:'© OpenStreetMap © CARTO' }).addTo(_map);
    _mk = L.marker([lat0,lng0], { draggable:true, icon:icon }).addTo(_map);
    _mk.on('dragend', function(){ var p=_mk.getLatLng(); setPunto(p.lat,p.lng,false); });
    _map.on('click', function(e){ setPunto(e.latlng.lat, e.latlng.lng, false); });
    if (hay) setPunto(lat0,lng0,false);
    setTimeout(function(){ _map.invalidateSize(); }, 200);
  };
  // Geolocalización
  document.addEventListener('click', function(e){
    if (!e.target.closest('#dom-geo')) return;
    if (!navigator.geolocation) { alert('Sin geolocalización'); return; }
    navigator.geolocation.getCurrentPosition(function(p){ domInitMapa(); setPunto(p.coords.latitude, p.coords.longitude, true); },
      function(){ alert('No se pudo obtener la ubicación. Toca el mapa.'); }, { enableHighAccuracy:true, timeout:10000 });
  });
  // Buscar dirección (Nominatim). Solo al tocar Enter o tras pausa (respeta el límite).
  function geocodificar(){
    var q = document.getElementById('dom-buscar-in').value.trim();
    var box = document.getElementById('dom-geores');
    if (q.length < 4) { box.style.display='none'; return; }
    fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=pe&limit=6&q=' + encodeURIComponent(q),
      { headers: { 'Accept-Language':'es' } })
      .then(function(r){ return r.json(); })
      .then(function(items){
        if (!items || !items.length) { box.innerHTML='<div style="padding:9px 12px;font-size:13px;color:#64748b">Sin resultados</div>'; box.style.display='block'; return; }
        box.innerHTML = items.map(function(it){
          return '<div class="dom-geo-opt" data-lat="'+it.lat+'" data-lng="'+it.lon+'" style="padding:9px 12px;font-size:13px;cursor:pointer;border-bottom:1px solid #f1f5f9">'+(it.display_name||'')+'</div>';
        }).join('');
        box.style.display='block';
        box.querySelectorAll('.dom-geo-opt').forEach(function(o){
          o.addEventListener('click', function(){ domInitMapa(); setPunto(parseFloat(this.dataset.lat), parseFloat(this.dataset.lng), true); box.style.display='none'; });
        });
      }).catch(function(){});
  }
  var _gt=null;
  document.addEventListener('input', function(e){
    if (!e.target || e.target.id !== 'dom-buscar-in') return;
    clearTimeout(_gt); var q=e.target.value.trim();
    if (q.length<5){ document.getElementById('dom-geores').style.display='none'; return; }
    _gt = setTimeout(geocodificar, 700);
  });
  document.addEventListener('keydown', function(e){ if (e.key==='Enter' && e.target && e.target.id==='dom-buscar-in'){ e.preventDefault(); geocodificar(); } });
})();
</script>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
