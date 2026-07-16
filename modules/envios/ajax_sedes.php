<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/ShalomSedesService.php';
require_once __DIR__ . '/../../core/OlvaSedesService.php';
session_init();
require_login();
session_write_close();
header('Content-Type: application/json; charset=utf-8');

$agencia_id = (int)($_GET['agencia_id'] ?? 0);
if (!$agencia_id) { echo json_encode(['sedes' => []]); exit; }

$cliente_id = (int)($_GET['cliente_id'] ?? 0);
$envio_id   = (int)($_GET['envio_id']   ?? 0);  // excluir el envío en edición de la sugerencia

$pdo    = Database::get();
$stmt   = $pdo->prepare("SELECT nombre FROM agencias_envio WHERE id = ?");
$stmt->execute([$agencia_id]);
$nombre = strtolower($stmt->fetchColumn() ?? '');

$sedes = [];

if (str_contains($nombre, 'shalom')) {
    foreach (ShalomSedesService::listar() as $s) {
        $sedes[] = [
            'valor' => $s['label'],   // lo que se guarda en sede_registro
            'label' => $s['label'],   // lo que se muestra: "DEP / PROV / DISTRITO / LUGAR"
            'dep'   => $s['departamento'],
        ];
    }
} elseif (str_contains($nombre, 'olva')) {
    foreach (OlvaSedesService::listar() as $s) {
        $sedes[] = [
            'valor'  => $s['label'],
            'label'  => $s['label'],   // "DEPARTAMENTO - PROVINCIA - NOMBRE"
            'dep'    => $s['departamento'],
            'ubigeo' => $s['id'] ?? '', // ubigeo del distrito → lista de tiendas Olva
        ];
    }
}

// Última sede usada por este cliente con esta agencia (en OTRO envío, no borrado)
$sede_sugerida = null;
if ($cliente_id) {
    $sq = $pdo->prepare("
        SELECT sede_registro FROM envios
        WHERE cliente_id = ? AND agencia_envio_id = ?
          AND sede_registro IS NOT NULL AND sede_registro != ''
          AND deleted_at IS NULL
          AND id != ?
        ORDER BY id DESC LIMIT 1
    ");
    $sq->execute([$cliente_id, $agencia_id, $envio_id ?: 0]);
    $sede_sugerida = $sq->fetchColumn() ?: null;
}

echo json_encode(['sedes' => $sedes, 'sede_sugerida' => $sede_sugerida], JSON_UNESCAPED_UNICODE);
