<?php
// modules/certificados/ajax_bulk.php — Acciones masivas sobre certificados
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();

header('Content-Type: application/json; charset=utf-8');

// Solo gerentes pueden hacer acciones masivas
if (!has_role('gerente_general', 'gerente_operaciones')) {
    echo json_encode(['ok' => false, 'error' => 'Sin permiso.']);
    exit;
}

// Leer JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !is_array($data)) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos.']);
    exit;
}

// Verificar CSRF
csrf_verify();

$ids          = array_filter(array_map('intval', $data['ids'] ?? []), fn($id) => $id > 0);
$nuevo_estado = $data['estado'] ?? '';

$estados_validos = ['vigente', 'por_vencer', 'vencido', 'en_proceso', 'suspendido'];
if (empty($ids) || !in_array($nuevo_estado, $estados_validos)) {
    echo json_encode(['ok' => false, 'error' => 'Parámetros inválidos.']);
    exit;
}

if (count($ids) > 200) {
    echo json_encode(['ok' => false, 'error' => 'Máximo 200 registros por operación.']);
    exit;
}

$pdo = Database::get();

$ph   = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("UPDATE certificados SET estado = ? WHERE id IN ($ph)");
$params = array_merge([$nuevo_estado], array_values($ids));
$stmt->execute($params);
$actualizados = $stmt->rowCount();

$label_estados = [
    'vigente'    => 'Vigente',
    'por_vencer' => 'Por vencer',
    'vencido'    => 'Vencido',
    'en_proceso' => 'En proceso',
    'suspendido' => 'Suspendido',
];

log_actividad('certificados', 'bulk_estado', "Estado masivo → {$nuevo_estado} en " . count($ids) . " certificados");

// ── Trigger de envío: si el nuevo estado es vigente, verificar por cada cert
//    si ya todos los de su propuesta son activos → crear envío
$envios_generados = [];
if ($nuevo_estado === 'vigente') {
    require_once __DIR__ . '/../envios/auto_generar.php';
    foreach ($ids as $cid) {
        $env = verificar_envio_por_cotizacion($pdo, $cid, $user['id']);
        if ($env && !in_array($env, $envios_generados)) {
            $envios_generados[] = $env;
            log_actividad('envios', 'auto_crear',
                "Envío #{$env} generado automáticamente (bulk) desde certificado #{$cid}");
        }
    }
}

echo json_encode([
    'ok'              => true,
    'actualizados'    => $actualizados,
    'mensaje'         => $actualizados . ' certificado' . ($actualizados !== 1 ? 's' : '') . ' actualizado' . ($actualizados !== 1 ? 's' : '') . ' a "' . ($label_estados[$nuevo_estado] ?? $nuevo_estado) . '".',
    'envios_creados'  => $envios_generados,
]);
