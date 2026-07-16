<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación
header('Content-Type: application/json');

$cliente_id = (int)($_GET['cliente_id'] ?? 0);
$envio_id   = (int)($_GET['envio_id']   ?? 0); // Para editar: excluir el envío actual
if (!$cliente_id) { echo json_encode([]); exit; }

$pdo  = Database::get();

// Obtener certificados activos del cliente
// e indicar si están en tránsito (tienen envío sin fecha_recibido)
// Si estamos editando, excluimos el envío actual para que sus certs sigan seleccionables
$stmt = $pdo->prepare("
    SELECT c.id, n.codigo, n.nombre AS norma_nombre, c.numero_certificado AS numero,
           EXISTS (
               SELECT 1
               FROM envios_certificados ec
               JOIN envios e ON e.id = ec.envio_id
               WHERE ec.certificado_id = c.id
               AND e.fecha_recibido IS NULL
               " . ($envio_id ? "AND e.id != $envio_id" : "") . "
           ) AS en_transito
    FROM certificados c
    JOIN normas_iso n ON n.id = c.norma_iso_id
    WHERE c.cliente_id = ?
    AND c.estado NOT IN ('vencido','suspendido')
    ORDER BY n.codigo
");
$stmt->execute([$cliente_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
