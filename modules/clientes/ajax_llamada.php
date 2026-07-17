<?php
/**
 * modules/clientes/ajax_llamada.php — Agenda de llamadas a clientes
 *
 * Acciones (POST):
 *  - crear:       cliente_id, fecha_hora, [cotizacion_id], [aviso_min], [nota], [usuario_id]
 *  - realizar:    llamada_id, [nota_cierre]
 *  - cancelar:    llamada_id
 *  - reprogramar: llamada_id, fecha_hora  (resetea avisos y vuelve a 'pendiente')
 *
 * El recordatorio lo dispara cron/recordatorio_llamadas.php (push + interna
 * SOLO al usuario asignado). Reglas: solo el asignado, quien la creó o un
 * gerente pueden cerrar/cancelar/reprogramar.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
csrf_verify();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$user   = auth_user();
$accion = $_POST['accion'] ?? '';
$pdo    = Database::get();

$AVISOS_VALIDOS = [5, 10, 15, 30, 60];

function _llamada_editable(array $ll, array $user): bool {
    return (int)$ll['usuario_id'] === (int)$user['id']
        || (int)($ll['created_by'] ?? 0) === (int)$user['id']
        || has_role('gerente_general', 'gerente_operaciones');
}

if ($accion === 'crear') {
    $cliente_id    = (int)($_POST['cliente_id'] ?? 0);
    $cotizacion_id = (int)($_POST['cotizacion_id'] ?? 0) ?: null;
    $usuario_id    = (int)($_POST['usuario_id'] ?? 0) ?: (int)$user['id'];
    $fecha_hora    = trim($_POST['fecha_hora'] ?? '');
    $aviso_min     = (int)($_POST['aviso_min'] ?? 15);
    $nota          = mb_substr(trim($_POST['nota'] ?? ''), 0, 500);

    if (!$cliente_id || !$fecha_hora) json_error('Cliente y fecha/hora son obligatorios.');
    $ts = strtotime($fecha_hora);
    if (!$ts) json_error('Fecha/hora inválida.');
    if ($ts <= time()) json_error('La fecha/hora debe ser futura.');
    if (!in_array($aviso_min, $AVISOS_VALIDOS, true)) $aviso_min = 15;

    $chk = $pdo->prepare("SELECT id, razon_social, horario_contacto FROM clientes WHERE id = ? AND deleted_at IS NULL");
    $chk->execute([$cliente_id]);
    $cli = $chk->fetch();
    if (!$cli) json_error('Cliente no encontrado.');

    // Asignado debe ser un usuario activo
    $uchk = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND activo = 1");
    $uchk->execute([$usuario_id]);
    if (!$uchk->fetch()) json_error('Usuario asignado inválido.');

    $pdo->prepare("
        INSERT INTO llamadas_programadas
          (cliente_id, cotizacion_id, usuario_id, fecha_hora, aviso_min, nota, created_by)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([
        $cliente_id, $cotizacion_id, $usuario_id,
        date('Y-m-d H:i:00', $ts), $aviso_min,
        $nota !== '' ? $nota : null, (int)$user['id'],
    ]);
    $nuevo_id = (int)$pdo->lastInsertId();

    log_actividad('clientes', 'llamada_agendar',
        "Llamada #{$nuevo_id} a {$cli['razon_social']} el " . date('d/m/Y H:i', $ts) . " (aviso {$aviso_min} min antes)");

    json_ok(['id' => $nuevo_id], 'Llamada agendada. Recibirás un aviso ' . $aviso_min . ' min antes.');
}

// ── Acciones sobre una llamada existente ──────────────────────────────────────
$llamada_id = (int)($_POST['llamada_id'] ?? 0);
if (!$llamada_id) json_error('Parámetros inválidos.');

$st = $pdo->prepare("SELECT * FROM llamadas_programadas WHERE id = ?");
$st->execute([$llamada_id]);
$ll = $st->fetch();
if (!$ll) json_error('Llamada no encontrada.');
if (!_llamada_editable($ll, $user)) json_error('Sin permiso sobre esta llamada.', 403);
if (in_array($ll['estado'], ['realizada', 'cancelada'], true)) json_error('La llamada ya está cerrada.');

if ($accion === 'realizar') {
    $nota_cierre = mb_substr(trim($_POST['nota_cierre'] ?? ''), 0, 500);
    $pdo->prepare("UPDATE llamadas_programadas SET estado='realizada', nota_cierre=? WHERE id=?")
        ->execute([$nota_cierre !== '' ? $nota_cierre : null, $llamada_id]);
    log_actividad('clientes', 'llamada_realizada', "Llamada #{$llamada_id} marcada realizada");
    json_ok(null, 'Llamada marcada como realizada.');
}

if ($accion === 'cancelar') {
    $pdo->prepare("UPDATE llamadas_programadas SET estado='cancelada' WHERE id=?")->execute([$llamada_id]);
    log_actividad('clientes', 'llamada_cancelada', "Llamada #{$llamada_id} cancelada");
    json_ok(null, 'Llamada cancelada.');
}

if ($accion === 'reprogramar') {
    $fecha_hora = trim($_POST['fecha_hora'] ?? '');
    $ts = strtotime($fecha_hora);
    if (!$ts) json_error('Fecha/hora inválida.');
    if ($ts <= time()) json_error('La fecha/hora debe ser futura.');
    $pdo->prepare("
        UPDATE llamadas_programadas
        SET fecha_hora=?, estado='pendiente', aviso_previo_at=NULL, aviso_hora_at=NULL
        WHERE id=?
    ")->execute([date('Y-m-d H:i:00', $ts), $llamada_id]);
    log_actividad('clientes', 'llamada_reprogramar', "Llamada #{$llamada_id} → " . date('d/m/Y H:i', $ts));
    json_ok(null, 'Llamada reprogramada.');
}

json_error('Acción inválida.');
