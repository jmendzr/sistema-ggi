<?php
/**
 * ajax_ultimo_envio.php
 * Devuelve los datos reutilizables del último envío (no borrado) de un cliente,
 * para autocompletar al crear uno nuevo: agencia, sede, tienda, dirección, destinatario, DNI.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close();
header('Content-Type: application/json; charset=utf-8');

$cliente_id = (int)($_GET['cliente_id'] ?? 0);
if (!$cliente_id) { echo json_encode(['ok' => false]); exit; }

$pdo  = Database::get();
$stmt = $pdo->prepare("
    SELECT e.agencia_envio_id, ag.nombre AS agencia,
           e.sede_registro, e.tienda_destino, e.direccion_destino,
           e.destinatario, e.destinatario_dni
    FROM envios e
    LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE e.cliente_id = ?
      AND e.deleted_at IS NULL
      AND e.agencia_envio_id IS NOT NULL
    ORDER BY e.id DESC
    LIMIT 1
");
$stmt->execute([$cliente_id]);
$row = $stmt->fetch(\PDO::FETCH_ASSOC);

if (!$row) { echo json_encode(['ok' => false]); exit; }

echo json_encode([
    'ok'   => true,
    'data' => [
        'agencia_envio_id' => (int)$row['agencia_envio_id'],
        'agencia'          => $row['agencia'] ?: '',
        'sede_registro'    => $row['sede_registro'] ?: '',
        'tienda_destino'   => $row['tienda_destino'] ?: '',
        'direccion_destino'=> $row['direccion_destino'] ?: '',
        'destinatario'     => $row['destinatario'] ?: '',
        'destinatario_dni' => $row['destinatario_dni'] ?: '',
    ],
], JSON_UNESCAPED_UNICODE);
