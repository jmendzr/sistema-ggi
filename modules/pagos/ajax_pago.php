<?php
/**
 * ajax_pago.php — Acciones AJAX sobre pagos_cliente
 * El ob_start() se mantiene activo durante todo el script.
 * _pago_json() limpia TODOS los niveles de buffer antes de enviar JSON,
 * evitando que notices/warnings corrompan la respuesta.
 */
ob_start();
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/PushService.php';
require_once __DIR__ . '/../../core/NotificationService.php';
require_once __DIR__ . '/../../vendor/autoload.php';
session_init();
require_login();

function _pago_json(array $data): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    _pago_json(['ok' => false, 'msg' => 'Método no permitido']);
}
csrf_verify();

$user    = auth_user();
$accion  = trim($_POST['accion'] ?? '');
$pago_id = (int)($_POST['pago_id'] ?? 0);
$pdo     = Database::get();

if (!$pago_id) { _pago_json(['ok' => false, 'msg' => 'ID inválido']); }

$pago = $pdo->prepare("
    SELECT p.*, c.razon_social AS empresa,
           COALESCE(cot.ejecutivo_id, c.ejecutivo_id) AS ejecutivo_id,
           cot.numero AS cot_numero
    FROM pagos_cliente p
    JOIN clientes c   ON c.id   = p.cliente_id
    JOIN cotizaciones cot ON cot.id = p.cotizacion_id
    WHERE p.id = ?
");
$pago->execute([$pago_id]);
$pago = $pago->fetch();

if (!$pago) { _pago_json(['ok' => false, 'msg' => 'Pago no encontrado']); }

// ──────────────────────────────────────────────────────
// ACCIÓN: Subir voucher
// ──────────────────────────────────────────────────────
if ($accion === 'subir_voucher') {

    if (!in_array($pago['estado'], ['pendiente_voucher', 'rechazado'])) {
        _pago_json(['ok' => false, 'msg' => 'Este pago ya tiene voucher en validación o fue validado']);
    }

    if (empty($_FILES['voucher']) || $_FILES['voucher']['error'] !== UPLOAD_ERR_OK) {
        _pago_json(['ok' => false, 'msg' => 'No se recibió el archivo']);
    }

    $file  = $_FILES['voucher'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : 'application/octet-stream';
    if ($finfo) finfo_close($finfo);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Tipos MIME ampliados para PDFs (distintos servidores devuelven variantes)
    $pdf_mimes     = ['application/pdf','application/x-pdf','application/acrobat','application/vnd.pdf','text/pdf','text/x-pdf'];
    $allowed_mimes = array_merge(ALLOWED_TYPES, $pdf_mimes);

    $ext_ok  = in_array($ext, ALLOWED_EXT);
    $mime_ok = in_array($mime, $allowed_mimes)
               || ($ext === 'pdf' && $mime === 'application/octet-stream');

    if (!$ext_ok || !$mime_ok) {
        _pago_json(['ok' => false, 'msg' => 'Tipo de archivo no permitido. Solo PDF, JPG, PNG o WEBP.']);
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        _pago_json(['ok' => false, 'msg' => 'El archivo supera el límite de 10 MB']);
    }

    $dir = UPLOAD_PATH . $pago['cliente_id'] . '/pagos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $nombre = 'pago_' . ($pago['tipo'] === 'inicial' ? 'inicial' : 'final') . '_cot' . $pago['cotizacion_id'] . '_v' . time() . '.' . $ext;
    $ruta   = $dir . $nombre;

    if (!move_uploaded_file($file['tmp_name'], $ruta)) {
        _pago_json(['ok' => false, 'msg' => 'Error al guardar el archivo']);
    }

    // Eliminar voucher anterior si existe
    if (!empty($pago['voucher_path']) && file_exists($pago['voucher_path'])) {
        @unlink($pago['voucher_path']);
    }

    $fecha_decl = trim($_POST['fecha_pago'] ?? '') ?: null;
    $banco      = trim($_POST['banco'] ?? '') ?: null;
    $referencia = trim($_POST['referencia'] ?? '') ?: null;

    $pdo->prepare("
        UPDATE pagos_cliente SET
          voucher_path      = ?,
          voucher_nombre    = ?,
          fecha_pago_decl   = ?,
          banco             = ?,
          referencia        = ?,
          estado            = 'pendiente_validacion',
          subido_por        = ?,
          fecha_subida      = NOW(),
          notas_validacion  = NULL
        WHERE id = ?
    ")->execute([$ruta, $nombre, $fecha_decl, $banco, $referencia, $user['id'], $pago_id]);

    try {
        log_actividad('pagos', 'subir_voucher', "Voucher subido pago #{$pago_id} (cotización #{$pago['cotizacion_id']})");
        $tipo_label = $pago['tipo'] === 'inicial' ? 'Inicial' : 'Final';
        $moneda_sym = $pago['moneda'] === 'USD' ? 'USD ' : 'S/ ';
        $monto_fmt  = $moneda_sym . number_format($pago['monto'], 2);
        NotificationService::dispatch('pago_registrado', [
            'id'           => $pago_id,
            'cliente_id'   => (int)$pago['cliente_id'],
            'cotizacion_id'=> (int)$pago['cotizacion_id'],
            'triggered_by' => $user['id'],
            'title'        => "Voucher pendiente — {$pago['empresa']}",
            'message'      => "Pago {$tipo_label} ({$monto_fmt}) de {$pago['cot_numero']} requiere validación.",
            'url'          => APP_URL . '/modules/pagos/index.php?pago_id=' . $pago_id,
        ]);
    } catch (\Exception $e) {
        error_log('[ajax_pago] subir_voucher notif error: ' . $e->getMessage());
    }

    _pago_json(['ok' => true, 'msg' => 'Voucher subido. Pendiente de validación por el gerente.']);
}

// ──────────────────────────────────────────────────────
// ACCIÓN: Validar pago (solo gerente_general)
// ──────────────────────────────────────────────────────
if ($accion === 'validar') {

    if (!has_role('gerente_general', 'gerente_operaciones')) {
        _pago_json(['ok' => false, 'msg' => 'Sin permiso']);
    }
    if ($pago['estado'] !== 'pendiente_validacion') {
        _pago_json(['ok' => false, 'msg' => 'El pago no está pendiente de validación']);
    }

    $pdo->prepare("
        UPDATE pagos_cliente SET
          estado            = 'validado',
          validado_por      = ?,
          fecha_validacion  = NOW(),
          notas_validacion  = NULL
        WHERE id = ?
    ")->execute([$user['id'], $pago_id]);

    // El voucher quedó resuelto → limpiar la notificación interna "pago_registrado"
    // del cliente para todos los validadores (aunque no hayan entrado por la campana).
    NotificationService::markResolved(['pago_registrado'], (int)$pago['cliente_id']);

    try {
        log_actividad('pagos', 'validar', "Pago #{$pago_id} validado");
        $tipo_label = $pago['tipo'] === 'inicial' ? 'Inicial' : 'Final';
        $moneda_sym = $pago['moneda'] === 'USD' ? 'USD ' : 'S/ ';
        $monto_fmt  = $moneda_sym . number_format($pago['monto'], 2);
        NotificationService::dispatch('pago_aprobado', [
            'id'           => $pago_id,
            'cliente_id'   => (int)$pago['cliente_id'],
            'cotizacion_id'=> (int)$pago['cotizacion_id'],
            'triggered_by' => $user['id'],
            'title'        => "Pago aprobado — {$pago['empresa']}",
            'message'      => "Pago {$tipo_label} ({$monto_fmt}) de {$pago['cot_numero']} fue aprobado ✓",
            'url'          => APP_URL . '/modules/clientes/ver.php?id=' . $pago['cliente_id'] . '#pagos',
        ]);
    } catch (\Exception $e) {
        error_log('[ajax_pago] validar notif error: ' . $e->getMessage());
    }

    // ── Factura por cargar: si con esta validación AMBOS pagos (inicial+final) quedaron
    //    validados, la cotización aplica IGV y aún no tiene factura → avisar a gerencia
    //    (push + notificación interna) para que emita y cargue la factura.
    try {
        $cotId = (int)$pago['cotizacion_id'];
        $chk = $pdo->prepare("
            SELECT cot.incluye_igv,
                   (SELECT COUNT(*) FROM pagos_cliente pc WHERE pc.cotizacion_id=cot.id AND pc.deleted_at IS NULL AND pc.tipo='inicial' AND pc.estado='validado') AS ini_ok,
                   (SELECT COUNT(*) FROM pagos_cliente pc WHERE pc.cotizacion_id=cot.id AND pc.deleted_at IS NULL AND pc.tipo='final'   AND pc.estado='validado') AS fin_ok,
                   (SELECT COUNT(*) FROM facturas_cliente f WHERE f.cotizacion_id=cot.id)                                                                       AS tiene_factura
            FROM cotizaciones cot WHERE cot.id = ?
        ");
        $chk->execute([$cotId]);
        $row = $chk->fetch();
        if ($row && (int)$row['incluye_igv'] === 1
                 && (int)$row['ini_ok']   >= 1
                 && (int)$row['fin_ok']   >= 1
                 && (int)$row['tiene_factura'] === 0) {
            NotificationService::dispatch('factura_por_cargar', [
                'cliente_id'    => (int)$pago['cliente_id'],
                'cotizacion_id' => $cotId,
                'triggered_by'  => $user['id'],
                'title'         => "Factura por cargar — {$pago['empresa']}",
                'message'       => "Pagos completos de {$pago['cot_numero']}. Ya puedes emitir y cargar la factura.",
                'url'           => APP_URL . '/modules/clientes/ver.php?id=' . (int)$pago['cliente_id'],
            ]);
        }
    } catch (\Exception $e) {
        error_log('[ajax_pago] factura_por_cargar notif error: ' . $e->getMessage());
    }

    // ── Bono al ejecutivo: SOLO cuando el cliente pagó el TOTAL (todos los pagos de la
    //    cotización validados). Antes se generaba al aceptar la propuesta; ahora es al pago
    //    total. generar_bonos_cotizacion es idempotente (no duplica si ya existe). ──
    try {
        $cotId = (int)$pago['cotizacion_id'];
        $pc = $pdo->prepare("
            SELECT COUNT(*) AS total, SUM(estado = 'validado') AS validados
            FROM pagos_cliente
            WHERE cotizacion_id = ? AND deleted_at IS NULL
        ");
        $pc->execute([$cotId]);
        $pcr = $pc->fetch();
        if ($pcr && (int)$pcr['total'] > 0 && (int)$pcr['total'] === (int)$pcr['validados']) {
            require_once __DIR__ . '/../bonos/ajax_generar.php';
            generar_bonos_cotizacion($pdo, $cotId);
        }
    } catch (\Throwable $e) {
        error_log('[ajax_pago] generar bonos error: ' . $e->getMessage());
    }

    // ── 1er recordatorio de datos de envío INMEDIATO al pago total ──────────────
    //    (el 2do/final lo sigue enviando el cron a los ≥2 días). Mismas condiciones
    //    que cron/recordatorio_datos_envio.php: envío en "Por preparar", sin datos,
    //    cliente con opt-in/teléfono/token, y aún sin recordatorio (record_n=0).
    //    Se marca record_n=1 para que el cron no repita el primero.
    try {
        $cotId = (int)$pago['cotizacion_id'];
        $pc2 = $pdo->prepare("SELECT COUNT(*) AS total, SUM(estado='validado') AS ok FROM pagos_cliente WHERE cotizacion_id=? AND deleted_at IS NULL");
        $pc2->execute([$cotId]);
        $pr2 = $pc2->fetch();
        if ($pr2 && (int)$pr2['total'] > 0 && (int)$pr2['total'] === (int)$pr2['ok']) {
            $est_pp = (int)($pdo->query("SELECT id FROM estados_envio WHERE LOWER(nombre)='por preparar' LIMIT 1")->fetchColumn() ?: 7);
            $envStmt = $pdo->prepare("
                SELECT e.id, e.cliente_id, c.portal_token
                FROM envios e
                JOIN clientes c ON c.id = e.cliente_id
                WHERE e.deleted_at IS NULL
                  AND e.cotizacion_id = ?
                  AND e.estado_envio_id = ?
                  AND e.sin_datos_envio = 0
                  AND e.recordatorio_datos_n = 0
                  AND (e.agencia_envio_id IS NULL OR e.destinatario IS NULL OR e.destinatario = '')
                  AND c.whatsapp_opt_in = 1
                  AND c.contacto_telefono IS NOT NULL AND c.contacto_telefono != ''
                  AND c.portal_token IS NOT NULL AND c.portal_token != ''
            ");
            $envStmt->execute([$cotId, $est_pp]);
            $marcar = $pdo->prepare("UPDATE envios SET recordatorio_datos_n = 1, recordatorio_datos_at = NOW() WHERE id = ?");
            foreach ($envStmt->fetchAll(\PDO::FETCH_ASSOC) as $ev) {
                NotificationService::dispatch('envio_datos_pendientes', [
                    'id'           => (int)$ev['id'],
                    'cliente_id'   => (int)$ev['cliente_id'],
                    'portal_token' => $ev['portal_token'],
                    'portal_link'  => APP_URL . '/portal/?token=' . $ev['portal_token'],
                    'title'        => 'Datos de envío pendientes',
                    'message'      => "Recordatorio (1/2) para cargar datos del envío #{$ev['id']}.",
                ]);
                $marcar->execute([$ev['id']]);
            }
        }
    } catch (\Throwable $e) {
        error_log('[ajax_pago] recordatorio datos inmediato error: ' . $e->getMessage());
    }

    _pago_json(['ok' => true, 'msg' => 'Pago validado correctamente.']);
}

// ──────────────────────────────────────────────────────
// ACCIÓN: Rechazar pago (solo gerente_general)
// ──────────────────────────────────────────────────────
if ($accion === 'rechazar') {

    if (!has_role('gerente_general', 'gerente_operaciones')) {
        _pago_json(['ok' => false, 'msg' => 'Sin permiso']);
    }
    if ($pago['estado'] !== 'pendiente_validacion') {
        _pago_json(['ok' => false, 'msg' => 'El pago no está pendiente de validación']);
    }

    $notas = trim($_POST['notas'] ?? '');
    if (!$notas) {
        _pago_json(['ok' => false, 'msg' => 'Debe indicar el motivo del rechazo']);
    }

    $pdo->prepare("
        UPDATE pagos_cliente SET
          estado            = 'rechazado',
          validado_por      = ?,
          fecha_validacion  = NOW(),
          notas_validacion  = ?
        WHERE id = ?
    ")->execute([$user['id'], $notas, $pago_id]);

    // Voucher resuelto (rechazado) → limpiar la notificación "pago_registrado" del cliente.
    NotificationService::markResolved(['pago_registrado'], (int)$pago['cliente_id']);

    try {
        log_actividad('pagos', 'rechazar', "Pago #{$pago_id} rechazado — {$notas}");
        if ($pago['ejecutivo_id']) {
            $tipo_label = $pago['tipo'] === 'inicial' ? 'Inicial' : 'Final';
            $moneda_sym = $pago['moneda'] === 'USD' ? 'USD ' : 'S/ ';
            $url_cli    = APP_URL . '/modules/clientes/ver.php?id=' . $pago['cliente_id'] . '#pagos';
            $titulo_n   = "Pago rechazado — {$pago['empresa']}";
            $cuerpo_n   = "Pago {$tipo_label} ({$moneda_sym}" . number_format($pago['monto'], 2) . ") rechazado ✗ Motivo: {$notas}";
            $pdo->prepare("
                INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url_accion)
                VALUES (?, 'pago_rechazado', ?, ?, ?)
            ")->execute([$pago['ejecutivo_id'], $titulo_n, $cuerpo_n, $url_cli]);
            // Web push al móvil del ejecutivo
            PushService::notify((int)$pago['ejecutivo_id'], $titulo_n, $cuerpo_n, $url_cli, 'pago-rechazado-' . $pago_id);
        }
    } catch (\Exception $e) {
        error_log('[ajax_pago] rechazar notif error: ' . $e->getMessage());
    }

    _pago_json(['ok' => true, 'msg' => 'Pago rechazado. El ejecutivo fue notificado.']);
}

_pago_json(['ok' => false, 'msg' => 'Acción no reconocida']);
