<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas');

$pdo = Database::get();
$ejec_sel = array_values(array_filter(array_map('intval', (array)($_GET['ejecutivo'] ?? [])), fn($v) => $v > 0));
$_est_raw_cot = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$_estados_exp_cot_raw = array_values(array_filter($_est_raw_cot, fn($v) => $v !== ''));
// Expandir pseudo-estado 'activa'
$estados_exp_cot = [];
foreach ($_estados_exp_cot_raw as $_sv) {
    if ($_sv === 'activa') { $estados_exp_cot[] = 'borrador'; $estados_exp_cot[] = 'enviada'; }
    else { $estados_exp_cot[] = $_sv; }
}
$estados_exp_cot = array_unique($estados_exp_cot);

$where = ['1=1']; $params = [];
if ($user['rol'] === 'ejecutivo_ventas') {
    $where[] = '(cot.ejecutivo_id=? OR cl.ejecutivo_id=? OR cl.responsible_user_id=? OR cl.created_by_user_id=?)';
    $params[] = $user['id']; $params[] = $user['id']; $params[] = $user['id']; $params[] = $user['id'];
}
if (!empty($estados_exp_cot)) { $ph=implode(',',array_fill(0,count($estados_exp_cot),'?')); $where[]="cot.estado IN ($ph)"; $params=array_merge($params,array_values($estados_exp_cot)); }
if ($ejec_sel) { $ph=implode(',',array_fill(0,count($ejec_sel),'?')); $where[]="cot.ejecutivo_id IN ($ph)"; $params=array_merge($params,$ejec_sel); }
$sql_where = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT cot.numero, COALESCE(cl.razon_social, cot.empresa_nombre) AS empresa,
           COALESCE(cl.ruc, cot.empresa_ruc) AS ruc,
           cot.estado, cot.monto_total, cot.fecha_emision, cot.fecha_vencimiento,
           cot.contacto_nombre, cot.contacto_telefono, cot.contacto_email,
           CONCAT(ej.nombre,' ',ej.apellido) AS ejecutivo,
           GROUP_CONCAT(n.codigo ORDER BY n.codigo SEPARATOR ', ') AS normas
    FROM cotizaciones cot
    LEFT JOIN clientes cl ON cl.id=cot.cliente_id
    LEFT JOIN usuarios ej ON ej.id=cot.ejecutivo_id
    LEFT JOIN cotizacion_normas cn ON cn.cotizacion_id=cot.id
    LEFT JOIN normas_iso n ON n.id=cn.norma_iso_id
    WHERE $sql_where
    GROUP BY cot.id
    ORDER BY cot.created_at DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'cotizaciones_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para Excel

fputcsv($out, ['Número','Empresa','RUC','Estado','Monto Total (S/)','Fecha Emisión','Fecha Vencimiento','Contacto','Teléfono','Email','Ejecutivo','Normas']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['numero'], $r['empresa'], $r['ruc'], $r['estado'],
        number_format($r['monto_total'],2), $r['fecha_emision'], $r['fecha_vencimiento'],
        $r['contacto_nombre'], $r['contacto_telefono'], $r['contacto_email'],
        $r['ejecutivo'], $r['normas'],
    ]);
}
fclose($out);
exit;
