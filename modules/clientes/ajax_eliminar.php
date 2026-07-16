<?php
/**
 * Eliminación lógica de empresas/clientes (soft delete via deleted_at).
 * NO elimina físicamente. La empresa desaparece del listado pero
 * sus propuestas, certificados y envíos históricos siguen accesibles.
 * POST preview=1  → devuelve info de dependencias sin eliminar
 * POST preview=0  → elimina definitivamente
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json');

// Solo Gerente General puede eliminar empresas
if (!has_role('gerente_general')) {
    echo json_encode(['ok' => false, 'msg' => 'Solo el Gerente General puede eliminar empresas.']);
    exit;
}

csrf_verify();

$pdo        = Database::get();
$cliente_id = (int)($_POST['cliente_id'] ?? 0);
$preview    = (int)($_POST['preview']    ?? 0);
$user       = auth_user();

if (!$cliente_id) {
    echo json_encode(['ok' => false, 'msg' => 'ID de empresa inválido.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$cliente_id]);
$cli = $stmt->fetch();

if (!$cli) {
    echo json_encode(['ok' => false, 'msg' => 'Empresa no encontrada o ya fue eliminada.']);
    exit;
}

// Conteo de dependencias — solo informativo, NO bloquean
$q = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE cliente_id = ? AND deleted_at IS NULL"); $q->execute([$cliente_id]); $n_cots  = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM certificados WHERE cliente_id = ? AND deleted_at IS NULL"); $q->execute([$cliente_id]); $n_certs = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM envios       WHERE cliente_id = ? AND deleted_at IS NULL"); $q->execute([$cliente_id]); $n_envios = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM pagos_cliente WHERE cliente_id = ?");                       $q->execute([$cliente_id]); $n_pagos  = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT COUNT(*) FROM auditorias a JOIN certificados c ON c.id=a.certificado_id WHERE c.cliente_id = ?"); $q->execute([$cliente_id]); $n_aud = (int)$q->fetchColumn();

if ($preview) {
    $deps = [];
    if ($n_cots)  $deps[] = "$n_cots propuesta(s)";
    if ($n_certs) $deps[] = "$n_certs certificado(s)";
    if ($n_envios)$deps[] = "$n_envios envío(s)";
    if ($n_pagos) $deps[] = "$n_pagos pago(s)";
    if ($n_aud)   $deps[] = "$n_aud auditoría(s)";

    echo json_encode([
        'ok'       => true,
        'preview'  => true,
        'empresa'  => $cli['razon_social'],
        'ruc'      => $cli['ruc'],
        'estado'   => ucfirst($cli['estado']),
        'codigo'   => $cli['codigo_interno'],
        'deps'     => $deps,
        'hay_deps' => !empty($deps),
        'n_total'  => $n_cots + $n_certs + $n_envios + $n_pagos + $n_aud,
    ]);
    exit;
}

// Soft delete — los registros relacionados quedan en el sistema con cliente_id intacto
$pdo->prepare("
    UPDATE clientes SET deleted_at = NOW(), deleted_by = ?
    WHERE id = ? AND deleted_at IS NULL
")->execute([$user['id'], $cliente_id]);

log_actividad('clientes', 'eliminar',
    "Empresa #{$cliente_id} ({$cli['razon_social']}, RUC: {$cli['ruc']}) eliminada (estado: {$cli['estado']})");

echo json_encode(['ok' => true, 'msg' => 'Empresa eliminada correctamente.']);
