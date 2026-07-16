<?php
/**
 * portal/ajax_sedes_portal.php
 * Endpoint de sedes para el portal de clientes (auth por token, sin sesión).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/ShalomSedesService.php';
require_once __DIR__ . '/../core/OlvaSedesService.php';

header('Content-Type: application/json; charset=utf-8');

$token      = trim($_GET['token']      ?? '');
$agencia_id = (int)($_GET['agencia_id'] ?? 0);
$envio_id   = (int)($_GET['envio_id']   ?? 0);  // para sede_sugerida

if (!$token || !$agencia_id) {
    echo json_encode(['sedes' => []]); exit;
}

$pdo = Database::get();

// Validar token y obtener cliente
$stmt = $pdo->prepare("SELECT id FROM clientes WHERE portal_token=? AND portal_activo=1 AND estado!='inactivo' AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$token]);
$cliente_id = (int)($stmt->fetchColumn() ?: 0);
if (!$cliente_id) {
    echo json_encode(['sedes' => []]); exit;
}

// Nombre + modo de destino de la agencia (switch en mantenimiento)
$ag = $pdo->prepare("SELECT nombre, sede_modo FROM agencias_envio WHERE id = ? AND activo = 1");
$ag->execute([$agencia_id]);
$ag_row    = $ag->fetch();
$nombre    = strtolower($ag_row['nombre'] ?? '');
$sede_modo = ($ag_row['sede_modo'] ?? 'manual') === 'api' ? 'api' : 'manual';

$sedes = [];

// Solo en modo integración (API) se ofrece el buscador de sedes.
if ($sede_modo === 'api') {
    if (str_contains($nombre, 'shalom')) {
        foreach (ShalomSedesService::listar() as $s) {
            $sedes[] = ['valor' => $s['label'], 'label' => $s['label'], 'dep' => $s['departamento']];
        }
    } elseif (str_contains($nombre, 'olva')) {
        foreach (OlvaSedesService::listar() as $s) {
            $sedes[] = ['valor' => $s['label'], 'label' => $s['label'], 'dep' => $s['departamento'], 'ubigeo' => $s['id'] ?? ''];
        }
    }
}

// Última sede usada por este cliente con esta agencia (en cualquier envío previo)
$sede_sugerida = null;
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

echo json_encode([
    'sedes'         => $sedes,
    'sede_modo'     => $sede_modo,
    'sede_sugerida' => $sede_sugerida,
], JSON_UNESCAPED_UNICODE);
