<?php
/**
 * portal/ajax_actualizar_envio.php
 * Permite al cliente completar los datos básicos de un envío en estado inicial.
 * Solo actualiza: agencia_envio_id, sede_registro, destinatario, destinatario_dni.
 * NO modifica estado_envio_id.
 */
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/NotificationService.php';

header('Content-Type: application/json; charset=utf-8');

$token    = trim($_POST['token']    ?? '');
$envio_id = (int)($_POST['envio_id'] ?? 0);

if (!$token || !$envio_id) {
    echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos.']); exit;
}

$pdo = Database::get();

// Verificar que el token corresponde al cliente dueño del envío
// y que el envío está en estado inicial (Por preparar = id 7)
$stmt = $pdo->prepare("
    SELECT e.id, e.estado_envio_id, e.cliente_id,
           e.agencia_envio_id AS ag_prev, e.destinatario AS dest_prev,
           cl.razon_social
    FROM envios e
    JOIN clientes cl ON cl.id = e.cliente_id
    WHERE e.id = ?
      AND cl.portal_token = ?
      AND cl.portal_activo = 1
      AND cl.deleted_at IS NULL
      AND e.deleted_at IS NULL
    LIMIT 1
");
$stmt->execute([$envio_id, $token]);
$envio = $stmt->fetch();

if (!$envio) {
    echo json_encode(['ok' => false, 'msg' => 'No autorizado.']); exit;
}

// Solo permitir edición en estado inicial ("Por preparar")
$estado_inicial = $pdo->query(
    "SELECT id FROM estados_envio WHERE activo=1 AND nombre='Por preparar' LIMIT 1"
)->fetchColumn();
if ($estado_inicial && (int)$envio['estado_envio_id'] !== (int)$estado_inicial) {
    echo json_encode(['ok' => false, 'msg' => 'Este envío ya no puede modificarse desde el portal.']); exit;
}

// Validar campos
$agencia_id     = (int)($_POST['agencia_envio_id']  ?? 0);
$sede           = trim($_POST['sede_registro']       ?? '');
$tienda         = trim($_POST['tienda_destino']      ?? '');
$direccion      = trim($_POST['direccion_destino']   ?? '');
$destinatario   = trim($_POST['destinatario']        ?? '');
$destinatario_dni = preg_replace('/\D/', '', $_POST['destinatario_dni'] ?? '');
$referencia     = trim($_POST['referencia_direccion'] ?? '');
$lat            = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float) $_POST['lat'] : null;
$lng            = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float) $_POST['lng'] : null;

if (!$agencia_id) {
    echo json_encode(['ok' => false, 'msg' => 'Selecciona una agencia de envío.']); exit;
}

// Verificar que la agencia existe y está activa; obtener nombre, modo y si es domicilio
$ag_ok = $pdo->prepare("SELECT nombre, sede_modo, es_domicilio FROM agencias_envio WHERE id = ? AND activo = 1");
$ag_ok->execute([$agencia_id]);
$ag_row = $ag_ok->fetch();
if (!$ag_row) {
    echo json_encode(['ok' => false, 'msg' => 'Agencia no válida.']); exit;
}
$es_olva      = stripos($ag_row['nombre'] ?? '', 'olva') !== false;
$es_domicilio = (int) ($ag_row['es_domicilio'] ?? 0) === 1;

if ($es_domicilio) {
    // Domicilio (Lima): hace falta dirección + punto exacto en el mapa.
    if ($direccion === '') { echo json_encode(['ok' => false, 'msg' => 'Escribe la dirección de entrega.']); exit; }
    if ($lat === null || $lng === null) {
        echo json_encode(['ok' => false, 'msg' => 'Marca el punto exacto de entrega en el mapa.']); exit;
    }
    $sede = ''; $tienda = '';   // no aplican en domicilio
} else {
    $lat = null; $lng = null; $referencia = '';   // solo domicilio usa el mapa
    // En integraciones (API) el destino es solo por sede → la dirección libre no aplica.
    if (($ag_row['sede_modo'] ?? 'manual') === 'api') {
        $direccion = '';
    }
    // Olva: el punto de recojo (tienda) es obligatorio.
    if ($es_olva && $tienda === '') {
        echo json_encode(['ok' => false, 'msg' => 'Selecciona el punto de recojo de Olva.']); exit;
    }
    if (!$es_olva) { $tienda = ''; } // solo Olva usa tienda
}

if ($destinatario_dni && strlen($destinatario_dni) !== 8) {
    echo json_encode(['ok' => false, 'msg' => 'El DNI debe tener 8 dígitos.']); exit;
}

// Actualizar — sin tocar estado_envio_id
$upd = $pdo->prepare("
    UPDATE envios
    SET agencia_envio_id  = ?,
        sede_registro     = ?,
        tienda_destino    = ?,
        direccion_destino = ?,
        destinatario      = ?,
        destinatario_dni  = ?,
        lat               = ?,
        lng               = ?,
        referencia_direccion = ?
    WHERE id = ?
");
$upd->execute([
    $agencia_id,
    $sede      ?: null,
    $tienda    ?: null,
    $direccion ?: null,
    $destinatario      ?: null,
    $destinatario_dni  ?: null,
    $lat,
    $lng,
    $referencia ?: null,
    $envio_id,
]);

// Notificar (push + interna) SOLO en la transición "sin datos" → "con datos completos".
$antes_incompleto = empty($envio['ag_prev']) || trim((string)$envio['dest_prev']) === '';
$ahora_completo   = $agencia_id > 0 && $destinatario !== '';
if ($antes_incompleto && $ahora_completo) {
    try {
        NotificationService::dispatch('envio_datos_registrados', [
            'id'         => (int)$envio_id,
            'cliente_id' => (int)$envio['cliente_id'],
            'origen'     => 'portal',
            'title'      => 'Datos de envío registrados',
            'message'    => "{$envio['razon_social']} completó los datos de envío desde el portal.",
            'push_body'  => "{$envio['razon_social']} completó sus datos de envío (portal).",
        ]);
    } catch (\Throwable $e) {
        error_log('[ajax_actualizar_envio] notif error: ' . $e->getMessage());
    }
}

// Devolver la agencia nombre para refrescar la UI
$ag_nombre = $pdo->prepare("SELECT nombre FROM agencias_envio WHERE id = ?");
$ag_nombre->execute([$agencia_id]);

echo json_encode([
    'ok'           => true,
    'msg'          => 'Datos de envío actualizados.',
    'agencia'      => $ag_nombre->fetchColumn(),
    'sede'         => $sede,
    'destinatario' => $destinatario,
]);
