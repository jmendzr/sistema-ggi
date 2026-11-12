<?php
/**
 * modules/planilla/ajax_planilla.php — Acciones de planilla.
 * guardar_config | generar | guardar | pagar | revertir.
 * Acceso: gerencia general + Administración y Finanzas.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!has_role('gerente_general')) {
    echo json_encode(['ok' => false, 'msg' => 'Sin permiso para gestionar planilla.']); exit;
}
if (!defined('MODULO_PLANILLA_ACTIVO') || !MODULO_PLANILLA_ACTIVO) {
    echo json_encode(['ok' => false, 'msg' => 'El módulo de planilla está desactivado.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
csrf_verify();

$pdo    = Database::get();
$user   = auth_user();
$accion = $_POST['accion'] ?? '';

function salir(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** ¿El trabajador comisiona? (config) */
function comisiona_flag(PDO $pdo, int $usuarioId): bool {
    $c = $pdo->prepare("SELECT comisiona FROM planilla_config WHERE usuario_id = ?");
    $c->execute([$usuarioId]);
    return (bool)(int)$c->fetchColumn();
}

/**
 * Comisión PENDIENTE del trabajador en el período (bonos aún no pagados, por
 * created_at). 0 si no comisiona. Es lo que la planilla pagará y liquidará.
 */
function comision_de(PDO $pdo, int $usuarioId, string $periodo): float {
    if (!comisiona_flag($pdo, $usuarioId)) return 0.0;
    $q = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM bonos_ejecutivos
                         WHERE ejecutivo_id = ? AND DATE_FORMAT(created_at,'%Y-%m') = ? AND estado = 'pendiente'");
    $q->execute([$usuarioId, $periodo]);
    return round((float)$q->fetchColumn(), 2);
}

// ── Guardar configuración por trabajador ─────────────────────────────────────
if ($accion === 'guardar_config') {
    $items = json_decode($_POST['items'] ?? '[]', true) ?: [];
    $up = $pdo->prepare(
        "INSERT INTO planilla_config (usuario_id, regimen, monto, comisiona, incluir, banco, num_cuenta, cci)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE regimen=VALUES(regimen), monto=VALUES(monto),
                                 comisiona=VALUES(comisiona), incluir=VALUES(incluir),
                                 banco=VALUES(banco), num_cuenta=VALUES(num_cuenta), cci=VALUES(cci)"
    );
    $n = 0;
    foreach ($items as $it) {
        $uid = (int)($it['usuario_id'] ?? 0);
        if ($uid <= 0) continue;
        $reg = ($it['regimen'] ?? 'planilla') === 'rxh' ? 'rxh' : 'planilla';
        $up->execute([$uid, $reg, max(0, (float)($it['monto'] ?? 0)),
                      !empty($it['comisiona']) ? 1 : 0, !empty($it['incluir']) ? 1 : 0,
                      mb_substr(trim($it['banco'] ?? ''), 0, 60) ?: null,
                      mb_substr(trim($it['num_cuenta'] ?? ''), 0, 40) ?: null,
                      mb_substr(trim($it['cci'] ?? ''), 0, 40) ?: null]);
        $n++;
    }
    log_actividad('planilla', 'config', "Actualizó configuración de $n trabajador/es");
    salir(['ok' => true, 'guardados' => $n]);
}

// ── Generar planilla del período desde la configuración ──────────────────────
if ($accion === 'generar') {
    $periodo = $_POST['periodo'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) salir(['ok' => false, 'msg' => 'Período inválido']);

    $trab = $pdo->query(
        "SELECT c.usuario_id, c.regimen, c.monto, c.comisiona
           FROM planilla_config c JOIN usuarios u ON u.id = c.usuario_id
          WHERE c.incluir = 1 AND u.activo = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    $ins = $pdo->prepare(
        "INSERT IGNORE INTO planilla (usuario_id, periodo, regimen, sueldo_base, comision, bonos, descuentos, neto, estado, created_by)
         VALUES (?, ?, ?, ?, ?, 0, 0, ?, 'pendiente', ?)"
    );
    $n = 0;
    foreach ($trab as $t) {
        $com = (int)$t['comisiona'] ? comision_de($pdo, (int)$t['usuario_id'], $periodo) : 0.0;
        $neto = round((float)$t['monto'] + $com, 2);
        $ins->execute([$t['usuario_id'], $periodo, $t['regimen'], $t['monto'], $com, $neto, $user['id']]);
        $n += $ins->rowCount();
    }
    log_actividad('planilla', 'generar', "Generó planilla $periodo ($n trabajador/es)");
    salir(['ok' => true, 'generados' => $n]);
}

// ── Operaciones sobre una fila ───────────────────────────────────────────────
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) salir(['ok' => false, 'msg' => 'Falta el id']);
$row = $pdo->prepare("SELECT * FROM planilla WHERE id = ?");
$row->execute([$id]);
$pl = $row->fetch(PDO::FETCH_ASSOC);
if (!$pl) salir(['ok' => false, 'msg' => 'Registro no encontrado']);

