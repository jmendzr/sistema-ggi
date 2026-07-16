<?php
/**
 * portal/ajax_ultimo_envio_portal.php
 * Datos reutilizables del último envío (no borrado) del cliente, para autocompletar
 * en el portal. Excluye el envío que se está completando. Auth por token.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$token    = trim($_GET['token']    ?? '');
$envio_id = (int)($_GET['envio_id'] ?? 0);
if ($token === '') { echo json_encode(['ok' => false]); exit; }

$pdo  = Database::get();
$cli  = $pdo->prepare("SELECT id FROM clientes WHERE portal_token=? AND portal_activo=1 AND estado!='inactivo' AND deleted_at IS NULL LIMIT 1");
$cli->execute([$token]);
$cliente_id = (int)($cli->fetchColumn() ?: 0);
if (!$cliente_id) { echo json_encode(['ok' => false]); exit; }

$stmt = $pdo->prepare("
    SELECT e.agencia_envio_id, ag.nombre AS agencia,
           e.sede_registro, e.tienda_destino, e.direccion_destino,
           e.destinatario, e.destinatario_dni
    FROM envios e
    LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE e.cliente_id = ?
      AND e.deleted_at IS NULL
      AND e.agencia_envio_id IS NOT NULL
      AND e.id != ?
    ORDER BY e.id DESC
    LIMIT 1
");
$stmt->execute([$cliente_id, $envio_id ?: 0]);
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
