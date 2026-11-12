<?php
/**
 * modules/envios/exportar_courier.php
 * Exporta envíos al formato de carga masiva de Olva o Shalom, rellenando la
 * plantilla oficial de cada courier (conserva hojas ocultas de validación y
 * desplegables). Respeta los filtros del listado y el permiso por rol.
 *
 *   ?courier=olva|shalom  (+ filtros: q, estado[])  &descargar=1
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas','gerente_comercial', 'gestor_logistico', 'delivery', 'administracion_finanzas');

$courier = strtolower($_GET['courier'] ?? '');
if (!in_array($courier, ['olva', 'shalom'], true)) {
    http_response_code(400);
    exit('Courier inválido.');
}
$TEMPLATE_DIR = dirname(__DIR__, 2) . '/../_formatos_courier';
$template = $TEMPLATE_DIR . '/formato_' . $courier . '.xlsx';
if (!is_file($template)) {
    http_response_code(500);
    exit('No se encontró la plantilla del courier.');
}

$pdo = Database::get();

/* ── Filtros (mismos que el listado) + forzar courier por nombre de agencia ── */
$buscar      = trim($_GET['q'] ?? '');
$_est_raw    = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$estados_sel = array_values(array_filter(array_map('intval', $_est_raw)));

$where  = ['e.deleted_at IS NULL', 'ag.nombre LIKE ?'];
$params = ['%' . $courier . '%'];

if ($user['rol'] === 'ejecutivo_ventas') {
    $where[]  = '(e.ejecutivo_id = ? OR cl.ejecutivo_id = ? OR cl.responsible_user_id = ? OR cl.created_by_user_id = ?)';
    array_push($params, $user['id'], $user['id'], $user['id'], $user['id']);
}
if ($user['rol'] === 'gestor_logistico') {
    $where[]  = '(e.agente_id = ? OR (e.agente_id IS NULL AND e.estado_envio_id NOT IN (5,6)))';
    $params[] = $user['id'];
}
if ($buscar) {
    $where[]  = '(cl.razon_social LIKE ? OR e.codigo_envio LIKE ? OR e.destinatario LIKE ?)';
    array_push($params, "%$buscar%", "%$buscar%", "%$buscar%");
}
if ($estados_sel) {
    $ph = implode(',', array_fill(0, count($estados_sel), '?'));
    $where[]  = "e.estado_envio_id IN ($ph)";
    $params   = array_merge($params, $estados_sel);
}
$sql_where = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT e.id, e.sede_registro, e.tienda_destino, e.direccion_destino,
           e.destinatario, e.destinatario_dni,
           cl.razon_social, cl.ruc, cl.contacto_telefono, cl.contacto_nombre, cl.contacto_apellido,
           (SELECT COUNT(*) FROM envios_certificados ec WHERE ec.envio_id = e.id) AS n_certs
    FROM envios e
    JOIN clientes cl            ON cl.id = e.cliente_id
    LEFT JOIN agencias_envio ag ON ag.id = e.agencia_envio_id
    WHERE $sql_where
    ORDER BY e.id DESC
");
$stmt->execute($params);
$envios = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$envios) {
    http_response_code(404);
    exit('No hay envíos de ' . ucfirst($courier) . ' para exportar con los filtros actuales.');
}

/* ── Helpers de mapeo ──────────────────────────────────────────────────────── */
// Divide "NOMBRES AP_PATERNO AP_MATERNO" → [nombres, paterno, materno]
function ggi_split_nombre(string $full): array {
    $t = preg_split('/\s+/', trim($full)) ?: [];
    $t = array_values(array_filter($t, fn($x) => $x !== ''));
    $n = count($t);
    if ($n === 0) return ['', '', ''];
    if ($n === 1) return [$t[0], '', ''];
    if ($n === 2) return [$t[0], $t[1], ''];
    $materno = array_pop($t);
    $paterno = array_pop($t);
    return [implode(' ', $t), $paterno, $materno];
}
function ggi_peso(int $nCerts): int { return $nCerts > 2 ? 2 : 1; }

/* ── Construir filas por courier ───────────────────────────────────────────── */
$rows = [];
if ($courier === 'olva') {
    $i = 0;
    foreach ($envios as $e) {
        $i++;
        // sede_registro Olva = "DEPTO - PROVINCIA - DISTRITO"
        $loc = preg_split('/\s*-\s*/', trim((string)$e['sede_registro'])) ?: [];
        [$nom, $pat, $mat] = ggi_split_nombre((string)$e['destinatario']);
        $rows[] = [
            'A' => $i,
            'B' => 'Recojo en Tienda',
            'C' => $loc[0] ?? '',
            'D' => $loc[1] ?? '',
            'E' => $loc[2] ?? '',
            'F' => '',                                  // Dirección (recojo en tienda → vacío)
            'G' => (string)$e['tienda_destino'],
            'H' => '',
            'I' => 'Paquete',
            'J' => 'UTILES DE OFICINA',
            'K' => 'PORTACERTIFICADO',
            'L' => 100,
            'M' => ggi_peso((int)$e['n_certs']),
            'N' => 20, 'O' => 30, 'P' => 10,            // Largo / Ancho / Alto (cm)
            'Q' => 'No',
            'R' => '',
            'S' => 'DNI',
            'T' => (string)$e['destinatario_dni'],
            'U' => (string)$e['contacto_telefono'],
            'V' => '',                                  // Razón Social (según ejemplo, vacío)
            'W' => '',                                  // Contacto
            'X' => $nom, 'Y' => $pat, 'Z' => $mat,
        ];
    }
} else { // shalom
    foreach ($envios as $e) {
        // sede_registro Shalom = "DEPTO / PROV / DISTRITO / AGENCIA"
        $loc     = preg_split('/\s*\/\s*/', trim((string)$e['sede_registro'])) ?: [];
        $destino = $loc ? end($loc) : '';
        $rows[] = [
            'A' => (string)$e['destinatario_dni'],
            'B' => (string)$e['contacto_telefono'],
            'C' => '',
            'D' => '',
            'E' => '',
            'F' => 'AV. LAS PALMERAS',
            'G' => $destino,
            'H' => 'PAQUETE L',
            'I' => 0.10, 'J' => 0.30, 'K' => 0.20,      // Alto / Ancho / Largo (m)
            'L' => ggi_peso((int)$e['n_certs']),
            'M' => 1,
        ];
    }
}

/* ── Rellenar la plantilla xlsx (inyecta <sheetData>) ─────────────────────── */
$sheetFile = $courier === 'olva' ? 'sheet5.xml' : 'sheet1.xml';   // InputData / Hoja1
$firstDataRow = $courier === 'olva' ? 5 : 2;                       // fila donde arrancan datos
$keepHeaderUpto = $firstDataRow - 1;

require_once __DIR__ . '/../../core/XlsxTemplateFiller.php';
$outFile = XlsxTemplateFiller::fill($template, $sheetFile, $keepHeaderUpto, $firstDataRow, $rows);
if (!$outFile) {
    http_response_code(500);
    exit('No se pudo generar el archivo.');
}

$fname = 'Envios_' . ucfirst($courier) . '_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($outFile));
header('Cache-Control: no-store');
readfile($outFile);
@unlink($outFile);
exit;
