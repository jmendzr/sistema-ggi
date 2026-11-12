<?php
define('NS_DEBUG', true); // TODO: quitar tras confirmar notificaciones en producción
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/PushService.php';
require_once __DIR__ . '/../../core/NotificationService.php';
require_once __DIR__ . '/../../vendor/autoload.php';
session_init();
require_login();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false]); exit; }
csrf_verify();

$user      = auth_user();
$nuevo_est = trim($_POST['estado'] ?? '');
$validos   = ['borrador','enviada','aceptada','rechazada','vencida'];

if (!in_array($nuevo_est, $validos)) {
    echo json_encode(['ok'=>false,'msg'=>'Estado inválido']); exit;
}

$pdo = Database::get();

// ── Bulk: cot_ids ──
if (!empty($_POST['cot_ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $_POST['cot_ids'])));
    if (empty($ids)) { echo json_encode(['ok'=>false,'msg'=>'Sin IDs']); exit; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    // Al aceptar, registrar también la fecha de cierre de cada propuesta.
    $set_fecha = $nuevo_est === 'aceptada' ? ", fecha_aceptacion=COALESCE(fecha_aceptacion, NOW())" : "";
    if ($user['rol'] === 'ejecutivo_ventas') {
        $pdo->prepare("UPDATE cotizaciones SET estado=?$set_fecha WHERE id IN ($ph) AND ejecutivo_id=? AND estado NOT IN ('aceptada','rechazada','vencida','desistida')")
            ->execute(array_merge([$nuevo_est], $ids, [$user['id']]));
    } else {
        $pdo->prepare("UPDATE cotizaciones SET estado=?$set_fecha WHERE id IN ($ph) AND estado NOT IN ('aceptada','rechazada','vencida','desistida')")
            ->execute(array_merge([$nuevo_est], $ids));
    }
    log_actividad('propuestas','estado_bulk',"Bulk ".count($ids)." propuestas → $nuevo_est");

    // ── Al ACEPTAR en lote: activar cliente + crear certificados y pagos por cada
    //    propuesta (antes el bulk solo cambiaba el estado → quedaban SIN cert ni pago).
    //    Usa el helper compartido con el flujo individual. Idempotente.
    if ($nuevo_est === 'aceptada') {
        require_once __DIR__ . '/aceptar_helper.php';
        $acc = $pdo->prepare("SELECT id, cliente_id FROM cotizaciones WHERE id IN ($ph) AND estado='aceptada' AND cliente_id IS NOT NULL");
        $acc->execute($ids);
        foreach ($acc->fetchAll(\PDO::FETCH_ASSOC) as $ac) {
            try {
                $pdo->prepare("UPDATE clientes SET estado='activo' WHERE id=?")->execute([(int)$ac['cliente_id']]);
                cot_generar_certs_y_pagos($pdo, (int)$ac['id'], (int)$ac['cliente_id'], (int)$user['id']);
            } catch (\Throwable $e) {
                error_log('[ajax_estado_cot bulk aceptar] cot '.$ac['id'].': '.$e->getMessage());
            }
        }
    }

    echo json_encode(['ok'=>true]); exit;
}

// ── Individual: cot_id ──
$cot_id = (int)($_POST['cot_id'] ?? 0);
if (!$cot_id) { echo json_encode(['ok'=>false,'msg'=>'ID inválido']); exit; }

$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id=?");
$stmt->execute([$cot_id]);
$cot = $stmt->fetch();

if (!$cot) { echo json_encode(['ok'=>false,'msg'=>'No encontrada']); exit; }
if ($user['rol'] === 'ejecutivo_ventas' && $cot['ejecutivo_id'] != $user['id']) {
    echo json_encode(['ok'=>false,'msg'=>'Sin permiso']); exit;
}
if (in_array($cot['estado'], ['aceptada','rechazada','vencida','desistida'])) {
    echo json_encode(['ok'=>false,'msg'=>'Estado final no modificable']); exit;
}

// Al aceptar, registrar la fecha de cierre de la propuesta (no se sobreescribe si ya existe).
if ($nuevo_est === 'aceptada') {
    // Fecha en que el cliente necesita sus certificados — OBLIGATORIA al aceptar.
    // Alimenta la alerta push al Gerente General 2 días antes (cron).
    $fecha_req = trim($_POST['fecha_requerida_certificados'] ?? '');
    if ($fecha_req === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_req) || !strtotime($fecha_req)) {
        echo json_encode(['ok'=>false,'msg'=>'Indica la fecha en que el cliente necesita sus certificados.']); exit;
    }
    if ($fecha_req < date('Y-m-d')) {
        echo json_encode(['ok'=>false,'msg'=>'La fecha de necesidad de certificados no puede ser pasada.']); exit;
    }
    // alerta_cert_at=NULL → habilita que el cron dispare la alerta (2 días antes).
    $pdo->prepare("UPDATE cotizaciones SET estado=?, fecha_aceptacion=COALESCE(fecha_aceptacion, NOW()), fecha_requerida_certificados=?, alerta_cert_at=NULL WHERE id=?")
        ->execute([$nuevo_est, $fecha_req, $cot_id]);
} else {
    $pdo->prepare("UPDATE cotizaciones SET estado=? WHERE id=?")->execute([$nuevo_est, $cot_id]);
}
$rechazo_motivo = trim($_POST['rechazo_motivo'] ?? '');
$log_desc = "Propuesta #$cot_id → $nuevo_est";
if ($nuevo_est === 'rechazada' && $rechazo_motivo) $log_desc .= " — Motivo: $rechazo_motivo";
log_actividad('propuestas','estado', $log_desc);

$response = ['ok' => true, 'estado' => $nuevo_est];

// ── Si se acepta → gestionar cliente y certificados ──
if ($nuevo_est === 'aceptada') { try {

    // Partimos del cliente_id que ya tiene la propuesta (empresa existente)
    // o null si es empresa nueva sin asociar aún
    $cliente_id_final = (int)($cot['cliente_id'] ?? 0) ?: null;

    // ── Solo si es empresa nueva y aún no tiene cliente vinculado: buscar o crear ──
    if (!$cliente_id_final && $cot['tipo_empresa'] === 'nueva') {
        // 1. Buscar por RUC
        if (!empty($cot['empresa_ruc'])) {
            $ex = $pdo->prepare("SELECT id FROM clientes WHERE ruc=?");
            $ex->execute([$cot['empresa_ruc']]);
            $cliente_id_final = (int)$ex->fetchColumn() ?: null;
        }
        // 2. Buscar por razón social
        if (!$cliente_id_final && !empty($cot['empresa_nombre'])) {
            $ex = $pdo->prepare("SELECT id FROM clientes WHERE razon_social=?");
            $ex->execute([$cot['empresa_nombre']]);
            $cliente_id_final = (int)$ex->fetchColumn() ?: null;
        }
        // 3. Crear nuevo cliente
        if (!$cliente_id_final) {
            // ── Auto-reparar secuencia CLIENTE si está desincronizada con los datos reales ──
            // El trigger usa secuencias.ultimo para generar codigo_interno (GGI-YYYY-NNN).
            // Si esa tabla fue reiniciada, el trigger generaría un código ya existente
            // y lanzaría un error de clave duplicada. Sincronizamos antes de insertar.
            $max_seq = $pdo->query("
                SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo_interno, '-', -1) AS UNSIGNED)), 0)
                FROM clientes
                WHERE codigo_interno REGEXP '^GGI-[0-9]+-[0-9]+'
            ")->fetchColumn();
            $pdo->prepare("UPDATE secuencias SET ultimo = GREATEST(ultimo, ?) WHERE tipo = 'CLIENTE'")
                ->execute([(int)$max_seq]);

            $ej_id_cot = $cot['ejecutivo_id'] ?: null;
            $pdo->prepare("
                INSERT INTO clientes
                  (ruc, razon_social, direccion, departamento, provincia, distrito, ubigeo,
                   contacto_nombre, contacto_apellido, contacto_cargo,
                   contacto_telefono, contacto_email,
                   num_trabajadores,
                   estado, fecha_contrato,
                   ejecutivo_id, created_by_user_id, responsible_user_id,
                   creado_por)
                VALUES (?,?,?,?,?,?,?,  ?,?,?,  ?,?,  ?,  'activo', CURDATE(),  ?,?,?,  ?)
            ")->execute([
                $cot['empresa_ruc']       ?: '00000000000',
                $cot['empresa_nombre']    ?: 'Sin nombre',
                $cot['empresa_direccion'] ?: null,
                $cot['empresa_dep']       ?: null,
                $cot['empresa_prov']      ?: null,
                $cot['empresa_dist']      ?: null,
                $cot['empresa_ubigeo']    ?: null,
                $cot['contacto_nombre']   ?: null,
                $cot['contacto_apellido'] ?: null,
                $cot['contacto_cargo']    ?: null,
                $cot['contacto_telefono'] ?: null,
                $cot['contacto_email']    ?: null,
                isset($cot['num_trabajadores']) ? (int)$cot['num_trabajadores'] : null,
                $ej_id_cot,
                $ej_id_cot,
                $ej_id_cot,
                $user['id'],
            ]);
            $cliente_id_final = (int)$pdo->lastInsertId();
            // Registrar historial de creación
            if ($cliente_id_final && $ej_id_cot) {
                $pdo->prepare("
                    INSERT INTO historial_responsables_cliente
                      (cliente_id, responsable_anterior, responsable_nuevo, motivo, cotizacion_id, registrado_por, notas)
                    VALUES (?, NULL, ?, 'creacion', ?, ?, 'Cliente creado al aceptar propuesta')
                ")->execute([$cliente_id_final, $ej_id_cot, $cot_id, $user['id']]);
            }
            log_actividad('clientes', 'crear', "Cliente creado desde propuesta #$cot_id");
        }
        // Vincular cliente a la propuesta
        $pdo->prepare("UPDATE cotizaciones SET cliente_id=? WHERE id=?")
            ->execute([$cliente_id_final, $cot_id]);
    }

    // ── Fallback: empresa existente pero cliente_id no guardado → buscar por RUC ──
    if (!$cliente_id_final && !empty($cot['empresa_ruc'])) {
        $ex = $pdo->prepare("SELECT id FROM clientes WHERE ruc=? LIMIT 1");
        $ex->execute([$cot['empresa_ruc']]);
        $cliente_id_final = (int)$ex->fetchColumn() ?: null;
        if ($cliente_id_final) {
            $pdo->prepare("UPDATE cotizaciones SET cliente_id=? WHERE id=?")
                ->execute([$cliente_id_final, $cot_id]);
        }
    }
    if (!$cliente_id_final && !empty($cot['empresa_nombre'])) {
        $ex = $pdo->prepare("SELECT id FROM clientes WHERE razon_social=? LIMIT 1");
        $ex->execute([$cot['empresa_nombre']]);
        $cliente_id_final = (int)$ex->fetchColumn() ?: null;
        if ($cliente_id_final) {
            $pdo->prepare("UPDATE cotizaciones SET cliente_id=? WHERE id=?")
                ->execute([$cliente_id_final, $cot_id]);
        }
    }

    // ── Actualizar estado del cliente a activo (nueva O existente) ──
    if ($cliente_id_final) {
        $pdo->prepare("UPDATE clientes SET estado='activo' WHERE id=?")
            ->execute([$cliente_id_final]);
        // Propagar num_trabajadores si la cotización lo tiene y el cliente aún no
        if (!empty($cot['num_trabajadores'])) {
            $pdo->prepare("UPDATE clientes SET num_trabajadores=? WHERE id=? AND num_trabajadores IS NULL")
                ->execute([(int)$cot['num_trabajadores'], $cliente_id_final]);
        }
        log_actividad('clientes', 'estado', "Cliente #$cliente_id_final → activo (propuesta #$cot_id)");
        $response['cliente_id'] = $cliente_id_final;

        // ── Enganche de atribución: vincular la conversación de WhatsApp (incl. la del
        //    anuncio con ctwa_clid) al cliente por teléfono, para no perder la venta en
        //    Meta Ads si el chat aún no estaba vinculado. Automático, no bloquea.
        try {
            require_once __DIR__ . '/../../core/WaInbox.php';
            $_tel_wa = $cot['contacto_telefono'] ?? '';
            if ($_tel_wa !== '') WaInbox::vincularPorTelefono($pdo, (int)$cliente_id_final, $_tel_wa);
        } catch (\Throwable $eWa) { error_log('[ajax_estado_cot] vincular WA: '.$eWa->getMessage()); }
    }

    // ── Transferencia de responsabilidad (si hay ejecutivo distinto al actual) ──
    // Solo aplica a clientes EXISTENTES (los nuevos ya tienen el ejecutivo correcto arriba).
    // La responsabilidad se transfiere al ejecutivo de la propuesta aceptada.
    if ($cliente_id_final && !empty($cot['ejecutivo_id'])) {
        $cli_actual = $pdo->prepare("SELECT responsible_user_id, ejecutivo_id FROM clientes WHERE id=?");
        $cli_actual->execute([$cliente_id_final]);
        $cli_row = $cli_actual->fetch();
        $nuevo_responsable = (int)$cot['ejecutivo_id'];
        $responsable_actual = (int)($cli_row['responsible_user_id'] ?? $cli_row['ejecutivo_id'] ?? 0);

        if ($cli_row && $nuevo_responsable && $nuevo_responsable !== $responsable_actual) {
            // Actualizar responsible_user_id (ejecutivo_id no se toca — backward compat)
            $pdo->prepare("UPDATE clientes SET responsible_user_id = ? WHERE id = ?")
                ->execute([$nuevo_responsable, $cliente_id_final]);

            // Registrar en historial
            $pdo->prepare("
                INSERT INTO historial_responsables_cliente
                  (cliente_id, responsable_anterior, responsable_nuevo, motivo, cotizacion_id, registrado_por, notas)
                VALUES (?, ?, ?, 'aceptacion_propuesta', ?, ?, ?)
            ")->execute([
                $cliente_id_final,
                $responsable_actual ?: null,
                $nuevo_responsable,
                $cot_id,
                $user['id'],
                "Propuesta #$cot_id aceptada — responsabilidad transferida automáticamente",
            ]);

            log_actividad('clientes', 'reasignacion',
                "Cliente #$cliente_id_final: responsable cambió de #$responsable_actual a #$nuevo_responsable (propuesta #$cot_id)");
        }
    }

    // ── Certificados en_proceso (por norma) + pagos 50/50 ──
    //    Helper compartido con el flujo bulk; idempotente (no duplica).
    if ($cliente_id_final) {
        require_once __DIR__ . '/aceptar_helper.php';
        $_cp = cot_generar_certs_y_pagos($pdo, $cot_id, (int)$cliente_id_final, (int)$user['id']);
        $response['certs_creados'] = $_cp['certs'];
    }

    // ── Bonos: NO se generan al aceptar la propuesta. El bono se asigna al ejecutivo
    //    SOLO cuando el cliente paga el TOTAL (todos los pagos validados) → ver
    //    modules/pagos/ajax_pago.php (acción 'validar'). ──

    // ── Envío: se genera cuando TODOS los certificados de esta propuesta
    //    pasen a estado 'vigente'. Ver módulo certificados/ajax_estado_cert.php,
    //    certificados/editar.php y certificados/ajax_bulk.php. ──

    // ── Meta Ads: el cierre de venta se reporta a la Conversions API cuando entra
    //    el PRIMER pago (pago inicial validado), no al aceptar. Ver
    //    modules/pagos/ajax_pago.php (acción 'validar', tipo='inicial'). ──
} catch (\Throwable $e) {
    $err_detail = $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('[ajax_estado_cot] Error en bloque aceptada: ' . $err_detail);
    $response['_proceso_error'] = $err_detail; // visible en consola JS para diagnóstico
}
}

