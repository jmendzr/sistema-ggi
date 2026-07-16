<?php
/**
 * AJAX: búsqueda de clientes para autocompletar en cotizaciones/crear.php
 * GET ?q=texto  → busca por RUC o razón social
 * GET ?id=X     → devuelve datos completos de un cliente específico
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::get();

// Modo: obtener un cliente por ID
if (!empty($_GET['id'])) {
    $stmt = $pdo->prepare("
        SELECT id, ruc, razon_social, estado, ejecutivo_id,
               contacto_nombre, contacto_apellido, contacto_cargo, contacto_telefono, contacto_email,
               direccion, departamento, provincia, distrito, ubigeo, num_trabajadores
        FROM clientes WHERE id = ? LIMIT 1
    ");
    $stmt->execute([(int)$_GET['id']]);
    $cl = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cl) { echo json_encode(['ok' => false]); exit; }
    echo json_encode(['ok' => true, 'data' => [_formatCl($cl)]]);
    exit;
}

// Modo: búsqueda por texto
$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['ok' => true, 'data' => []]);
    exit;
}

$like = '%' . $q . '%';
$stmt = $pdo->prepare("
    SELECT id, ruc, razon_social, estado, ejecutivo_id,
           contacto_nombre, contacto_apellido, contacto_cargo, contacto_telefono, contacto_email,
           direccion, departamento, provincia, distrito, ubigeo, num_trabajadores
    FROM clientes
    WHERE (razon_social LIKE ? OR ruc LIKE ?)
    ORDER BY (estado = 'activo') DESC, razon_social ASC
    LIMIT 20
");
$stmt->execute([$like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'data' => array_map('_formatCl', $rows)]);

function _formatCl(array $cl): array {
    return [
        'id'              => (int)$cl['id'],
        'ruc'             => $cl['ruc'] ?? '',
        'name'            => $cl['razon_social'],
        'estado'          => $cl['estado'] ?? 'activo',
        'label'           => ($cl['ruc'] ? $cl['ruc'] . ' — ' : '') . $cl['razon_social'],
        'source'          => 'db',
        'ejec_id'         => $cl['ejecutivo_id'] ? (int)$cl['ejecutivo_id'] : null,
        'direccion'       => $cl['direccion']    ?? '',
        'departamento'    => $cl['departamento'] ?? '',
        'provincia'       => $cl['provincia']    ?? '',
        'distrito'        => $cl['distrito']     ?? '',
        'ubigeo'          => $cl['ubigeo']       ?? '',
        'num_trabajadores'=> isset($cl['num_trabajadores']) && $cl['num_trabajadores'] !== null ? (int)$cl['num_trabajadores'] : null,
        'contacto' => [
            'nombre'   => $cl['contacto_nombre']   ?? '',
            'apellido' => $cl['contacto_apellido'] ?? '',
            'cargo'    => $cl['contacto_cargo']    ?? '',
            'telefono' => $cl['contacto_telefono'] ?? '',
            'email'    => $cl['contacto_email']    ?? '',
        ],
    ];
}
