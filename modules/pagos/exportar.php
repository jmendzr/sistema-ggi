<?php
// modules/pagos/exportar.php — Exportar pagos a CSV
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas');

$pdo          = Database::get();
$es_ejecutivo = $user['rol'] === 'ejecutivo_ventas';

// ── Mismos filtros que el listado — multi-select ──
$_ev = ['pendiente_voucher', 'pendiente_validacion', 'validado', 'rechazado'];
$_est_raw_p = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$estados_sel = array_values(array_filter($_est_raw_p, fn($v) => in_array($v, $_ev)));
$estado_fil  = count($estados_sel) === 1 ? $estados_sel[0] : '';

$search  = trim($_GET['q'] ?? '');
$mes_sel = array_values(array_filter((array)($_GET['mes'] ?? []), fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v)));

$where  = ['1=1'];
$params = [];

if ($es_ejecutivo) {
    $where[] = '(cot.ejecutivo_id = ? OR c.ejecutivo_id = ? OR c.responsible_user_id = ? OR c.created_by_user_id = ?)';
    $params[] = $user['id']; $params[] = $user['id'];
    $params[] = $user['id']; $params[] = $user['id'];
}
if (!empty($estados_sel)) {
    $ph = implode(',', array_fill(0, count($estados_sel), '?'));
    $where[]  = "p.estado IN ($ph)";
    $params   = array_merge($params, $estados_sel);
}
if ($search) {
    $where[] = '(c.razon_social LIKE ? OR c.ruc LIKE ? OR cot.numero LIKE ?)';
    $s = '%' . $search . '%';
    $params = array_merge($params, [$s, $s, $s]);
}
if ($mes_sel) {
    $ph = implode(',', array_fill(0, count($mes_sel), '?'));
    $where[] = "DATE_FORMAT(COALESCE(p.fecha_pago_decl, DATE(p.created_at)), '%Y-%m') IN ($ph)";
    $params = array_merge($params, $mes_sel);
}

$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        cot.numero                                     AS propuesta,
        c.razon_social                                 AS empresa,
        c.ruc                                          AS ruc,
        p.tipo                                         AS tipo_pago,
        p.monto                                        AS monto,
        p.moneda                                       AS moneda,
        p.estado                                       AS estado,
        COALESCE(p.fecha_pago_decl, DATE(p.created_at)) AS fecha_pago,
        p.fecha_validacion                             AS fecha_validacion,
        CONCAT(u_ej.nombre, ' ', u_ej.apellido)        AS ejecutivo,
        CONCAT(u_val.nombre, ' ', u_val.apellido)      AS validado_por,
        p.observacion_validacion                       AS observacion
    FROM pagos_cliente p
    JOIN clientes c       ON c.id   = p.cliente_id
    JOIN cotizaciones cot ON cot.id = p.cotizacion_id
    LEFT JOIN usuarios u_ej  ON u_ej.id  = COALESCE(cot.ejecutivo_id, c.ejecutivo_id)
    LEFT JOIN usuarios u_val ON u_val.id = p.validado_por
    WHERE $where_sql
    ORDER BY p.cotizacion_id DESC, p.tipo ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$labels_estado = [
    'pendiente_voucher'    => 'Sin voucher',
    'pendiente_validacion' => 'Por validar',
    'validado'             => 'Validado',
    'rechazado'            => 'Rechazado',
];
$labels_tipo = [
    'inicial'   => 'Inicial',
    'final'     => 'Final',
    'unico'     => 'Único',
    'adelanto'  => 'Adelanto',
];

$filename = 'pagos_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para Excel

fputcsv($out, [
    'Propuesta', 'Empresa', 'RUC', 'Tipo de Pago',
    'Monto', 'Moneda', 'Estado',
    'Fecha Pago', 'Fecha Validación',
    'Ejecutivo', 'Validado Por', 'Observación',
]);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['propuesta'],
        $r['empresa'],
        $r['ruc'],
        $labels_tipo[$r['tipo_pago']] ?? $r['tipo_pago'],
        number_format((float)$r['monto'], 2),
        $r['moneda'],
        $labels_estado[$r['estado']] ?? $r['estado'],
        $r['fecha_pago'] ?? '',
        $r['fecha_validacion'] ?? '',
        $r['ejecutivo'] ?? '',
        $r['validado_por'] ?? '',
        $r['observacion'] ?? '',
    ]);
}

fclose($out);
exit;
