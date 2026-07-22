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

// ══ Resolver / reabrir conversación ══════════════════════════════════════════
if ($accion === 'resolver' || $accion === 'reabrir') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }

    if ($accion === 'resolver') {
        // Resolver libera la conversación: si quedara asignada, nadie más
        // podría retomarla cuando el cliente vuelva a escribir.
        $pdo->prepare("UPDATE wa_conversaciones
                          SET estado = 'resuelta', asignado_a = NULL, asignado_at = NULL
                        WHERE id = ?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE wa_conversaciones SET estado = 'abierta' WHERE id = ?")->execute([$id]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ══ Etiquetas: catálogo ══════════════════════════════════════════════════════
if ($accion === 'etiquetas') {
    echo json_encode([
        'ok'    => true,
        'items' => $pdo->query("SELECT id, nombre, color FROM wa_etiquetas WHERE activo = 1 ORDER BY nombre")
                       ->fetchAll(PDO::FETCH_ASSOC),
    ]);
    exit;
}

// ══ Etiquetas: poner / quitar ════════════════════════════════════════════════
if ($accion === 'etiquetar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();

    $conv = (int) ($_POST['id'] ?? 0);
    $etq  = (int) ($_POST['etiqueta_id'] ?? 0);
    $on   = !empty($_POST['activar']);

    if (!$conv || !$etq) { echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']); exit; }

    if ($on) {
        $pdo->prepare("INSERT IGNORE INTO wa_conversacion_etiquetas (conversacion_id, etiqueta_id) VALUES (?, ?)")
            ->execute([$conv, $etq]);
    } else {
        $pdo->prepare("DELETE FROM wa_conversacion_etiquetas WHERE conversacion_id = ? AND etiqueta_id = ?")
            ->execute([$conv, $etq]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ══ Nueva conversación: buscar contacto y abrir/crear el hilo ════════════════
if ($accion === 'buscar_contacto') {
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'items' => []]); exit; }

    $like = '%' . $q . '%';
    $st = $pdo->prepare(
        "SELECT id, razon_social, ruc, contacto_telefono
           FROM clientes
          WHERE deleted_at IS NULL
            AND contacto_telefono IS NOT NULL AND contacto_telefono <> ''
            AND (razon_social LIKE ? OR ruc LIKE ? OR contacto_telefono LIKE ?)
          ORDER BY razon_social LIMIT 15"
    );
    $st->execute([$like, $like, $like]);

    echo json_encode(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($accion === 'abrir_contacto') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();

    $tel = preg_replace('/\D/', '', $_POST['telefono'] ?? '');
    if (strlen($tel) < 9) { echo json_encode(['ok' => false, 'msg' => 'Teléfono inválido']); exit; }
    if (strlen($tel) === 9) $tel = '51' . $tel;   // móvil peruano sin prefijo

    echo json_encode(['ok' => true, 'id' => WaInbox::ensureConversacion($pdo, $tel)]);
    exit;
}

// ══ Plantillas disponibles para enviar ═══════════════════════════════════════
if ($accion === 'plantillas') {
    // Solo las APROBADAS en Meta. Ofrecer una rechazada (o inexistente) sería
    // enviar al ejecutivo a un fallo garantizado.
    $st = $pdo->query(
        "SELECT event_code, template_name, language_code, param_keys, body_text
           FROM whatsapp_templates
          WHERE activo = 1 AND estado_meta = 'APPROVED'
            AND body_text IS NOT NULL AND body_text <> ''
          ORDER BY template_name"
    );

    $out = [];
    foreach ($st as $r) {
        $claves = json_decode($r['param_keys'] ?? '[]', true) ?: [];
        $out[] = [
            'nombre'   => $r['template_name'],
            'idioma'   => $r['language_code'],
            'cuerpo'   => $r['body_text'],
            'claves'   => $claves,          // nombres legibles de {{1}}, {{2}}…
            'n_params' => count($claves),
        ];
    }

    echo json_encode(['ok' => true, 'items' => $out]);
    exit;
}

// ══ Enviar plantilla (única vía fuera de la ventana de 24 h) ═════════════════
if ($accion === 'enviar_plantilla') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $id     = (int) ($_POST['id'] ?? 0);
    $nombre = trim($_POST['plantilla'] ?? '');
    $params = $_POST['params'] ?? [];
    if (!is_array($params)) $params = [];

    if (!$id || $nombre === '') {
        echo json_encode(['ok' => false, 'msg' => 'Datos incompletos']); exit;
    }

    $q = $pdo->prepare("SELECT telefono, asignado_a, phone_number_id FROM wa_conversaciones WHERE id = ?");
    $q->execute([$id]);
    $conv = $q->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }

    // Mismo candado que el texto libre
    if ((int) $conv['asignado_a'] !== $yo) {
        echo json_encode(['ok' => false, 'msg' => $conv['asignado_a']
            ? 'La conversación está asignada a otro ejecutivo'
            : 'Asígnate la conversación para responder']);
        exit;
    }

    $t = $pdo->prepare("SELECT template_name, language_code, param_keys, body_text
                          FROM whatsapp_templates WHERE template_name = ? AND activo = 1");
    $t->execute([$nombre]);
    $tpl = $t->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) { echo json_encode(['ok' => false, 'msg' => 'Plantilla no disponible']); exit; }

    // Se exigen todos los parámetros: Meta rechaza la plantilla si falta alguno
    $claves = json_decode($tpl['param_keys'] ?? '[]', true) ?: [];
    $vals   = [];
    foreach ($claves as $i => $_clave) {
        $v = trim((string) ($params[$i] ?? ''));
        if ($v === '') {
            echo json_encode(['ok' => false, 'msg' => 'Faltan datos de la plantilla']); exit;
        }
        $vals[] = $v;
    }

    $waId = WhatsAppService::sendTemplate($conv['telefono'], $tpl['template_name'], $vals, $tpl['language_code']);

    // Texto tal como lo verá el cliente, para que quede legible en el hilo
    $texto = $tpl['body_text'];
    foreach ($vals as $i => $v) $texto = str_replace('{{' . ($i + 1) . '}}', $v, $texto);

    WaInbox::registrarSaliente(
        $pdo, $conv['telefono'], $waId, 'template', $texto,
        $tpl['template_name'], $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
    );

    echo $waId
        ? json_encode(['ok' => true])
        : json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
    exit;
}

// ══ Enviar mensaje ═══════════════════════════════════════════════════════════
if ($accion === 'enviar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $id    = (int) ($_POST['id'] ?? 0);
    $texto = trim($_POST['texto'] ?? '');

    if (!$id)          { echo json_encode(['ok' => false, 'msg' => 'Conversación inválida']); exit; }
    if ($texto === '') { echo json_encode(['ok' => false, 'msg' => 'Escribe un mensaje']); exit; }
    // WhatsApp corta los mensajes de texto en 4096 caracteres
    if (mb_strlen($texto) > 4096) {
        echo json_encode(['ok' => false, 'msg' => 'El mensaje supera los 4096 caracteres']); exit;
    }

    $q = $pdo->prepare("SELECT telefono, asignado_a, ventana_expira_at, phone_number_id
                          FROM wa_conversaciones WHERE id = ?");
    $q->execute([$id]);
    $conv = $q->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }

    // El candado: solo escribe quien la tiene asignada. Gerencia tampoco pisa
    // una conversación ajena sin reasignarla antes — si no, el bloqueo no sirve.
    if ((int) $conv['asignado_a'] !== $yo) {
        echo json_encode([
            'ok'  => false,
            'msg' => $conv['asignado_a'] ? 'La conversación está asignada a otro ejecutivo'
                                         : 'Asígnate la conversación para responder',
        ]);
        exit;
    }

    // Regla de Meta: fuera de las 24 h solo se admiten plantillas aprobadas.
    // Se valida aquí y no solo en la interfaz, para que no dependa del navegador.
    if (!WaInbox::ventanaAbierta($conv['ventana_expira_at'])) {
        echo json_encode([
            'ok'  => false,
            'msg' => 'La ventana de 24 h venció. Solo se pueden enviar plantillas aprobadas.',
            'ventana_vencida' => true,
        ]);
        exit;
    }

    $waId = WhatsAppService::sendText($conv['telefono'], $texto);

    WaInbox::registrarSaliente(
        $pdo, $conv['telefono'], $waId, 'text', $texto,
        null, $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
    );

    if (!$waId) {
        echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
        exit;
    }

    echo json_encode(['ok' => true]);
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
    // "Sin persona": el número no está vinculado a ninguna empresa del sistema
    elseif ($filtro === 'sin_persona') { $where[] = 'c.cliente_id IS NULL'; }

    $etq = (int) ($_GET['etiqueta'] ?? 0);
    if ($etq) {
        $where[] = 'EXISTS (SELECT 1 FROM wa_conversacion_etiquetas x
                             WHERE x.conversacion_id = c.id AND x.etiqueta_id = ?)';
        $args[]  = $etq;
    }

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

    $filas = $st->fetchAll(PDO::FETCH_ASSOC);

    // Etiquetas de toda la página en UNA consulta, no una por fila
    $etqPorConv = [];
    $ids = array_column($filas, 'id');
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $qe = $pdo->prepare("SELECT x.conversacion_id, e.nombre, e.color
                               FROM wa_conversacion_etiquetas x
                               JOIN wa_etiquetas e ON e.id = x.etiqueta_id AND e.activo = 1
                              WHERE x.conversacion_id IN ($ph)");
        $qe->execute($ids);
        foreach ($qe as $e) {
            $etqPorConv[$e['conversacion_id']][] = ['nombre' => $e['nombre'], 'color' => $e['color']];
        }
    }

    $items = [];
    foreach ($filas as $r) {
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
            'etiquetas'       => $etqPorConv[$r['id']] ?? [],
        ];
    }

    $c = $pdo->prepare(
        "SELECT COUNT(*) todas, SUM(asignado_a = ?) mias,
                SUM(asignado_a IS NULL) sin_asignar, SUM(cliente_id IS NULL) sin_persona
           FROM wa_conversaciones WHERE estado <> 'resuelta'"
    );
    $c->execute([$yo]);
    $cn = $c->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['ok' => true, 'items' => $items, 'conteos' => [
        'todas'       => (int) ($cn['todas'] ?? 0),
        'mias'        => (int) ($cn['mias'] ?? 0),
        'sin_asignar' => (int) ($cn['sin_asignar'] ?? 0),
        'sin_persona' => (int) ($cn['sin_persona'] ?? 0),
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

    $qe = $pdo->prepare("SELECT e.id, e.nombre, e.color
                           FROM wa_conversacion_etiquetas x
                           JOIN wa_etiquetas e ON e.id = x.etiqueta_id AND e.activo = 1
                          WHERE x.conversacion_id = ? ORDER BY e.nombre");
    $qe->execute([$id]);
    $etiquetas = $qe->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
        'estado'          => $conv['estado'],
    ], 'mensajes' => $mensajes, 'contexto' => $ctx, 'etiquetas' => $etiquetas]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
