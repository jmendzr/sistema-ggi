<?php
// modules/exportar.php — Exportador CSV/Excel universal
// Uso: exportar.php?modulo=clientes&q=...&estado=...
require_once __DIR__ . '/../core/helpers.php';
session_init();
require_login();

$modulo = $_GET['modulo'] ?? '';
$modulos_validos = ['clientes', 'certificados', 'envios', 'cotizaciones', 'auditorias'];
if (!in_array($modulo, $modulos_validos)) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$pdo    = Database::get();
$buscar = trim($_GET['q'] ?? '');
$fecha_desde = $_GET['desde'] ?? '';
$fecha_hasta = $_GET['hasta'] ?? '';

// Multi-select: acepta ?estado[]=v1&estado[]=v2 O legado ?estado=v
$_est_raw_exp = array_key_exists('estado', $_GET) ? (array)$_GET['estado'] : [];
$estados_exp  = array_values(array_filter($_est_raw_exp, fn($v) => $v !== ''));
$estado       = count($estados_exp) === 1 ? $estados_exp[0] : ''; // compat legado

$filas   = [];
$headers = [];

// ============================================================
switch ($modulo) {

// ── CLIENTES ──────────────────────────────────────────────
case 'clientes':
    require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial');
    $headers = ['RUC','Razón Social','Departamento','Provincia','Distrito',
                'Contacto','Teléfono','Email Contacto','Cargo','Estado',
                'Fecha Contrato','Certs. Vigentes','Registrado por','Fecha Registro'];
    $where = ['c.deleted_at IS NULL'];
    $params = [];
    // Ejecutivo_ventas exporta solo sus clientes (ejecutivo, responsable o captador)
    if ($user['rol'] === 'ejecutivo_ventas') {
        $where[] = '(c.ejecutivo_id = ? OR c.responsible_user_id = ? OR c.created_by_user_id = ?)';
        $params[] = $user['id']; $params[] = $user['id']; $params[] = $user['id'];
    }
    if ($buscar) { $where[] = '(c.razon_social LIKE ? OR c.ruc LIKE ?)'; $params = array_merge($params, ["%$buscar%","%$buscar%"]); }
    if (!empty($estados_exp)) { $ph=implode(',',array_fill(0,count($estados_exp),'?')); $where[]="c.estado IN ($ph)"; $params=array_merge($params,$estados_exp); }
    $sql_where = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT c.ruc, c.razon_social, c.departamento, c.provincia, c.distrito,
               CONCAT(COALESCE(c.contacto_nombre,''),' ',COALESCE(c.contacto_apellido,'')) AS contacto,
               c.contacto_telefono, c.contacto_email, c.contacto_cargo, c.estado,
               c.fecha_contrato, u.nombre AS creado_por, c.created_at,
               (SELECT COUNT(*) FROM certificados WHERE cliente_id=c.id AND estado IN ('vigente','por_vencer')) AS total_certs
        FROM clientes c JOIN usuarios u ON u.id=c.creado_por
        WHERE $sql_where ORDER BY c.razon_social ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $filas[] = [
            $r['ruc'], $r['razon_social'],
            $r['departamento'], $r['provincia'], $r['distrito'],
            trim($r['contacto']), $r['contacto_telefono'], $r['contacto_email'],
            $r['contacto_cargo'], ucfirst($r['estado']),
            $r['fecha_contrato'] ? date('d/m/Y', strtotime($r['fecha_contrato'])) : '',
            $r['total_certs'], $r['creado_por'],
            date('d/m/Y', strtotime($r['created_at'])),
        ];
    }
    break;

