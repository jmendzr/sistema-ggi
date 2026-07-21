<?php
/**
 * modules/inbox/ajax.php — Datos y acciones del inbox multiagente.
 *
 * Lectura: lista de conversaciones, hilo y contexto de negocio.
 * Escritura: asignar / liberar (el candado). El envío de mensajes llega
 * en la fase siguiente.
 *
 * Todos los ejecutivos VEN todo; el candado se aplica al escribir.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WaInbox.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas');
header('Content-Type: application/json; charset=utf-8');

$pdo    = Database::get();
$yo     = (int) ($_SESSION['usuario']['id'] ?? 0);
$rol    = $_SESSION['usuario']['rol'] ?? '';
$esJefe = in_array($rol, ['gerente_general', 'gerente_operaciones'], true);
$accion = $_REQUEST['accion'] ?? 'lista';

function wa_estado_label(?string $e): string
{
    return [
        'queued' => 'En cola', 'sent' => 'Enviado', 'delivered' => 'Entregado',
        'read'   => 'Leído',   'failed' => 'Falló',
    ][$e] ?? '';
}

// ══ Acciones de escritura ════════════════════════════════════════════════════
if ($accion === 'asignar' || $accion === 'liberar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }

    if ($accion === 'asignar') {
        // Atómico: si dos ejecutivos hacen clic a la vez, solo uno gana.
        if (WaInbox::asignar($pdo, $id, $yo)) {
            echo json_encode(['ok' => true]);
        } else {
            $q = $pdo->prepare(
                "SELECT TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) n
                   FROM wa_conversaciones c LEFT JOIN usuarios u ON u.id = c.asignado_a
                  WHERE c.id = ?"
            );
            $q->execute([$id]);
            $quien = $q->fetchColumn() ?: 'otro ejecutivo';
            echo json_encode(['ok' => false, 'msg' => 'Ya la tomó ' . $quien, 'recargar' => true]);
        }
        exit;
    }

    // Liberar: propia siempre; ajena solo gerencia
    if (WaInbox::liberar($pdo, $id, $yo, $esJefe)) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'No puedes liberar una conversación de otro ejecutivo']);
    }
    exit;
}

// ══ Listado ══════════════════════════════════════════════════════════════════
if ($accion === 'lista') {
    $filtro = $_GET['filtro'] ?? 'todas';
    $buscar = trim($_GET['q'] ?? '');

    $where = ["c.estado <> 'resuelta'"];
    $args  = [];

    if ($filtro === 'mias')            { $where[] = 'c.asignado_a = ?'; $args[] = $yo; }
    elseif ($filtro === 'sin_asignar') { $where[] = 'c.asignado_a IS NULL'; }

    if ($buscar !== '') {
        $where[] = '(c.telefono LIKE ? OR cl.razon_social LIKE ? OR c.ultimo_mensaje_preview LIKE ?)';
        $like = '%' . $buscar . '%';
        array_push($args, $like, $like, $like);
    }

    $st = $pdo->prepare(
        "SELECT c.id, c.telefono, c.cliente_id, c.asignado_a, c.asignado_at, c.no_leidos,
                c.ventana_expira_at, c.ultimo_mensaje_at, c.ultimo_mensaje_preview, cl.razon_social,
                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS asignado_nombre
           FROM wa_conversaciones c
           LEFT JOIN clientes cl ON cl.id = c.cliente_id
           LEFT JOIN usuarios u  ON u.id  = c.asignado_a
          WHERE " . implode(' AND ', $where) . "
          ORDER BY c.ultimo_mensaje_at DESC LIMIT 100"
    );
    $st->execute($args);

    $items = [];
    foreach ($st as $r) {
        $items[] = [
            'id'        => (int) $r['id'],
            'telefono'  => $r['telefono'],
            'titulo'    => $r['razon_social'] ?: '+' . $r['telefono'],
            'preview'   => $r['ultimo_mensaje_preview'] ?? '',
            'cuando'    => $r['ultimo_mensaje_at'],
            'no_leidos' => (int) $r['no_leidos'],
            'asignado_a'      => $r['asignado_a'] ? (int) $r['asignado_a'] : null,
            'asignado_nombre' => $r['asignado_nombre'] ?: null,
            'es_mia'          => ((int) $r['asignado_a']) === $yo,
            // Sin liberación automática, una conversación estancada debe notarse
            'dias_asignada'   => $r['asignado_at'] ? (int) floor((time() - strtotime($r['asignado_at'])) / 86400) : null,
            'ventana_abierta' => WaInbox::ventanaAbierta($r['ventana_expira_at']),
        ];
    }

    $c = $pdo->prepare(
        "SELECT COUNT(*) todas, SUM(asignado_a = ?) mias, SUM(asignado_a IS NULL) sin_asignar
           FROM wa_conversaciones WHERE estado <> 'resuelta'"
    );
    $c->execute([$yo]);
    $cn = $c->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['ok' => true, 'items' => $items, 'conteos' => [
        'todas' => (int) ($cn['todas'] ?? 0),
        'mias'  => (int) ($cn['mias'] ?? 0),
        'sin_asignar' => (int) ($cn['sin_asignar'] ?? 0),
    ]]);
    exit;
}

// ══ Hilo + contexto ══════════════════════════════════════════════════════════
if ($accion === 'hilo') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }

    $st = $pdo->prepare(
        "SELECT c.*, cl.razon_social, cl.ruc, cl.contacto_nombre,
                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS asignado_nombre
           FROM wa_conversaciones c
           LEFT JOIN clientes cl ON cl.id = c.cliente_id
           LEFT JOIN usuarios u  ON u.id  = c.asignado_a
          WHERE c.id = ?"
    );
    $st->execute([$id]);
    $conv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'No encontrada']); exit; }

    $ms = $pdo->prepare(
        "SELECT m.id, m.direccion, m.tipo, m.texto, m.template_name, m.estado, m.error_msg,
                m.enviado_por, m.created_at,
                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS autor
           FROM wa_mensajes m LEFT JOIN usuarios u ON u.id = m.enviado_por
          WHERE m.conversacion_id = ? ORDER BY m.id ASC LIMIT 300"
    );
    $ms->execute([$id]);

    $mensajes = [];
    foreach ($ms as $m) {
        $mensajes[] = [
            'dir'        => $m['direccion'],
            'texto'      => $m['texto'],
            'estado'     => $m['estado'],
            'estado_txt' => wa_estado_label($m['estado']),
            'error'      => $m['error_msg'],
            'autor'      => $m['enviado_por'] ? ($m['autor'] ?: 'Ejecutivo') : null,
            'cuando'     => $m['created_at'],
        ];
    }

    $ctx = ['propuestas' => [], 'envios' => []];
    if ($conv['cliente_id']) {
        $q = $pdo->prepare("SELECT id, numero, estado FROM cotizaciones
                             WHERE cliente_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 5");
        $q->execute([$conv['cliente_id']]);
        $ctx['propuestas'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $q = $pdo->prepare("SELECT id, codigo_envio FROM envios
                             WHERE cliente_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 3");
        $q->execute([$conv['cliente_id']]);
        $ctx['envios'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $pdo->prepare("UPDATE wa_conversaciones SET no_leidos = 0 WHERE id = ?")->execute([$id]);

    echo json_encode(['ok' => true, 'conv' => [
        'id'              => (int) $conv['id'],
        'telefono'        => $conv['telefono'],
        'titulo'          => $conv['razon_social'] ?: '+' . $conv['telefono'],
        'cliente_id'      => $conv['cliente_id'] ? (int) $conv['cliente_id'] : null,
        'ruc'             => $conv['ruc'] ?? null,
        'contacto'        => $conv['contacto_nombre'] ?? null,
        'asignado_a'      => $conv['asignado_a'] ? (int) $conv['asignado_a'] : null,
        'asignado_nombre' => $conv['asignado_nombre'] ?: null,
        'es_mia'          => ((int) $conv['asignado_a']) === $yo,
        'puede_liberar'   => ((int) $conv['asignado_a']) === $yo || $esJefe,
        'ventana_abierta' => WaInbox::ventanaAbierta($conv['ventana_expira_at']),
        'ventana_expira'  => $conv['ventana_expira_at'],
    ], 'mensajes' => $mensajes, 'contexto' => $ctx]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