// ── Web Push al ejecutivo y gerentes cuando la propuesta cambia a aceptada/rechazada ─
try {
    if (in_array($nuevo_est, ['aceptada', 'rechazada'], true)) {
        $empresa   = trim($cot['empresa_nombre'] ?? $cot['empresa_ruc'] ?? '') ?: "Propuesta #{$cot_id}";
        $monto_fmt = 'S/ ' . number_format((float)($cot['monto_total'] ?? 0), 2);
        $nota       = trim($cot['notas'] ?? '');
        $nota_short = $nota !== '' ? (mb_strlen($nota) > 80 ? mb_substr($nota, 0, 77) . '…' : $nota) : '';

        if ($nuevo_est === 'aceptada') {
            $push_title    = "¡Propuesta aceptada!";
            $push_body_ej  = "{$empresa} — {$cot['numero']} ({$monto_fmt})";
            $push_body_ger = "{$empresa} — {$cot['numero']} ({$monto_fmt})";
        } else {
            $push_title    = "Propuesta rechazada";
            $rechazo_txt   = !empty($rechazo_motivo) ? ": {$rechazo_motivo}" : '';
            $push_body_ej  = "{$empresa} — {$cot['numero']} ({$monto_fmt}){$rechazo_txt}";
            $push_body_ger = $push_body_ej;
        }

        // Agregar nota interna si existe
        if ($nota_short !== '') {
            $push_body_ej  .= "\nNota: {$nota_short}";
            $push_body_ger .= "\nNota: {$nota_short}";
        }

        $push_url = APP_URL . '/modules/cotizaciones/ver.php?id=' . $cot_id;
        $push_tag = 'cot-' . $nuevo_est . '-' . $cot_id;

        // Push al ejecutivo de la propuesta (si no es quien cambió el estado)
        $ejecutivo_id = (int)($cot['ejecutivo_id'] ?? 0);
        if ($ejecutivo_id && $ejecutivo_id !== (int)$user['id']) {
            PushService::notify($ejecutivo_id, $push_title, $push_body_ej, $push_url, $push_tag);
        }

        // Notificación interna + push a gerentes cuando la propuesta es aceptada
        if ($nuevo_est === 'aceptada') {
            NotificationService::dispatch('propuesta_aceptada', [
                'id'           => $cot_id,
                'cliente_id'   => $cliente_id_final ?? (int)($cot['cliente_id'] ?? 0),
                'cotizacion_id'=> $cot_id,
                'cot_numero'   => $cot['numero'],
                'triggered_by' => $user['id'],
                'title'        => "Propuesta aceptada — {$empresa}",
                'message'      => "{$empresa} — {$cot['numero']} ({$monto_fmt}) fue marcada como aceptada.",
            ]);
        }
    }
} catch (\Throwable $e) {
    error_log('[ajax_estado_cot] push error: ' . $e->getMessage());
}

echo json_encode($response);