// ── CERTIFICADOS ──────────────────────────────────────────
case 'certificados':
    require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial');
    $headers = ['Cliente','RUC','Norma','Casa Cert.','N° Certificado','Estado',
                'Fecha Emisión','Fecha Vencimiento','Días al Vencimiento',
                'Fecha Aud. 1','Fecha Aud. 2','Alcance','Registrado por'];
    $where = ['c.deleted_at IS NULL', 'cl.deleted_at IS NULL'];
    $params = [];
    // Ejecutivo_ventas exporta solo certificados de sus clientes
    if ($user['rol'] === 'ejecutivo_ventas') {
        $where[] = '(cl.ejecutivo_id = ? OR cl.responsible_user_id = ? OR cl.created_by_user_id = ?)';
        $params[] = $user['id']; $params[] = $user['id']; $params[] = $user['id'];
    }
    if ($buscar) { $where[] = '(cl.razon_social LIKE ? OR cl.ruc LIKE ? OR n.codigo LIKE ? OR c.numero_certificado LIKE ?)'; $params = array_merge($params, ["%$buscar%","%$buscar%","%$buscar%","%$buscar%"]); }
    if (!empty($estados_exp)) { $ph=implode(',',array_fill(0,count($estados_exp),'?')); $where[]="c.estado IN ($ph)"; $params=array_merge($params,$estados_exp); }
    $sql_where = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT cl.razon_social, cl.ruc, n.codigo AS norma, cc.nombre AS casa,
               c.numero_certificado, c.estado,
               c.fecha_emision, c.fecha_vencimiento,
               DATEDIFF(c.fecha_vencimiento, CURDATE()) AS dias_venc,
               c.fecha_auditoria_1, c.fecha_auditoria_2, c.alcance,
               u.nombre AS creado_por
        FROM certificados c
        JOIN clientes cl ON cl.id=c.cliente_id
        JOIN normas_iso n ON n.id=c.norma_iso_id
        JOIN casas_certificadoras cc ON cc.id=c.casa_certificadora_id
        JOIN usuarios u ON u.id=c.creado_por
        WHERE $sql_where ORDER BY c.fecha_vencimiento ASC
    ");
    $stmt->execute($params);
    $label_map = ['vigente'=>'Vigente','por_vencer'=>'Por vencer','vencido'=>'Vencido','en_proceso'=>'En proceso','suspendido'=>'Suspendido'];
    foreach ($stmt->fetchAll() as $r) {
        $filas[] = [
            $r['razon_social'], $r['ruc'], $r['norma'], $r['casa'],
            $r['numero_certificado'], $label_map[$r['estado']] ?? $r['estado'],
            date('d/m/Y', strtotime($r['fecha_emision'])),
            date('d/m/Y', strtotime($r['fecha_vencimiento'])),
            $r['dias_venc'],
            $r['fecha_auditoria_1'] ? date('d/m/Y', strtotime($r['fecha_auditoria_1'])) : '',
            $r['fecha_auditoria_2'] ? date('d/m/Y', strtotime($r['fecha_auditoria_2'])) : '',
            $r['alcance'], $r['creado_por'],
        ];
    }
    break;

// ── ENVÍOS ────────────────────────────────────────────────
case 'envios':
    require_role('gerente_general','gerente_operaciones','delivery');
    // Mismos campos que el "Detalle del envío" (modules/envios/ver.php).
    $headers = ['Empresa','RUC','Agencia','Estado','Certificado(s)',
                'Código de seguimiento','N° de orden','Código de recojo',
                'Sede de destino','Dirección de destino','Destinatario','DNI Destinatario',
                'Observaciones','Registrado por','Gestor logístico',
                'Fecha de envío','Fecha de recepción','Días en tránsito'];
    $where = ['e.deleted_at IS NULL', 'cl.deleted_at IS NULL'];
    $params = [];
    if ($buscar) { $where[] = '(cl.razon_social LIKE ? OR cl.ruc LIKE ? OR e.codigo_envio LIKE ?)'; $params = array_merge($params, ["%$buscar%","%$buscar%","%$buscar%"]); }
    if (!empty($estados_exp)) { $ids_exp=array_filter(array_map('intval',$estados_exp)); if($ids_exp){$ph=implode(',',array_fill(0,count($ids_exp),'?'));$where[]="e.estado_envio_id IN ($ph)";$params=array_merge($params,$ids_exp);} }
    $sql_where = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT cl.razon_social, cl.ruc,
               COALESCE(ag.nombre,'Por asignar') AS agencia, es.nombre AS estado,
               cc.contenido,
               e.codigo_envio, e.numero_orden, e.codigo_recojo,
               e.sede_registro, e.direccion_destino,
               e.destinatario, e.destinatario_dni, e.observaciones,
               e.fecha_envio, e.fecha_recibido,
               CASE WHEN e.fecha_recibido IS NOT NULL
                    THEN DATEDIFF(e.fecha_recibido, e.fecha_envio)
                    ELSE DATEDIFF(CURDATE(), e.fecha_envio) END AS dias_transito,
               u.nombre AS registrado_por,
               TRIM(CONCAT(COALESCE(agt.nombre,''),' ',COALESCE(agt.apellido,''))) AS gestor
        FROM envios e
        JOIN clientes cl ON cl.id=e.cliente_id
        LEFT JOIN agencias_envio ag ON ag.id=e.agencia_envio_id
        JOIN estados_envio es ON es.id=e.estado_envio_id
        LEFT JOIN usuarios u   ON u.id=e.registrado_por
        LEFT JOIN usuarios agt ON agt.id=e.agente_id
        LEFT JOIN (
            SELECT ec.envio_id,
                   GROUP_CONCAT(CONCAT(n.codigo,' ',c.numero_certificado) SEPARATOR ' | ') AS contenido
            FROM envios_certificados ec
            JOIN certificados c ON c.id = ec.certificado_id
            JOIN normas_iso n   ON n.id = c.norma_iso_id
            GROUP BY ec.envio_id
        ) cc ON cc.envio_id = e.id
        WHERE $sql_where ORDER BY e.fecha_envio DESC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $filas[] = [
            $r['razon_social'], $r['ruc'], $r['agencia'], $r['estado'], $r['contenido'],
            $r['codigo_envio'], $r['numero_orden'], $r['codigo_recojo'],
            $r['sede_registro'], $r['direccion_destino'], $r['destinatario'], $r['destinatario_dni'],
            $r['observaciones'], $r['registrado_por'],
            ($r['gestor'] !== '' ? $r['gestor'] : 'Sin gestor asignado'),
            $r['fecha_envio']    ? date('d/m/Y', strtotime($r['fecha_envio']))    : '',
            $r['fecha_recibido'] ? date('d/m/Y', strtotime($r['fecha_recibido'])) : 'Pendiente',
            $r['dias_transito'],
        ];
    }
    break;

