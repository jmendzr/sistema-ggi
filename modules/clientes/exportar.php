<?php
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
$user = auth_user();
require_role('gerente_general','gerente_operaciones','ejecutivo_ventas');

$pdo = Database::get();
// Filtros multiselect (alineado con el listado: ejecutivo[], revision[], estado[])
$ejec_sel  = array_values(array_filter(array_map('intval', (array)($_GET['ejecutivo'] ?? [])), fn($v) => $v > 0));
$revision_sel = array_values(array_filter((array)($_GET['revision'] ?? []), fn($v) => in_array($v, ['pendiente','revisado'], true)));
$_est_raw_c = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$estados_exp_c = array_values(array_filter($_est_raw_c, fn($v) => $v !== ''));

$where = ['1=1']; $params = [];
if ($user['rol'] === 'ejecutivo_ventas') {
    $where[] = '(c.ejecutivo_id=? OR c.responsible_user_id=? OR c.created_by_user_id=?)';
    $params[] = $user['id']; $params[] = $user['id']; $params[] = $user['id'];
}
if (!empty($estados_exp_c)) { $ph=implode(',',array_fill(0,count($estados_exp_c),'?')); $where[]="c.estado IN ($ph)"; $params=array_merge($params,$estados_exp_c); }
if (!empty($ejec_sel)) { $ph=implode(',',array_fill(0,count($ejec_sel),'?')); $where[]="c.ejecutivo_id IN ($ph)"; $params=array_merge($params,$ejec_sel); }
if (!empty($revision_sel) && $user['rol']==='gerente_general') { $ph=implode(',',array_fill(0,count($revision_sel),'?')); $where[]="c.revision_gerente IN ($ph)"; $params=array_merge($params,$revision_sel); }
$sql_where = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT c.ruc, c.razon_social, c.estado, c.direccion, c.departamento, c.provincia, c.distrito,
           c.contacto_nombre, c.contacto_apellido, c.contacto_cargo, c.contacto_telefono, c.contacto_email,
           c.fecha_contrato, CONCAT(ej.nombre,' ',ej.apellido) AS ejecutivo,
           GROUP_CONCAT(DISTINCT n.codigo ORDER BY n.codigo SEPARATOR ', ') AS normas_activas
    FROM clientes c
    LEFT JOIN usuarios ej ON ej.id=c.ejecutivo_id
    LEFT JOIN certificados ce ON ce.cliente_id=c.id AND ce.estado NOT IN ('vencido','suspendido')
    LEFT JOIN normas_iso n ON n.id=ce.norma_iso_id
    WHERE $sql_where
    GROUP BY c.id
    ORDER BY c.razon_social
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'clientes_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($out, ['RUC','Razón Social','Estado','Dirección','Departamento','Provincia','Distrito','Contacto','Apellido','Cargo','Teléfono','Email','Fecha Contrato','Ejecutivo','Normas Activas']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['ruc'], $r['razon_social'], $r['estado'], $r['direccion'],
        $r['departamento'], $r['provincia'], $r['distrito'],
        $r['contacto_nombre'], $r['contacto_apellido'], $r['contacto_cargo'],
        $r['contacto_telefono'], $r['contacto_email'], $r['fecha_contrato'],
        $r['ejecutivo'], $r['normas_activas'],
    ]);
}
fclose($out);
exit;
