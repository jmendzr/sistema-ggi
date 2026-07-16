<?php
/**
 * Desistimiento de propuesta aceptada: la empresa aceptó y luego dejó de responder.
 * Revierte lo generado por la aceptación conservando trazabilidad (a diferencia
 * de eliminar, la propuesta queda visible en estado 'desistida' con su motivo).
 *
 * POST cot_id + preview=1 → devuelve qué se va a revertir, sin tocar nada
 * POST cot_id + motivo    → ejecuta el desistimiento (transaccional):
 *   - cotización → estado 'desistida' (motivo_desistimiento, desistida_at/by)
 *   - certificados 'en_proceso' de la cotización → soft-delete
 *   - pagos NO validados de la cotización → soft-delete (los validados se conservan)
 *   - cliente → 'inactivo' si no le queda negocio activo (otras propuestas
 *     aceptadas o certificados vivos); inactivo también bloquea su portal
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/NotificationService.php';
session_init();
require_login();
header('Content-Type: application/json');

if (!has_role('gerente_general', 'gerente_operaciones')) {
    echo json_encode(['ok' => false, 'msg' => 'Solo gerencia puede marcar una propuesta como desistida.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false]); exit; }
csrf_verify();

$pdo     = Database::get();
$user    = auth_user();
$cot_id  = (int)($_POST['cot_id'] ?? 0);
$preview = (int)($_POST['preview'] ?? 0);
$motivo  = trim($_POST['motivo'] ?? '');

if (!$cot_id) { echo json_encode(['ok' => false, 'msg' => 'ID de propuesta inválido.']); exit; }

$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$cot_id]);
$cot = $stmt->fetch();

if (!$cot) { echo json_encode(['ok' => false, 'msg' => 'Propuesta no encontrada.']); exit; }
if ($cot['estado'] !== 'aceptada') {
    echo json_encode(['ok' => false, 'msg' => 'Solo una propuesta aceptada puede marcarse como desistida.']);
    exit;
}

$cliente_id = (int)($cot['cliente_id'] ?? 0);

// ── Qué se revertiría ──
$q = $pdo->prepare("SELECT COUNT(*) FROM certificados WHERE cotizacion_id = ? AND estado = 'en_proceso' AND deleted_at IS NULL");
$q->execute([$cot_id]); $n_certs_anular = (int)$q->fetchColumn();

$q = $pdo->prepare("SELECT COUNT(*) FROM pagos_cliente WHERE cotizacion_id = ? AND estado <> 'validado' AND deleted_at IS NULL");
$q->execute([$cot_id]); $n_pagos_anular = (int)$q->fetchColumn();

$q = $pdo->prepare("SELECT COUNT(*) FROM pagos_cliente WHERE cotizacion_id = ? AND estado = 'validado' AND deleted_at IS NULL");
$q->execute([$cot_id]); $n_pagos_validados = (int)$q->fetchColumn();

// ¿El cliente quedaría sin negocio activo? (otras aceptadas u otros certificados vivos)
$cliente_desactivar = false;
$cli = null;
if ($cliente_id) {
    $cq = $pdo->prepare("SELECT * FROM clientes WHERE id = ? AND deleted_at IS NULL");
    $cq->execute([$cliente_id]);
    $cli = $cq->fetch();
    if ($cli && $cli['estado'] === 'activo') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE cliente_id = ? AND id <> ? AND estado = 'aceptada' AND deleted_at IS NULL");
        $q->execute([$cliente_id, $cot_id]); $otras_aceptadas = (int)$q->fetchColumn();
        // Certificados vivos ajenos a esta propuesta (o de ella pero ya emitidos, no en_proceso)
        $q = $pdo->prepare("
            SELECT COUNT(*) FROM certificados
            WHERE cliente_id = ? AND deleted_at IS NULL
              AND estado IN ('vigente','por_vencer','en_proceso')
              AND NOT (cotizacion_id = ? AND estado = 'en_proceso')
        ");
        $q->execute([$cliente_id, $cot_id]); $otros_certs = (int)$q->fetchColumn();
        $cliente_desactivar = ($otras_aceptadas === 0 && $otros_certs === 0);
    }
}

if ($preview) {
    $acciones = [];
    if ($n_certs_anular)    $acciones[] = "$n_certs_anular certificado(s) en proceso se anularán";
    if ($n_pagos_anular)    $acciones[] = "$n_pagos_anular pago(s) pendiente(s) se anularán";
    if ($n_pagos_validados) $acciones[] = "$n_pagos_validados pago(s) validado(s) se conservan (dinero ya recibido)";
    if ($cliente_desactivar) $acciones[] = "La empresa pasará a estado Inactivo (su portal quedará bloqueado)";
    echo json_encode([
        'ok'       => true,
        'preview'  => true,
        'numero'   => $cot['numero'],
        'empresa'  => $cot['empresa_nombre'] ?: ($cli['razon_social'] ?? '—'),
        'acciones' => $acciones,
    ]);
    exit;
}

if (mb_strlen($motivo) < 5) {
    echo json_encode(['ok' => false, 'msg' => 'Indica el motivo del desistimiento (mínimo 5 caracteres).']);
    exit;
}

// ── Ejecutar en transacción ──
try {
    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE cotizaciones
        SET estado = 'desistida', motivo_desistimiento = ?, desistida_at = NOW(), desistida_by = ?
        WHERE id = ? AND estado = 'aceptada' AND deleted_at IS NULL
    ")->execute([$motivo, $user['id'], $cot_id]);

    $pdo->prepare("
        UPDATE certificados SET deleted_at = NOW(), deleted_by = ?
        WHERE cotizacion_id = ? AND estado = 'en_proceso' AND deleted_at IS NULL
    ")->execute([$user['id'], $cot_id]);

    $pdo->prepare("
        UPDATE pagos_cliente SET deleted_at = NOW(), deleted_by = ?
        WHERE cotizacion_id = ? AND estado <> 'validado' AND deleted_at IS NULL
    ")->execute([$user['id'], $cot_id]);

    if ($cliente_desactivar) {
        $pdo->prepare("UPDATE clientes SET estado = 'inactivo' WHERE id = ?")->execute([$cliente_id]);
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ajax_desistir] cot #' . $cot_id . ': ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Error al procesar el desistimiento.']);
    exit;
}

log_actividad('propuestas', 'desistir',
    "Propuesta {$cot['numero']} → desistida — Motivo: $motivo" .
    " (certs anulados: $n_certs_anular, pagos anulados: $n_pagos_anular, validados conservados: $n_pagos_validados" .
    ($cliente_desactivar ? ", cliente #$cliente_id → inactivo" : "") . ")");

// ── Notificación interna + push (gerentes + ejecutivo de la propuesta) ──
try {
    $empresa   = trim($cot['empresa_nombre'] ?? '') ?: ($cli['razon_social'] ?? "Propuesta #$cot_id");
    $monto_fmt = 'S/ ' . number_format((float)($cot['monto_total'] ?? 0), 2);
    NotificationService::dispatch('propuesta_desistida', [
        'id'            => $cot_id,
        'cotizacion_id' => $cot_id,
        'cliente_id'    => $cliente_id,
        'cot_numero'    => $cot['numero'],
        'triggered_by'  => $user['id'],
        'title'         => "Propuesta desistida — {$empresa}",
        'message'       => "{$empresa} — {$cot['numero']} ({$monto_fmt}) desistió tras aceptar. Motivo: {$motivo}",
    ]);
} catch (\Throwable $e) {
    error_log('[ajax_desistir] notif error: ' . $e->getMessage());
}

echo json_encode([
    'ok'                 => true,
    'estado'             => 'desistida',
    'certs_anulados'     => $n_certs_anular,
    'pagos_anulados'     => $n_pagos_anular,
    'pagos_conservados'  => $n_pagos_validados,
    'cliente_inactivado' => $cliente_desactivar,
]);