// ── COTIZACIONES ──────────────────────────────────────────
case 'cotizaciones':
    require_role('gerente_general','gerente_operaciones','ejecutivo_ventas','gerente_comercial');
    $headers = ['Número','Empresa','RUC Empresa','Contacto','Teléfono','Email',
                'Estado','Monto Total','Moneda','Normas',
                'Fecha Emisión','Fecha Vencimiento','Creado por'];
    $where = ['cot.deleted_at IS NULL'];
    $params = [];
    // Ejecutivo_ventas exporta sus cotizaciones propias o las de sus clientes
    if ($user['rol'] === 'ejecutivo_ventas') {
        $where[] = '(cot.ejecutivo_id = ? OR cl.ejecutivo_id = ? OR cl.responsible_user_id = ? OR cl.created_by_user_id = ?)';
        $params[] = $user['id']; $params[] = $user['id']; $params[] = $user['id']; $params[] = $user['id'];
    }
    if ($buscar) { $where[] = '(cot.empresa_nombre LIKE ? OR cot.empresa_ruc LIKE ? OR cot.numero LIKE ?)'; $params = array_merge($params, ["%$buscar%","%$buscar%","%$buscar%"]); }
    if (!empty($estados_exp)) {
        $_vals_exp=[];
        foreach($estados_exp as $_sv){
            if($_sv==='activa'){$_vals_exp[]='borrador';$_vals_exp[]='enviada';}else{$_vals_exp[]=$_sv;}
        }
        $_vals_exp=array_unique($_vals_exp);
        $ph=implode(',',array_fill(0,count($_vals_exp),'?'));
        $where[]="cot.estado IN ($ph)";
        $params=array_merge($params,$_vals_exp);
    }
    $sql_where = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT cot.numero, cot.empresa_nombre, cot.empresa_ruc,
               cot.contacto_nombre, cot.contacto_telefono, cot.contacto_email,
               cot.estado, cot.monto_total, cot.moneda,
               cot.fecha_emision, cot.fecha_vencimiento, u.nombre AS creado_por, cot.id
        FROM cotizaciones cot
        JOIN usuarios u ON u.id=cot.creado_por
        LEFT JOIN clientes cl ON cl.id = cot.cliente_id
        WHERE $sql_where ORDER BY cot.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Normas por cotización
    $ids = array_column($rows, 'id');
    $normas_map = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $ns = $pdo->prepare("SELECT cn.cotizacion_id, n.codigo FROM cotizacion_normas cn JOIN normas_iso n ON n.id=cn.norma_iso_id WHERE cn.cotizacion_id IN ($ph)");
        $ns->execute($ids);
        foreach ($ns->fetchAll() as $n) { $normas_map[$n['cotizacion_id']][] = $n['codigo']; }
    }
    $estado_label = ['borrador'=>'Borrador','enviada'=>'Enviada','aceptada'=>'Aceptada','rechazada'=>'Rechazada','vencida'=>'Vencida','desistida'=>'Desistida'];
    foreach ($rows as $r) {
        $filas[] = [
            $r['numero'], $r['empresa_nombre'], $r['empresa_ruc'],
            $r['contacto_nombre'], $r['contacto_telefono'], $r['contacto_email'],
            $estado_label[$r['estado']] ?? $r['estado'],
            number_format($r['monto_total'], 2), $r['moneda'],
            implode(' / ', $normas_map[$r['id']] ?? []),
            date('d/m/Y', strtotime($r['fecha_emision'])),
            $r['fecha_vencimiento'] ? date('d/m/Y', strtotime($r['fecha_vencimiento'])) : '',
            $r['creado_por'],
        ];
    }
    break;

