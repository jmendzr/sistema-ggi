<?php
/**
 * Eliminación lógica de propuestas/cotizaciones (soft delete via deleted_at).
 * NO restringe por estado — cualquier estado puede eliminarse.
 * POST preview=1  → devuelve info de dependencias sin eliminar
 * POST preview=0  → elimina definitivamente
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json');

if (!has_role('gerente_general', 'gerente_operaciones')) {
    echo json_encode(['ok' => false, 'msg' => 'Sin permiso para eliminar propuestas.']);
    exit;
}

csrf_verify();

$pdo    = Database::get();
$cot_id = (int)($_POST['cot_id'] ?? 0);
$preview = (int)($_POST['preview'] ?? 0);
$user   = auth_user();

if (!$cot_id) {
    echo json_encode(['ok' => false, 'msg' => 'ID de propuesta inválido.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$cot_id]);
$cot = $stmt->fetch();

if (!$cot) {
    echo json_encode(['ok' => false, 'msg' => 'Propuesta no encontrada o ya fue eliminada.']);
    exit;
}

// Conteo de dependencias (solo informativo — no bloquean)
$n_certs  = (int)$pdo->prepare("SELECT COUNT(*) FROM certificados  WHERE cotizacion_id = ? AND deleted_at IS NULL")->execute([$cot_id])
    ? 0 : 0;
$q = $pdo->prepare("SELECT COUNT(*) FROM certificados  WHERE cotizacion_id = ? AND deleted_at IS NULL"); $q->execute([$cot_id]); $n_certs = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM pagos_cliente WHERE cotizacion_id = ?");                         $q->execute([$cot_id]); $n_pagos = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM envios        WHERE cotizacion_id = ? AND deleted_at IS NULL"); $q->execute([$cot_id]); $n_envios = (int)$q->fetchColumn();

if ($preview) {
    $deps = [];
    if ($n_certs)  $deps[] = "$n_certs certificado(s) generado(s)";
    if ($n_pagos)  $deps[] = "$n_pagos pago(s) asociado(s)";
    if ($n_envios) $deps[] = "$n_envios envío(s) asociado(s)";

    $estados_labels = [
        'borrador'=>'Borrador','enviada'=>'Enviada','aceptada'=>'Aceptada',
        'rechazada'=>'Rechazada','vencida'=>'Vencida','en_revision'=>'En revisión',
    ];

    echo json_encode([
        'ok'      => true,
        'preview' => true,
        'numero'  => $cot['numero'],
        'estado'  => $estados_labels[$cot['estado']] ?? $cot['estado'],
        'empresa' => $cot['empresa_nombre'] ?: ($cot['cliente_id'] ? "Cliente #{$cot['cliente_id']}" : '—'),
        'deps'    => $deps,
        'hay_deps'=> !empty($deps),
        'es_aceptada' => $cot['estado'] === 'aceptada',
    ]);
    exit;
}

// Eliminación lógica — sin importar el estado
$pdo->prepare("
    UPDATE cotizaciones SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
")->execute([$user['id'], $cot_id]);

log_actividad('propuestas', 'eliminar',
    "Propuesta {$cot['numero']} eliminada (estado: {$cot['estado']}, empresa: " . ($cot['empresa_nombre'] ?: '—') . ")");

echo json_encode(['ok' => true, 'msg' => 'Propuesta eliminada correctamente.']);