if ($accion === 'guardar') {
    $base       = max(0, (float)($_POST['sueldo_base'] ?? $pl['sueldo_base']));
    $bonos      = max(0, (float)($_POST['bonos'] ?? 0));
    $descuentos = max(0, (float)($_POST['descuentos'] ?? 0));
    $metodo     = mb_substr(trim($_POST['metodo_pago'] ?? ''), 0, 40);
    $notas      = mb_substr(trim($_POST['notas'] ?? ''), 0, 300);
    $comision   = comision_de($pdo, (int)$pl['usuario_id'], $pl['periodo']);
    $neto       = round($base + $comision + $bonos - $descuentos, 2);

    $comp_path = $pl['comprobante_path'];
    if (!empty($_FILES['comprobante']['tmp_name'])) {
        $tmp  = $_FILES['comprobante']['tmp_name'];
        $mime = mime_content_type($tmp) ?: '';
        $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'][$mime] ?? '';
        if (!$ext) salir(['ok' => false, 'msg' => 'El comprobante debe ser JPG, PNG o PDF']);
        if (filesize($tmp) > 5 * 1024 * 1024) salir(['ok' => false, 'msg' => 'El comprobante supera 5 MB']);
        $dir = __DIR__ . '/../../storage/planilla/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $dest = $dir . 'planilla_' . $id . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($tmp, $dest)) salir(['ok' => false, 'msg' => 'No se pudo guardar el comprobante']);
        if ($comp_path && is_file($comp_path)) @unlink($comp_path);
        $comp_path = $dest;
    }

    $pdo->prepare("UPDATE planilla SET sueldo_base=?, comision=?, bonos=?, descuentos=?, neto=?, metodo_pago=?, notas=?, comprobante_path=? WHERE id=?")
        ->execute([$base, $comision, $bonos, $descuentos, $neto, $metodo ?: null, $notas ?: null, $comp_path, $id]);
    log_actividad('planilla', 'editar', "Editó planilla #$id (neto S/ " . number_format($neto, 2) . ")");
    salir(['ok' => true]);
}

if ($accion === 'pagar') {
    $comisiona = comisiona_flag($pdo, (int)$pl['usuario_id']);
    $comision  = comision_de($pdo, (int)$pl['usuario_id'], $pl['periodo']);
    $neto = round((float)$pl['sueldo_base'] + $comision + (float)$pl['bonos'] - (float)$pl['descuentos'], 2);
    $pdo->prepare("UPDATE planilla SET estado='pagado', comision=?, neto=?, fecha_pago=CURDATE(), pagado_por=? WHERE id=?")
        ->execute([$comision, $neto, $user['id'], $id]);
    // Liquidar los bonos pendientes del período de este trabajador: quedan
    // pagados y enlazados a esta planilla (planilla_id) para poder revertir.
    $nb = 0;
    if ($comisiona) {
        $ub = $pdo->prepare("UPDATE bonos_ejecutivos SET estado='pagado', fecha_pago=CURDATE(), pagado_at=NOW(), pagado_por=?, planilla_id=?
                              WHERE ejecutivo_id=? AND DATE_FORMAT(created_at,'%Y-%m')=? AND estado='pendiente'");
        $ub->execute([$user['id'], $id, (int)$pl['usuario_id'], $pl['periodo']]);
        $nb = $ub->rowCount();
    }
    log_actividad('planilla', 'pagar', "Pago #$id (S/ " . number_format($neto, 2) . ")" . ($nb ? " · liquidó $nb bono(s)" : ''));
    salir(['ok' => true, 'bonos_liquidados' => $nb]);
}

if ($accion === 'revertir') {
    // Revertir también los bonos que esta planilla había liquidado.
    $ub = $pdo->prepare("UPDATE bonos_ejecutivos SET estado='pendiente', fecha_pago=NULL, pagado_por=NULL, planilla_id=NULL WHERE planilla_id=?");
    $ub->execute([$id]);
    $pdo->prepare("UPDATE planilla SET estado='pendiente', fecha_pago=NULL, pagado_por=NULL WHERE id=?")->execute([$id]);
    log_actividad('planilla', 'revertir', "Revirtió el pago #$id" . ($ub->rowCount() ? " y " . $ub->rowCount() . " bono(s)" : ''));
    salir(['ok' => true]);
}

salir(['ok' => false, 'msg' => 'Acción desconocida']);