// ── AUDITORÍAS ────────────────────────────────────────────
case 'auditorias':
    require_role('gerente_general','gerente_operaciones');
    $headers = ['Cliente','RUC','Norma','Casa Certificadora','Tipo','Resultado',
                'Auditor Externo','Fecha Programada','Fecha Realizada','Fecha Reprogramada','Observaciones'];
    $where = ['a.deleted_at IS NULL', 'c.deleted_at IS NULL', 'cl.deleted_at IS NULL'];
    $params = [];
    if ($buscar) { $where[] = '(cl.razon_social LIKE ? OR cl.ruc LIKE ?)'; $params = array_merge($params, ["%$buscar%","%$buscar%"]); }
    if (!empty($estados_exp)) { $ph=implode(',',array_fill(0,count($estados_exp),'?')); $where[]="a.resultado IN ($ph)"; $params=array_merge($params,$estados_exp); }
    $sql_where = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT cl.razon_social, cl.ruc, n.codigo AS norma,
               cc.nombre AS casa, a.tipo, a.resultado,
               a.auditor_externo, a.fecha_programada, a.fecha_realizada,
               a.fecha_reprogramada, a.observaciones
        FROM auditorias a
        JOIN certificados c ON c.id=a.certificado_id
        JOIN clientes cl ON cl.id=c.cliente_id
        JOIN normas_iso n ON n.id=c.norma_iso_id
        LEFT JOIN casas_certificadoras cc ON cc.id=a.casa_certificadora_id
        WHERE $sql_where ORDER BY a.fecha_programada DESC
    ");
    $stmt->execute($params);
    $tipo_label = ['seguimiento_1'=>'Seguimiento 1','seguimiento_2'=>'Seguimiento 2','renovacion'=>'Renovación','extraordinaria'=>'Extraordinaria'];
    $res_label  = ['pendiente'=>'Pendiente','aprobado'=>'Aprobado','observado'=>'Observado','no_aprobado'=>'No aprobado'];
    foreach ($stmt->fetchAll() as $r) {
        $filas[] = [
            $r['razon_social'], $r['ruc'], $r['norma'], $r['casa'] ?? '—',
            $tipo_label[$r['tipo']] ?? $r['tipo'],
            $res_label[$r['resultado']] ?? $r['resultado'],
            $r['auditor_externo'] ?? '',
            $r['fecha_programada']   ? date('d/m/Y', strtotime($r['fecha_programada']))   : '',
            $r['fecha_realizada']    ? date('d/m/Y', strtotime($r['fecha_realizada']))    : '',
            $r['fecha_reprogramada'] ? date('d/m/Y', strtotime($r['fecha_reprogramada'])) : '',
            $r['observaciones'] ?? '',
        ];
    }
    break;
}

// ── Generar CSV ───────────────────────────────────────────
$nombre_archivo = 'GGI_' . strtoupper($modulo) . '_' . date('Ymd_His') . '.csv';

// BOM UTF-8 para que Excel lo abra correctamente con tildes
$bom = "\xEF\xBB\xBF";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, $bom);

// Encabezados
fputcsv($out, $headers, ';');

// Datos
foreach ($filas as $fila) {
    fputcsv($out, $fila, ';');
}

fclose($out);
log_actividad($modulo, 'exportar', 'Exportación CSV: ' . count($filas) . ' registros');
exit;
