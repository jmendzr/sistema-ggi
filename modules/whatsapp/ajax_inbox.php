<?php
/**
 * ajax_inbox.php — Datos del inbox multiagente de WhatsApp.
 *
 * Fase 2 (solo lectura): listado de conversaciones, hilo y contexto de empresa.
 * Las acciones de escritura (asignar/liberar/enviar) llegan en la fase 3.
 *
 * Todos los ejecutivos VEN todas las conversaciones; el candado de escritura
 * (asignado_a) se aplica al enviar, no al leer.
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
$accion = $_GET['accion'] ?? 'lista';

/** Etiqueta legible del estado de entrega. */
function wa_estado_label(?string $e): string
{
    return [
        'queued'    => 'En cola',
        'sent'      => 'Enviado',
        'delivered' => 'Entregado',
        'read'      => 'Leído',
        'failed'    => 'Falló',
    ][$e] ?? '';
}

// ── Listado de conversaciones ────────────────────────────────────────────────
if ($accion === 'lista') {
    $filtro = $_GET['filtro'] ?? 'todas';   // todas | mias | sin_asignar
    $buscar = trim($_GET['q'] ?? '');

    $where = ["c.estado <> 'resuelta'"];
    $args  = [];

    if ($filtro === 'mias')        { $where[] = 'c.asignado_a = ?'; $args[] = $yo; }
    elseif ($filtro === 'sin_asignar') { $where[] = 'c.asignado_a IS NULL'; }

    if ($buscar !== '') {
        $where[] = '(c.telefono LIKE ? OR cl.razon_social LIKE ? OR c.ultimo_mensaje_preview LIKE ?)';
        $like    = '%' . $buscar . '%';
        array_push($args, $like, $like, $like);
    }

    $sql = "SELECT c.id, c.telefono, c.cliente_id, c.asignado_a, c.asignado_at, c.no_leidos,
                   c.ventana_expira_at, c.ultimo_mensaje_at, c.ultimo_mensaje_preview,
                   cl.razon_social,
                   TRIM(CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,''))) AS asignado_nombre
              FROM wa_conversaciones c
              LEFT JOIN clientes cl ON cl.id = c.cliente_id
              LEFT JOIN usuarios u  ON u.id  = c.asignado_a
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.ultimo_mensaje_at DESC
             LIMIT 100";

    $st = $pdo->prepare($sql);
    $st->execute($args);

    $out = [];
    foreach ($st as $r) {
        $out[] = [
            'id'          => (int) $r['id'],
            'telefono'    => $r['telefono'],
            'titulo'      => $r['razon_social'] ?: '+' . $r['telefono'],
            'tiene_empresa' => (bool) $r['cliente_id'],
            'preview'     => $r['ultimo_mensaje_preview'] ?? '',
            'cuando'      => $r['ultimo_mensaje_at'],
            'no_leidos'   => (int) $r['no_leidos'],
            'asignado_a'  => $r['asignado_a'] ? (int) $r['asignado_a'] : null,
            'asignado_nombre' => $r['asignado_nombre'] ?: null,
            'es_mia'      => ((int) $r['asignado_a']) === $yo,
            // Días asignada: compensa que no haya liberación automática,
            // para que una conversación estancada se note.
            'dias_asignada' => $r['asignado_at']
                ? (int) floor((time() - strtotime($r['asignado_at'])) / 86400)
                : null,
            'ventana_abierta' => WaInbox::ventanaAbierta($r['ventana_expira_at']),
        ];
    }

    // Contadores de los filtros
    $cont = $pdo->prepare(
        "SELECT COUNT(*) todas,
                SUM(asignado_a = ?)     mias,
                SUM(asignado_a IS NULL) sin_asignar
           FROM wa_conversaciones WHERE estado <> 'resuelta'"
    );
    $cont->execute([$yo]);
    $c = $cont->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'    => true,
        'items' => $out,
        'conteos' => [
            'todas'       => (int) ($c['todas'] ?? 0),
            'mias'        => (int) ($c['mias'] ?? 0),
            'sin_asignar' => (int) ($c['sin_asignar'] ?? 0),
        ],
    ]);
    exit;
}

// ── Hilo de una conversación + contexto ──────────────────────────────────────
if ($accion === 'hilo') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }

    $st = $pdo->prepare(
        "SELECT c.*, cl.razon_social, cl.ruc, cl.contacto_nombre, cl.contacto_email,
                TRIM(CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,''))) AS asignado_nombre
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
                TRIM(CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,''))) AS autor
           FROM wa_mensajes m
           LEFT JOIN usuarios u ON u.id = m.enviado_por
          WHERE m.conversacion_id = ?
          ORDER BY m.id ASC
          LIMIT 300"
    );
    $ms->execute([$id]);

    $mensajes = [];
    foreach ($ms as $m) {
        $mensajes[] = [
            'id'        => (int) $m['id'],
            'dir'       => $m['direccion'],
            'tipo'      => $m['tipo'],
            'texto'     => $m['texto'],
            'template'  => $m['template_name'],
            'estado'    => $m['estado'],
            'estado_txt'=> wa_estado_label($m['estado']),
            'error'     => $m['error_msg'],
            // Automático del sistema vs escrito por una persona
            'autor'     => $m['enviado_por'] ? ($m['autor'] ?: 'Ejecutivo') : null,
            'cuando'    => $m['created_at'],
        ];
    }

    // Contexto de negocio: lo que la herramienta externa no puede mostrar
    $ctx = ['propuestas' => [], 'envios' => []];
    if ($conv['cliente_id']) {
        $q = $pdo->prepare(
            "SELECT numero, estado, monto_total AS total FROM cotizaciones
              WHERE cliente_id = ? AND deleted_at IS NULL
              ORDER BY id DESC LIMIT 5"
        );
        $q->execute([$conv['cliente_id']]);
        $ctx['propuestas'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $q = $pdo->prepare(
            "SELECT codigo_envio AS codigo, estado_envio_id FROM envios
              WHERE cliente_id = ? AND deleted_at IS NULL
              ORDER BY id DESC LIMIT 3"
        );
        $q->execute([$conv['cliente_id']]);
        $ctx['envios'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Marcar leída (no altera la asignación)
    $pdo->prepare("UPDATE wa_conversaciones SET no_leidos = 0 WHERE id = ?")->execute([$id]);

    echo json_encode([
        'ok'   => true,
        'conv' => [
            'id'              => (int) $conv['id'],
            'telefono'        => $conv['telefono'],
            'titulo'          => $conv['razon_social'] ?: '+' . $conv['telefono'],
            'cliente_id'      => $conv['cliente_id'] ? (int) $conv['cliente_id'] : null,
            'ruc'             => $conv['ruc'] ?? null,
            'contacto'        => $conv['contacto_nombre'] ?? null,
            'asignado_a'      => $conv['asignado_a'] ? (int) $conv['asignado_a'] : null,
            'asignado_nombre' => $conv['asignado_nombre'] ?: null,
            'es_mia'          => ((int) $conv['asignado_a']) === $yo,
            'ventana_abierta' => WaInbox::ventanaAbierta($conv['ventana_expira_at']),
            'ventana_expira'  => $conv['ventana_expira_at'],
            'estado'          => $conv['estado'],
        ],
        'mensajes' => $mensajes,
        'contexto' => $ctx,
        'yo'       => $yo,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
