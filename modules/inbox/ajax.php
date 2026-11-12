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
require_role('gerente_general', 'gerente_operaciones', 'ejecutivo_ventas','gerente_comercial');
// Liberar el lock de sesión de inmediato: este endpoint solo LEE $_SESSION
// (id/rol del usuario), nunca escribe. Sin esto, PHP serializa las peticiones
// AJAX concurrentes (lista + hilo + media + refrescar) y el inbox se siente
// lento. csrf_verify() sigue funcionando: $_SESSION queda legible en memoria.
session_write_close();
header('Content-Type: application/json; charset=utf-8');

$pdo    = Database::get();
$yo     = (int) ($_SESSION['usuario']['id'] ?? 0);
$rol    = $_SESSION['usuario']['rol'] ?? '';
$esJefe = in_array($rol, ['gerente_general', 'gerente_operaciones', 'gerente_comercial'], true);
$accion = $_REQUEST['accion'] ?? 'lista';

function wa_estado_label(?string $e): string
{
    return [
        'queued' => 'En cola', 'sent' => 'Enviado', 'delivered' => 'Entregado',
        'read'   => 'Leído',   'failed' => 'Falló',
    ][$e] ?? '';
}

// Etapa del embudo derivada AUTOMÁTICAMENTE del estado real del cliente.
// La más avanzada gana. $cidCol es un nombre de columna (no entra input de usuario).
function gg_etapa_case(string $cidCol): string
{
    return "CASE
        WHEN EXISTS(SELECT 1 FROM certificados ce WHERE ce.cliente_id=$cidCol AND ce.deleted_at IS NULL AND ce.estado='vigente') THEN 'certificado'
        WHEN EXISTS(SELECT 1 FROM cotizaciones co WHERE co.cliente_id=$cidCol AND co.deleted_at IS NULL AND co.estado='aceptada') THEN 'cliente'
        WHEN EXISTS(SELECT 1 FROM cotizaciones co WHERE co.cliente_id=$cidCol AND co.deleted_at IS NULL AND co.estado='enviada') THEN 'negociacion'
        WHEN EXISTS(SELECT 1 FROM cotizaciones co WHERE co.cliente_id=$cidCol AND co.deleted_at IS NULL) THEN 'frio'
        WHEN $cidCol IS NOT NULL THEN 'lead'
        ELSE NULL END";
}
const GG_ETAPAS = ['lead', 'negociacion', 'cliente', 'certificado', 'frio'];

// ══ Buscar conversación por teléfono (deep-link desde otras páginas) ═════════
if ($accion === 'por_tel') {
    $tel = preg_replace('/\D/', '', $_REQUEST['tel'] ?? '');
    if (strlen($tel) < 9) { echo json_encode(['ok' => false]); exit; }
    $q = $pdo->prepare("SELECT id FROM wa_conversaciones WHERE telefono LIKE ? ORDER BY id DESC LIMIT 1");
    $q->execute(['%' . substr($tel, -9)]);
    $cid = $q->fetchColumn();
    echo json_encode(['ok' => (bool) $cid, 'id' => $cid ? (int) $cid : null]);
    exit;
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
            WaInbox::registrarAsignacion($pdo, $id, 'tomar', null, $yo, $yo);
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
    $q = $pdo->prepare("SELECT asignado_a FROM wa_conversaciones WHERE id = ?");
    $q->execute([$id]);
    $antes = $q->fetchColumn();

    if (WaInbox::liberar($pdo, $id, $yo, $esJefe)) {
        WaInbox::registrarAsignacion(
            $pdo, $id, ((int) $antes === $yo) ? 'soltar' : 'quitar',
            $antes ? (int) $antes : null, null, $yo
        );
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'No puedes liberar una conversación de otro ejecutivo']);
    }
    exit;
}

// ══ Reasignar a otro ejecutivo (solo gerencia) ═══════════════════════════════
if ($accion === 'reasignar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();

    if (!$esJefe) {
        echo json_encode(['ok' => false, 'msg' => 'Solo gerencia puede reasignar conversaciones']); exit;
    }

    $id  = (int) ($_POST['id'] ?? 0);
    $a   = ($_POST['a_usuario'] ?? '') !== '' ? (int) $_POST['a_usuario'] : null;
    $mot = trim($_POST['motivo'] ?? '');
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }

    // Solo a alguien que pueda atender de verdad
    if ($a !== null) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM usuarios
                             WHERE id = ? AND activo = 1
                               AND rol IN ('ejecutivo_ventas','gerente_operaciones','gerente_general')");
        $q->execute([$a]);
        if (!$q->fetchColumn()) {
            echo json_encode(['ok' => false, 'msg' => 'Ese usuario no puede atender conversaciones']); exit;
        }
    }

    echo WaInbox::reasignar($pdo, $id, $a, $yo, $mot ?: null)
        ? json_encode(['ok' => true])
        : json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']);
    exit;
}

// ══ Ejecutivos disponibles + historial de asignaciones ═══════════════════════
if ($accion === 'equipo') {
    if (!$esJefe) { echo json_encode(['ok' => false, 'msg' => 'Sin permiso']); exit; }

    $u = $pdo->query(
        "SELECT id, TRIM(CONCAT(nombre,' ',apellido)) nombre, rol
           FROM usuarios
          WHERE activo = 1 AND rol IN ('ejecutivo_ventas','gerente_operaciones','gerente_general')
          ORDER BY rol = 'ejecutivo_ventas' DESC, nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    $id = (int) ($_GET['id'] ?? 0);

    echo json_encode([
        'ok'        => true,
        'usuarios'  => $u,
        'historial' => $id ? WaInbox::historialAsignaciones($pdo, $id) : [],
    ]);
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
        "SELECT event_code, template_name, language_code, param_keys, body_text, categoria, url_button_key
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
            // La categoría no es cosmética: Marketing cuesta más por mensaje y
            // exige consentimiento del cliente.
            'categoria'=> $r['categoria'] ?: 'UTILITY',
            // Botón URL dinámico: Meta EXIGE su parámetro aparte del cuerpo,
            // y sin él rechaza el envío con el error 131008.
            'boton'    => $r['url_button_key'] ?: null,
        ];
    }

    echo json_encode(['ok' => true, 'items' => $out]);
    exit;
}

/**
 * Resuelve los datos de una plantilla a partir de la empresa de la conversación.
 *
 * Son las mismas claves que ya usan las notificaciones automáticas, así que el
 * ejecutivo no debería tener que copiarlas a mano: el sistema las conoce.
 * Devuelve solo lo que puede afirmar; lo demás queda vacío para completar.
 */
function gg_inbox_sugerir(PDO $pdo, ?int $clienteId, array $claves): array
{
    $out = array_fill_keys($claves, '');
    if (!$clienteId) return $out;

    $c = $pdo->prepare("SELECT razon_social FROM clientes WHERE id = ?");
    $c->execute([$clienteId]);
    $cli = $c->fetch(PDO::FETCH_ASSOC);
    if (!$cli) return $out;

    foreach ($claves as $k) {
        if ($k === 'razon_social') {
            $out[$k] = $cli['razon_social'] ?? '';

        } elseif ($k === 'cot_numero') {
            // La propuesta más reciente que no sea borrador: es de la que se habla
            $q = $pdo->prepare("SELECT numero FROM cotizaciones
                                 WHERE cliente_id = ? AND deleted_at IS NULL AND estado <> 'borrador'
                                 ORDER BY id DESC LIMIT 1");
            $q->execute([$clienteId]);
            $out[$k] = (string) ($q->fetchColumn() ?: '');

        } elseif ($k === 'portal_token') {
            $q = $pdo->prepare("SELECT portal_token FROM clientes WHERE id = ?");
            $q->execute([$clienteId]);
            $out[$k] = (string) ($q->fetchColumn() ?: '');

        } elseif ($k === 'normas') {
            // Normas de sus propuestas aceptadas, sin repetir
            // Se quita el año (ISO 9001:2015 → ISO 9001) igual que en los
            // envíos automáticos: si no, el mismo dato se vería distinto según
            // lo mandara el sistema o una persona.
            $q = $pdo->prepare(
                "SELECT DISTINCT SUBSTRING_INDEX(n.codigo, ':', 1) AS codigo
                   FROM cotizaciones cot
                   JOIN cotizacion_normas cn ON cn.cotizacion_id = cot.id
                   JOIN normas_iso n         ON n.id = cn.norma_iso_id
                  WHERE cot.cliente_id = ? AND cot.deleted_at IS NULL
                    AND cot.estado = 'aceptada'
                  ORDER BY codigo"
            );
            $q->execute([$clienteId]);
            $lista = implode(', ', $q->fetchAll(PDO::FETCH_COLUMN) ?: []);

            // gg_normas_compact deja "ISO 9001, 14001, …" como en los envíos
            if (function_exists('gg_normas_compact')) $lista = gg_normas_compact($lista);
            $out[$k] = $lista;
        }
    }

    return $out;
}

// ══ Datos sugeridos para una plantilla ═══════════════════════════════════════
if ($accion === 'sugerir_params') {
    $id     = (int) ($_GET['id'] ?? 0);
    $claves = array_filter(array_map('trim', explode(',', $_GET['claves'] ?? '')));
    if (!$id || !$claves) { echo json_encode(['ok' => true, 'valores' => []]); exit; }

    $q = $pdo->prepare("SELECT cliente_id FROM wa_conversaciones WHERE id = ?");
    $q->execute([$id]);
    $cli = $q->fetchColumn();

    echo json_encode([
        'ok'      => true,
        'valores' => gg_inbox_sugerir($pdo, $cli ? (int) $cli : null, $claves),
    ]);
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

    $t = $pdo->prepare("SELECT template_name, language_code, param_keys, body_text, url_button_key, header_media_url
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

    // El parámetro del botón va aparte de los del cuerpo
    $boton = trim($_POST['boton'] ?? '');
    if ($tpl['url_button_key'] && $boton === '') {
        echo json_encode(['ok' => false,
            'msg' => 'Esta plantilla lleva un botón que necesita un dato adicional.']);
        exit;
    }

    // OJO: las plantillas SOLO existen en la WABA de notificaciones (Frank/Andrés
    // tienen 0). Por eso las plantillas se envían desde el número de notificaciones,
    // NO desde el número conversacional (si no, error 132001 "template does not exist").
    // Si la plantilla lleva cabecera (imagen/PDF), Meta exige la media en cada
    // envío. Detectamos por extensión: .pdf → documento; si no, imagen.
    $hdr   = trim((string) ($tpl['header_media_url'] ?? ''));
    $esPdf = $hdr !== '' && preg_match('/\.pdf($|\?)/i', $hdr);
    $waId = WhatsAppService::sendTemplate(
        $conv['telefono'], $tpl['template_name'], $vals, $tpl['language_code'],
        $esPdf ? $hdr : null,                       // docUrl (cabecera documento)
        null,                                       // docFilename
        $boton !== '' ? $boton : null,              // parámetro del botón URL
        null,                                       // desde el número de notificaciones
        (!$esPdf && $hdr !== '') ? $hdr : null       // imgUrl (cabecera imagen)
    );

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

// ══ Stickers ya usados (no hay catálogo que ofrecer, pero sí historial) ══════
if ($accion === 'stickers') {
    $st = $pdo->query(
        "SELECT MIN(id) id, media_id
           FROM wa_mensajes
          WHERE tipo = 'sticker' AND media_id IS NOT NULL
          GROUP BY media_id
          ORDER BY id DESC
          LIMIT 40"
    );
    echo json_encode(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ══ Reenviar un sticker ya conocido (por su media_id) ════════════════════════
if ($accion === 'enviar_sticker') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $id  = (int) ($_POST['id'] ?? 0);
    $msg = (int) ($_POST['mensaje_id'] ?? 0);
    if (!$id || !$msg) { echo json_encode(['ok' => false, 'msg' => 'Datos incompletos']); exit; }

    $q = $pdo->prepare("SELECT telefono, asignado_a, ventana_expira_at, phone_number_id
                          FROM wa_conversaciones WHERE id = ?");
    $q->execute([$id]);
    $conv = $q->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }

    if ((int) $conv['asignado_a'] !== $yo) {
        echo json_encode(['ok' => false, 'msg' => 'Toma la conversación para poder responder']); exit;
    }
    if (!WaInbox::ventanaAbierta($conv['ventana_expira_at'])) {
        echo json_encode(['ok' => false, 'msg' => 'La ventana de 24 h venció: solo se pueden enviar plantillas']); exit;
    }

    $s = $pdo->prepare("SELECT media_id FROM wa_mensajes WHERE id = ? AND tipo = 'sticker'");
    $s->execute([$msg]);
    $mediaId = $s->fetchColumn();
    if (!$mediaId) { echo json_encode(['ok' => false, 'msg' => 'Sticker no disponible']); exit; }

    $waId = WhatsAppService::sendMedia($conv['telefono'], 'sticker', (string) $mediaId, null, null, $conv['phone_number_id']);

    WaInbox::registrarSaliente(
        $pdo, $conv['telefono'], $waId, 'sticker', null,
        null, $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
    );

    if ($waId) {
        $pdo->prepare("UPDATE wa_mensajes SET media_id = ?, media_mime = 'image/webp' WHERE wa_message_id = ?")
            ->execute([$mediaId, $waId]);
    }

    echo $waId
        ? json_encode(['ok' => true])
        : json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
    exit;
}

// ══ Adjuntar y enviar archivo ════════════════════════════════════════════════
if ($accion === 'adjuntar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id || empty($_FILES['archivo']['tmp_name'])) {
        echo json_encode(['ok' => false, 'msg' => 'Falta el archivo']); exit;
    }
    if (($_FILES['archivo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'La subida falló (¿archivo muy grande?)']); exit;
    }

    $q = $pdo->prepare("SELECT telefono, asignado_a, ventana_expira_at, phone_number_id
                          FROM wa_conversaciones WHERE id = ?");
    $q->execute([$id]);
    $conv = $q->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }

    if ((int) $conv['asignado_a'] !== $yo) {
        echo json_encode(['ok' => false, 'msg' => 'Toma la conversación para poder responder']); exit;
    }
    // Los adjuntos son mensajes libres: fuera de la ventana Meta los rechaza
    if (!WaInbox::ventanaAbierta($conv['ventana_expira_at'])) {
        echo json_encode(['ok' => false, 'msg' => 'La ventana de 24 h venció: solo se pueden enviar plantillas']); exit;
    }

    $tmp    = $_FILES['archivo']['tmp_name'];
    $nombre = $_FILES['archivo']['name'] ?? 'archivo';
    $mime   = mime_content_type($tmp) ?: 'application/octet-stream';
    $peso   = filesize($tmp);
    $aviso  = null;
    $tempConvertido = null;   // temporal a limpiar si hubo conversión
    $esVoz  = !empty($_POST['voz']);

    // Nota de voz: mime_content_type de un .ogg suele devolver application/ogg,
    // pero Meta exige audio/ogg. Se corrige por la extensión del archivo grabado.
    if ($esVoz) {
        $ext  = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $mime = $ext === 'ogg' ? 'audio/ogg' : ($ext === 'mp4' ? 'audio/mp4' : $mime);
    }

    // Cada formato va por un tipo distinto de la API, no todos son "imagen":
    //  - webp  → sticker (WhatsApp solo anima stickers en ese formato)
    //  - gif   → Meta NO admite image/gif; se manda como documento para que
    //            al menos llegue. Animado solo funciona con MP4.
    //  - mp4   → video (es la forma real de mandar un "GIF" que se mueva)
    if ($mime === 'image/webp') {
        $tipo = 'sticker';
    } elseif ($mime === 'image/gif') {
        // Meta no admite image/gif y no hay ffmpeg para pasarlo a MP4. Pero
        // WhatsApp sí anima los stickers en WebP animado, y eso Imagick lo
        // puede generar: es la única vía para que el GIF se mueva.
        $webp = WhatsAppService::gifAStickerWebp($tmp);
        if ($webp) {
            $tmp   = $webp;
            $mime  = 'image/webp';
            $tipo  = 'sticker';
            $peso  = filesize($tmp);
            $aviso = 'El GIF se envió como sticker animado (es la forma en que WhatsApp lo reproduce).';
            $tempConvertido = $webp;
        } else {
            $tipo  = 'document';
            $aviso = 'El GIF era demasiado pesado para convertirlo a sticker animado, '
                   . 'así que se envió como archivo.';
        }
    } elseif (str_starts_with($mime, 'image/')) {
        $tipo = 'image';
    } elseif (str_starts_with($mime, 'video/')) {
        $tipo = 'video';
    } elseif (str_starts_with($mime, 'audio/')) {
        $tipo = 'audio';
    } else {
        $tipo = 'document';
    }

    // Límites de Meta por tipo
    $max = match ($tipo) {
        'sticker' => 500 * 1024,             // 500 KB animado / 100 KB estático
        'image'   => 5 * 1024 * 1024,
        'video', 'audio' => 16 * 1024 * 1024,
        default   => 100 * 1024 * 1024,
    };
    if ($peso > $max) {
        echo json_encode(['ok' => false, 'msg' => $tipo === 'sticker'
            ? 'Los stickers no pueden superar 500 KB'
            : 'El archivo supera el límite de WhatsApp para su tipo']);
        exit;
    }

    $mediaId = WhatsAppService::uploadMedia($tmp, $mime);
    if (!$mediaId) {
        echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo subir']); exit;
    }

    // Los stickers no admiten pie de foto: si se manda, Meta rechaza el envío
    $pie  = $tipo === 'sticker' ? '' : trim($_POST['texto'] ?? '');
    $waId = WhatsAppService::sendMedia($conv['telefono'], $tipo, $mediaId, $pie ?: null, $nombre, $conv['phone_number_id']);

    $convId = WaInbox::registrarSaliente(
        $pdo, $conv['telefono'], $waId, $tipo, $pie ?: null,
        null, $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
    );

    // Guardar el media_id para poder mostrarlo también en nuestro hilo
    if ($waId) {
        $pdo->prepare("UPDATE wa_mensajes SET media_id = ?, media_mime = ?, media_nombre = ?
                        WHERE wa_message_id = ?")->execute([$mediaId, $mime, $nombre, $waId]);
    }

    if ($tempConvertido) @unlink($tempConvertido);

    echo $waId
        ? json_encode(['ok' => true, 'aviso' => $aviso])
        : json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
    exit;
}

// ══ Reaccionar a un mensaje del cliente ══════════════════════════════════════
if ($accion === 'reaccionar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $convId = (int) ($_POST['id'] ?? 0);
    $msgId  = (int) ($_POST['mensaje_id'] ?? 0);
    $emoji  = trim($_POST['emoji'] ?? '');   // vacío = quitar
    if (!$convId || !$msgId) { echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']); exit; }

    $c = $pdo->prepare("SELECT telefono, asignado_a, ventana_expira_at FROM wa_conversaciones WHERE id = ?");
    $c->execute([$convId]);
    $conv = $c->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }
    if ((int) $conv['asignado_a'] !== $yo) { echo json_encode(['ok' => false, 'msg' => 'Toma la conversación primero']); exit; }
    if (!WaInbox::ventanaAbierta($conv['ventana_expira_at'])) {
        echo json_encode(['ok' => false, 'msg' => 'La ventana de 24 h venció']); exit;
    }

    // Solo se puede reaccionar a un mensaje ENTRANTE (uno del cliente)
    $m = $pdo->prepare("SELECT wa_message_id FROM wa_mensajes WHERE id = ? AND conversacion_id = ? AND direccion = 'in'");
    $m->execute([$msgId, $convId]);
    $waId = $m->fetchColumn();
    if (!$waId) { echo json_encode(['ok' => false, 'msg' => 'No se puede reaccionar a ese mensaje']); exit; }

    if (WhatsAppService::reaccionar($conv['telefono'], $waId, $emoji) !== null) {
        $pdo->prepare("UPDATE wa_mensajes SET reaccion = ? WHERE id = ?")->execute([$emoji ?: null, $msgId]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo reaccionar']);
    }
    exit;
}

// ══ Enviar un documento del cliente (propuesta / certificado) por WhatsApp ═══
if ($accion === 'enviar_documento') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $id      = (int) ($_POST['id'] ?? 0);
    $tipoDoc = $_POST['doc_tipo'] ?? '';
    $docId   = (int) ($_POST['doc_id'] ?? 0);
    if (!$id || !$docId || !in_array($tipoDoc, ['cotizacion', 'certificado'], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']); exit;
    }

    $q = $pdo->prepare("SELECT telefono, asignado_a, ventana_expira_at, phone_number_id, cliente_id
                          FROM wa_conversaciones WHERE id = ?");
    $q->execute([$id]);
    $conv = $q->fetch(PDO::FETCH_ASSOC);
    if (!$conv)                                    { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }
    if (!$conv['cliente_id'])                      { echo json_encode(['ok' => false, 'msg' => 'La conversación no está vinculada a una empresa']); exit; }
    if ((int) $conv['asignado_a'] !== $yo)         { echo json_encode(['ok' => false, 'msg' => 'Toma la conversación para poder enviar']); exit; }
    if (!WaInbox::ventanaAbierta($conv['ventana_expira_at'])) {
        echo json_encode(['ok' => false, 'msg' => 'La ventana de 24 h venció: solo se pueden enviar plantillas.']); exit;
    }

    // Resolver el archivo verificando que pertenece al cliente de la conversación
    if ($tipoDoc === 'cotizacion') {
        $d = $pdo->prepare("SELECT archivo_pdf AS arch, numero FROM cotizaciones
                             WHERE id = ? AND cliente_id = ? AND deleted_at IS NULL");
        $d->execute([$docId, $conv['cliente_id']]);
        $doc = $d->fetch(PDO::FETCH_ASSOC);
        $nombre = $doc ? ('Propuesta ' . $doc['numero'] . '.pdf') : 'Propuesta.pdf';
    } else {
        $d = $pdo->prepare("SELECT certificado_archivo AS arch, numero_certificado FROM certificados
                             WHERE id = ? AND cliente_id = ? AND deleted_at IS NULL");
        $d->execute([$docId, $conv['cliente_id']]);
        $doc = $d->fetch(PDO::FETCH_ASSOC);
        $nombre = ($doc && $doc['numero_certificado']) ? ('Certificado ' . $doc['numero_certificado'] . '.pdf') : 'Certificado.pdf';
    }
    if (!$doc || empty($doc['arch'])) { echo json_encode(['ok' => false, 'msg' => 'Ese documento no tiene un archivo PDF cargado.']); exit; }

    // Ruta local segura (dentro de UPLOAD_PATH; evita path traversal)
    $ruta = realpath(rtrim(UPLOAD_PATH, '/') . '/' . ltrim($doc['arch'], '/'));
    $base = realpath(UPLOAD_PATH);
    if (!$ruta || !$base || !str_starts_with($ruta, $base) || !is_readable($ruta)) {
        echo json_encode(['ok' => false, 'msg' => 'No se encontró el archivo del documento.']); exit;
    }

    $mime = mime_content_type($ruta) ?: 'application/pdf';
    $mediaId = WhatsAppService::uploadMedia($ruta, $mime);
    if (!$mediaId) { echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo subir el archivo']); exit; }

    $pie  = trim($_POST['texto'] ?? '') ?: null;
    $waId = WhatsAppService::sendMedia($conv['telefono'], 'document', $mediaId, $pie, $nombre, $conv['phone_number_id']);

    WaInbox::registrarSaliente(
        $pdo, $conv['telefono'], $waId, 'document', $pie,
        null, $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
    );
    if ($waId) {
        $pdo->prepare("UPDATE wa_mensajes SET media_id = ?, media_mime = ?, media_nombre = ? WHERE wa_message_id = ?")
            ->execute([$mediaId, $mime, $nombre, $waId]);
    }

    echo $waId
        ? json_encode(['ok' => true, 'msg' => 'Documento enviado'])
        : json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
    exit;
}

// ══ Marcar como no leída (manual) ════════════════════════════════════════════
if ($accion === 'sin_leer') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }

    // Marca manual: se limpia sola cuando llega un mensaje nuevo o se abre el hilo
    $pdo->prepare("UPDATE wa_conversaciones SET sin_leer_manual = 1 WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ══ Fijar / archivar / silenciar una conversación (toggle) ══════════════════
if ($accion === 'conv_flag') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();

    $id    = (int) ($_POST['id'] ?? 0);
    $campo = $_POST['campo'] ?? '';
    $valor = !empty($_POST['valor']) ? 1 : 0;
    if (!$id || !in_array($campo, ['fijada', 'archivada', 'silenciada'], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']); exit;
    }
    // Solo columnas de la lista blanca llegan a la consulta (no es interpolación de usuario)
    $pdo->prepare("UPDATE wa_conversaciones SET {$campo} = ? WHERE id = ?")->execute([$valor, $id]);
    echo json_encode(['ok' => true, 'campo' => $campo, 'valor' => $valor]);
    exit;
}

// ══ Quitar la marca 🔥 Lead caliente ═════════════════════════════════════════
// El cliente pasa a "lead caliente" (lead_caliente_at) al pulsar "Quiero
// aprovecharlo" (WaInbox::marcarLeadCaliente), lo que además fija la conversación.
// Esta acción permite retirar esa marca cuando ya se atendió: limpia lead_caliente_at
// y desfija (la fijación fue automática al marcar el lead). No borra el historial.
if ($accion === 'quitar_lead') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }
    $pdo->prepare("UPDATE wa_conversaciones SET lead_caliente_at = NULL, fijada = 0 WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ══ Llamadas: sondeo de llamada entrante pendiente (softphone) ═══════════════
// Devuelve el último evento "connect" reciente que aún no fue contestado ni
// terminado, con el SDP offer para que el navegador arme el answer (WebRTC).
if ($accion === 'llamada_pendiente') {
    $vis = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
    $wNum = ''; $args = [];
    if ($vis !== null && $vis) {
        $wNum = ' AND l.phone_number_id IN (' . implode(',', array_fill(0, count($vis), '?')) . ')';
        $args = $vis;
    }
    // connect de los últimos 60 s sin un terminate posterior para el mismo call
    // Solo llamadas ENTRANTES: se excluye BUSINESS_INITIATED (el connect de una
    // llamada saliente nuestra trae el SDP answer y no debe sonar como entrante).
    $sql = "SELECT l.wa_call_id, l.telefono, l.phone_number_id, l.conversacion_id, l.payload_json, l.created_at
              FROM wa_llamadas l
             WHERE l.evento = 'connect'
               AND (l.direccion IS NULL OR l.direccion <> 'BUSINESS_INITIATED')
               AND l.created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
               AND NOT EXISTS (SELECT 1 FROM wa_llamadas t
                                WHERE t.wa_call_id = l.wa_call_id
                                  AND t.evento IN ('terminate','reject') AND t.id > l.id)
               $wNum
             ORDER BY l.id DESC LIMIT 1";
    $stp = $pdo->prepare($sql);
    $stp->execute($args);
    $row = $stp->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok' => true, 'llamada' => null]); exit; }

    $pl  = json_decode($row['payload_json'] ?? '{}', true) ?: [];
    $sdp = $pl['session']['sdp'] ?? null;
    // Nombre del contacto si lo tenemos en la conversación / cliente
    $nombre = '+' . $row['telefono'];
    if ($row['conversacion_id']) {
        $q = $pdo->prepare("SELECT COALESCE(cl.razon_social, CONCAT('+', c.telefono)) n
                              FROM wa_conversaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                             WHERE c.id = ?");
        $q->execute([$row['conversacion_id']]);
        $nombre = $q->fetchColumn() ?: $nombre;
    }
    echo json_encode(['ok' => true, 'llamada' => [
        'call_id'         => $row['wa_call_id'],
        'telefono'        => $row['telefono'],
        'phone_number_id' => $row['phone_number_id'],
        'conversacion_id' => $row['conversacion_id'] ? (int) $row['conversacion_id'] : null,
        'nombre'          => $nombre,
        'sdp'             => $sdp,
    ]]);
    exit;
}

// ══ Llamadas: ¿la llamada en curso ya terminó? (para colgar si el otro cortó) ═
if ($accion === 'llamada_estado') {
    $callId = trim($_GET['call_id'] ?? '');
    if (!$callId) { echo json_encode(['ok' => false]); exit; }
    $q = $pdo->prepare("SELECT 1 FROM wa_llamadas
                         WHERE wa_call_id = ? AND evento IN ('terminate','reject') LIMIT 1");
    $q->execute([$callId]);
    echo json_encode(['ok' => true, 'terminada' => (bool) $q->fetchColumn()]);
    exit;
}

// ══ Llamadas: pedir PERMISO para llamar (mensaje interactivo, no plantilla) ═══
// Meta exige permiso para llamar en frío. Se envía dentro de la ventana de 24 h;
// límite 1 por 24 h y 2 por semana. Al aceptar el cliente, se puede llamar.
if ($accion === 'llamada_permiso') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $convId = (int) ($_POST['id'] ?? 0);
    $texto  = trim($_POST['texto'] ?? '');
    if (!$convId) { echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']); exit; }
    if ($texto === '') $texto = 'Nos gustaría llamarte para ayudarte con tu certificación. ¿Nos autorizas la llamada?';

    $c = $pdo->prepare("SELECT telefono, phone_number_id FROM wa_conversaciones WHERE id = ?");
    $c->execute([$convId]);
    $conv = $c->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }

    $waId = WhatsAppService::sendInteractive($conv['telefono'], [
        'type'   => 'call_permission_request',
        'action' => ['name' => 'call_permission_request'],
        'body'   => ['text' => mb_substr($texto, 0, 1024)],
    ], $conv['phone_number_id']);
    if (!$waId) { echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar la solicitud']); exit; }

    WaInbox::registrarSaliente($pdo, $conv['telefono'], $waId, 'text',
        '🔔 Solicitud de permiso para llamar enviada', null, $yo, 'sent', null, $conv['phone_number_id']);
    echo json_encode(['ok' => true]);
    exit;
}

// ══ Llamadas: INICIAR una llamada saliente (business-initiated) ═════════════
if ($accion === 'llamada_iniciar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $convId = (int) ($_POST['id'] ?? 0);
    $sdp    = $_POST['sdp'] ?? '';
    if (!$convId || !$sdp) { echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']); exit; }

    $c = $pdo->prepare("SELECT telefono, phone_number_id, asignado_a FROM wa_conversaciones WHERE id = ?");
    $c->execute([$convId]);
    $conv = $c->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }
    // Debe tenerla a cargo (o ser jefe) para llamar
    if (!$esJefe && (int) $conv['asignado_a'] !== $yo) {
        echo json_encode(['ok' => false, 'msg' => 'Toma la conversación primero']); exit;
    }

    $r = WhatsAppService::llamadaIniciar($conv['phone_number_id'], $conv['telefono'], $sdp);
    if (!$r['ok'] || !$r['call_id']) {
        echo json_encode(['ok' => false, 'msg' => $r['error'] ?: 'No se pudo iniciar la llamada']); exit;
    }
    // Entrada saliente en el hilo + bitácora
    $pdo->prepare("INSERT INTO wa_llamadas (wa_call_id, conversacion_id, phone_number_id, telefono, direccion, evento, estado)
                   VALUES (?, ?, ?, ?, 'BUSINESS_INITIATED', 'iniciar', 'ok')")
        ->execute([$r['call_id'], $convId, $conv['phone_number_id'], $conv['telefono']]);
    // Mensaje "Llamada de voz" saliente en el hilo
    WaInbox::registrarSaliente($pdo, $conv['telefono'], $r['call_id'], 'call', 'call:ringing', null, $yo, 'sent', null, $conv['phone_number_id']);

    echo json_encode(['ok' => true, 'call_id' => $r['call_id'], 'phone_number_id' => $conv['phone_number_id']]);
    exit;
}

// ══ Llamadas: obtener el SDP answer que Meta manda por webhook ══════════════
if ($accion === 'llamada_answer') {
    $callId = trim($_GET['call_id'] ?? '');
    if (!$callId) { echo json_encode(['ok' => false]); exit; }
    // Buscar en la bitácora el evento con session.sdp_type = answer
    $q = $pdo->prepare("SELECT payload_json FROM wa_llamadas
                         WHERE wa_call_id = ? AND payload_json IS NOT NULL ORDER BY id DESC LIMIT 10");
    $q->execute([$callId]);
    $sdp = null;
    foreach ($q as $row) {
        $pl = json_decode($row['payload_json'] ?? '{}', true) ?: [];
        if (($pl['session']['sdp_type'] ?? '') === 'answer' && !empty($pl['session']['sdp'])) {
            $sdp = $pl['session']['sdp']; break;
        }
    }
    // ¿ya terminó?
    $t = $pdo->prepare("SELECT 1 FROM wa_llamadas WHERE wa_call_id = ? AND evento IN ('terminate','reject') LIMIT 1");
    $t->execute([$callId]);
    echo json_encode(['ok' => true, 'sdp' => $sdp, 'terminada' => (bool) $t->fetchColumn()]);
    exit;
}

// ══ Llamadas: contestar / rechazar / colgar (proxy a la Graph API) ══════════
if ($accion === 'llamada_accion') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $callId  = trim($_POST['call_id'] ?? '');
    $phoneId = trim($_POST['phone_number_id'] ?? '');
    $act     = $_POST['accion_llamada'] ?? '';
    $sdp     = $_POST['sdp'] ?? null;
    if (!$callId || !$phoneId || !in_array($act, ['pre_accept', 'accept', 'reject', 'terminate'], true)) {
        echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']); exit;
    }

    $r = WhatsAppService::llamadaAccion($phoneId, $callId, $act, $sdp);
    // Registrar el cambio de estado en nuestra bitácora
    $pdo->prepare("INSERT INTO wa_llamadas (wa_call_id, phone_number_id, evento, estado)
                   VALUES (?, ?, ?, ?)")
        ->execute([$callId, $phoneId, $act, $r['ok'] ? 'ok' : 'error']);

    echo json_encode(['ok' => $r['ok'], 'msg' => $r['error'] ?? null]);
    exit;
}

// ══ Enviar mensaje interactivo (botones o lista) ════════════════════════════
if ($accion === 'enviar_interactivo') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $id    = (int) ($_POST['id'] ?? 0);
    $sub   = $_POST['subtipo'] ?? '';   // 'button' | 'list' | 'cta_url'
    $cuerpo = trim($_POST['cuerpo'] ?? '');
    $pie    = trim($_POST['pie'] ?? '');
    $header = trim($_POST['header'] ?? '');

    if (!$id || $cuerpo === '') { echo json_encode(['ok' => false, 'msg' => 'Falta el mensaje']); exit; }

    $c = $pdo->prepare("SELECT telefono, asignado_a, ventana_expira_at, phone_number_id FROM wa_conversaciones WHERE id = ?");
    $c->execute([$id]);
    $conv = $c->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }
    if ((int) $conv['asignado_a'] !== $yo) { echo json_encode(['ok' => false, 'msg' => 'Toma la conversación primero']); exit; }
    if (!WaInbox::ventanaAbierta($conv['ventana_expira_at'])) {
        echo json_encode(['ok' => false, 'msg' => 'La ventana de 24 h venció: solo se pueden enviar plantillas']); exit;
    }

    $interactive = ['body' => ['text' => mb_substr($cuerpo, 0, 1024)]];
    if ($pie !== '')    $interactive['footer'] = ['text' => mb_substr($pie, 0, 60)];
    if ($header !== '') $interactive['header'] = ['type' => 'text', 'text' => mb_substr($header, 0, 60)];

    // Resumen legible del interactivo para guardarlo en el hilo
    $resumen = $cuerpo;

    if ($sub === 'button') {
        // Hasta 3 botones de respuesta rápida; título máx. 20 caracteres
        $botones = array_values(array_filter(array_map('trim', (array) ($_POST['botones'] ?? [])), fn($b) => $b !== ''));
        if (!$botones) { echo json_encode(['ok' => false, 'msg' => 'Agrega al menos un botón']); exit; }
        if (count($botones) > 3) $botones = array_slice($botones, 0, 3);

        $interactive['type']   = 'button';
        $interactive['action'] = ['buttons' => array_map(fn($t, $i) => [
            'type'  => 'reply',
            'reply' => ['id' => 'btn_' . ($i + 1), 'title' => mb_substr($t, 0, 20)],
        ], $botones, array_keys($botones))];

        $resumen .= "\n[Botones: " . implode(' · ', $botones) . ']';

    } elseif ($sub === 'list') {
        // Una sección con opciones; título de fila máx. 24 caracteres
        $boton    = trim($_POST['boton_lista'] ?? '') ?: 'Ver opciones';
        $opciones = array_values(array_filter(array_map('trim', (array) ($_POST['opciones'] ?? [])), fn($o) => $o !== ''));
        if (!$opciones) { echo json_encode(['ok' => false, 'msg' => 'Agrega al menos una opción a la lista']); exit; }
        if (count($opciones) > 10) $opciones = array_slice($opciones, 0, 10);

        $interactive['type']   = 'list';
        $interactive['action'] = [
            'button'   => mb_substr($boton, 0, 20),
            'sections' => [[
                'title' => 'Opciones',
                'rows'  => array_map(fn($o, $i) => [
                    'id'    => 'opt_' . ($i + 1),
                    'title' => mb_substr($o, 0, 24),
                ], $opciones, array_keys($opciones)),
            ]],
        ];

        $resumen .= "\n[Lista: " . implode(' · ', $opciones) . ']';

    } elseif ($sub === 'cta_url') {
        // Botón que abre una URL
        $txt = trim($_POST['cta_texto'] ?? '') ?: 'Abrir enlace';
        $url = trim($_POST['cta_url'] ?? '');
        if (!filter_var($url, FILTER_VALIDATE_URL)) { echo json_encode(['ok' => false, 'msg' => 'La URL no es válida']); exit; }

        $interactive['type']   = 'cta_url';
        $interactive['action'] = ['name' => 'cta_url', 'parameters' => [
            'display_text' => mb_substr($txt, 0, 20),
            'url'          => $url,
        ]];

        $resumen .= "\n[Enlace: {$txt} → {$url}]";

    } elseif ($sub === 'contact') {
        // Tarjeta de contacto: no es 'interactive', va por su propio tipo
        $nom = trim($_POST['ct_nombre'] ?? '');
        $tel = trim($_POST['ct_telefono'] ?? '');
        $emp = trim($_POST['ct_empresa'] ?? '');
        if ($nom === '' || $tel === '') { echo json_encode(['ok' => false, 'msg' => 'Nombre y teléfono son obligatorios']); exit; }

        $waId = WhatsAppService::sendContacto($conv['telefono'], $nom, $tel, $emp, $conv['phone_number_id']);
        WaInbox::registrarSaliente(
            $pdo, $conv['telefono'], $waId, 'contacts', '👤 ' . $nom . ' · ' . $tel,
            null, $yo, $waId ? 'sent' : 'failed',
            $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
        );
        echo $waId ? json_encode(['ok' => true])
                   : json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
        exit;

    } else {
        echo json_encode(['ok' => false, 'msg' => 'Tipo de mensaje inválido']); exit;
    }

    $waId = WhatsAppService::sendInteractive($conv['telefono'], $interactive, $conv['phone_number_id']);

    WaInbox::registrarSaliente(
        $pdo, $conv['telefono'], $waId, 'interactive', $resumen,
        null, $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
    );

    echo $waId
        ? json_encode(['ok' => true])
        : json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
    exit;
}

// ══ Enviar una UBICACIÓN (pin en el mapa) ════════════════════════════════════
if ($accion === 'enviar_ubicacion') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $id   = (int) ($_POST['id'] ?? 0);
    $lat  = isset($_POST['lat']) ? (float) $_POST['lat'] : null;
    $lng  = isset($_POST['lng']) ? (float) $_POST['lng'] : null;
    $nom  = trim($_POST['nombre'] ?? '');
    $dir  = trim($_POST['direccion'] ?? '');

    if (!$id || $lat === null || $lng === null) { echo json_encode(['ok' => false, 'msg' => 'Faltan las coordenadas']); exit; }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        echo json_encode(['ok' => false, 'msg' => 'Coordenadas fuera de rango']); exit;
    }

    $c = $pdo->prepare("SELECT telefono, asignado_a, ventana_expira_at, phone_number_id FROM wa_conversaciones WHERE id = ?");
    $c->execute([$id]);
    $conv = $c->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }
    if ((int) $conv['asignado_a'] !== $yo) { echo json_encode(['ok' => false, 'msg' => 'Toma la conversación primero']); exit; }
    if (!WaInbox::ventanaAbierta($conv['ventana_expira_at'])) {
        echo json_encode(['ok' => false, 'msg' => 'La ventana de 24 h venció: solo se pueden enviar plantillas']); exit;
    }

    $waId = WhatsAppService::sendLocation($conv['telefono'], $lat, $lng, $nom, $dir, $conv['phone_number_id']);
    $preview = '📍 ' . ($nom !== '' ? $nom : ($dir !== '' ? $dir : 'Ubicación'));
    WaInbox::registrarSaliente(
        $pdo, $conv['telefono'], $waId, 'location', $preview,
        null, $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
    );
    echo $waId ? json_encode(['ok' => true])
               : json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
    exit;
}

// ══ Enviar mensaje ═══════════════════════════════════════════════════════════
// ── Copiloto IA: sugerir una respuesta (no envía nada, solo devuelve texto) ──
if ($accion === 'ia_sugerir') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();
    require_once __DIR__ . '/../../core/IaAgente.php';

    if (!IaAgente::disponible()) {
        echo json_encode(['ok' => false, 'msg' => 'El copiloto de IA no está configurado.']); exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Conversación inválida']); exit; }

    $q = $pdo->prepare("SELECT c.telefono, c.cliente_id, COALESCE(cl.razon_social, CONCAT('+', c.telefono)) AS titulo
                          FROM wa_conversaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                         WHERE c.id = ?");
    $q->execute([$id]);
    $conv = $q->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }

    // Hilo reciente (solo lo necesario para redactar)
    $mq = $pdo->prepare("SELECT direccion AS dir, tipo, texto FROM wa_mensajes
                          WHERE conversacion_id = ? ORDER BY id DESC LIMIT 30");
    $mq->execute([$id]);
    $mensajes = array_reverse($mq->fetchAll(PDO::FETCH_ASSOC) ?: []);

    $ctx      = IaAgente::contextoCliente($pdo, $conv['cliente_id'] ? (int) $conv['cliente_id'] : null);
    $catalogo = IaAgente::catalogoNormas($pdo);
    $r        = IaAgente::sugerir($mensajes, $ctx, (string) $conv['titulo'], $catalogo);

    echo json_encode($r['ok']
        ? ['ok' => true, 'texto' => $r['texto']]
        : ['ok' => false, 'msg' => $r['error'] ?: 'No se pudo generar la sugerencia.']);
    exit;
}

// ── Notas internas (privadas del equipo; NUNCA se envían al cliente) ─────────
if ($accion === 'nota_crear') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    $id    = (int) ($_POST['id'] ?? 0);
    $texto = trim($_POST['texto'] ?? '');
    if (!$id)          { echo json_encode(['ok' => false, 'msg' => 'Conversación inválida']); exit; }
    if ($texto === '') { echo json_encode(['ok' => false, 'msg' => 'Escribe la nota']); exit; }
    $texto = mb_substr($texto, 0, 2000);
    $ok = $pdo->query("SELECT id FROM wa_conversaciones WHERE id = " . $id)->fetchColumn();
    if (!$ok) { echo json_encode(['ok' => false, 'msg' => 'Conversación no encontrada']); exit; }
    $pdo->prepare("INSERT INTO wa_conversacion_notas (conversacion_id, usuario_id, nota) VALUES (?,?,?)")
        ->execute([$id, $yo, $texto]);
    $nid = (int) $pdo->lastInsertId();
    $autor = trim(($_SESSION['usuario']['nombre'] ?? '') . ' ' . ($_SESSION['usuario']['apellido'] ?? ''));
    echo json_encode(['ok' => true, 'nota' => [
        'id' => $nid, 'nota' => $texto, 'autor' => $autor,
        'usuario_id' => $yo, 'created_at' => date('Y-m-d H:i:s'),
    ]]);
    exit;
}

if ($accion === 'nota_borrar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    $nid = (int) ($_POST['nota_id'] ?? 0);
    if (!$nid) { echo json_encode(['ok' => false, 'msg' => 'Nota inválida']); exit; }
    // Solo el autor o gerencia pueden borrar
    $q = $pdo->prepare("SELECT usuario_id FROM wa_conversacion_notas WHERE id = ?");
    $q->execute([$nid]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Nota no encontrada']); exit; }
    if ((int) $row['usuario_id'] !== $yo && !$esJefe) {
        echo json_encode(['ok' => false, 'msg' => 'Solo el autor o gerencia pueden borrar la nota']); exit;
    }
    $pdo->prepare("DELETE FROM wa_conversacion_notas WHERE id = ?")->execute([$nid]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Respuestas rápidas (canned) compartidas por el equipo ────────────────────
if ($accion === 'respuestas') {
    $q = $pdo->query("SELECT id, atajo, texto FROM wa_respuestas_rapidas WHERE activo = 1 ORDER BY atajo");
    echo json_encode(['ok' => true, 'items' => $q->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    exit;
}

if ($accion === 'respuesta_guardar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    $rid   = (int) ($_POST['rr_id'] ?? 0);
    $atajo = trim($_POST['atajo'] ?? '');
    $texto = trim($_POST['texto'] ?? '');
    $atajo = preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower($atajo));
    if ($atajo === '' || $texto === '') { echo json_encode(['ok' => false, 'msg' => 'Atajo y texto son obligatorios']); exit; }
    $atajo = mb_substr($atajo, 0, 40);
    $texto = mb_substr($texto, 0, 2000);
    try {
        if ($rid) {
            $pdo->prepare("UPDATE wa_respuestas_rapidas SET atajo = ?, texto = ? WHERE id = ?")
                ->execute([$atajo, $texto, $rid]);
        } else {
            $pdo->prepare("INSERT INTO wa_respuestas_rapidas (atajo, texto, creado_por) VALUES (?,?,?)")
                ->execute([$atajo, $texto, $yo]);
            $rid = (int) $pdo->lastInsertId();
        }
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => 'Ese atajo ya existe. Usa otro nombre.']); exit;
    }
    echo json_encode(['ok' => true, 'id' => $rid, 'atajo' => $atajo, 'texto' => $texto]);
    exit;
}

if ($accion === 'respuesta_borrar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();
    $rid = (int) ($_POST['rr_id'] ?? 0);
    if (!$rid) { echo json_encode(['ok' => false, 'msg' => 'Respuesta inválida']); exit; }
    $pdo->prepare("UPDATE wa_respuestas_rapidas SET activo = 0 WHERE id = ?")->execute([$rid]);
    echo json_encode(['ok' => true]);
    exit;
}

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

    // Responder citando otro mensaje: se valida que sea de esta conversación
    $respWaId = null;
    $respMsg  = (int) ($_POST['responder_a'] ?? 0);
    if ($respMsg) {
        $rq = $pdo->prepare("SELECT wa_message_id FROM wa_mensajes WHERE id = ? AND conversacion_id = ?");
        $rq->execute([$respMsg, $id]);
        $respWaId = $rq->fetchColumn() ?: null;
    }

    $waId = WhatsAppService::sendText($conv['telefono'], $texto, $respWaId, $conv['phone_number_id']);

    $nuevoId = WaInbox::registrarSaliente(
        $pdo, $conv['telefono'], $waId, 'text', $texto,
        null, $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $conv['phone_number_id']
    );

    // Guardar a qué mensaje respondió, para dibujar la cita en el hilo
    if ($waId && $respWaId) {
        $pdo->prepare("UPDATE wa_mensajes SET responde_a = ? WHERE wa_message_id = ?")
            ->execute([$respWaId, $waId]);
    }

    if (!$waId) {
        echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo enviar']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ══ Reenviar un mensaje a OTRA conversación ══════════════════════════════════
if ($accion === 'reenviar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit;
    }
    csrf_verify();
    require_once __DIR__ . '/../../core/WhatsAppService.php';

    $mid     = (int) ($_POST['mensaje_id'] ?? 0);
    $destino = (int) ($_POST['destino'] ?? 0);
    if (!$mid || !$destino) { echo json_encode(['ok' => false, 'msg' => 'Faltan datos']); exit; }

    // Mensaje origen + bandeja a la que pertenece (para validar acceso)
    $sq = $pdo->prepare(
        "SELECT m.id, m.tipo, m.texto, m.media_id, m.media_path, m.media_mime, m.media_nombre,
                c.phone_number_id AS src_pnid
           FROM wa_mensajes m
           JOIN wa_conversaciones c ON c.id = m.conversacion_id
          WHERE m.id = ?"
    );
    $sq->execute([$mid]);
    $src = $sq->fetch(PDO::FETCH_ASSOC);
    if (!$src) { echo json_encode(['ok' => false, 'msg' => 'Mensaje no encontrado']); exit; }

    $vis = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
    if ($vis !== null && !in_array($src['src_pnid'], $vis, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Sin acceso a ese mensaje']); exit;
    }

    // Conversación destino
    $tq = $pdo->prepare("SELECT telefono, asignado_a, ventana_expira_at, phone_number_id
                           FROM wa_conversaciones WHERE id = ?");
    $tq->execute([$destino]);
    $dst = $tq->fetch(PDO::FETCH_ASSOC);
    if (!$dst) { echo json_encode(['ok' => false, 'msg' => 'Conversación destino no encontrada']); exit; }
    if ($vis !== null && !in_array($dst['phone_number_id'], $vis, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Sin acceso a esa bandeja']); exit;
    }
    // Mismo candado que al responder: solo escribe quien la tiene asignada.
    if ((int) $dst['asignado_a'] !== $yo) {
        echo json_encode(['ok' => false, 'msg' => $dst['asignado_a']
            ? 'La conversación destino está asignada a otro ejecutivo'
            : 'Asígnate la conversación destino para reenviar']);
        exit;
    }
    if (!WaInbox::ventanaAbierta($dst['ventana_expira_at'])) {
        echo json_encode(['ok' => false, 'msg' => 'La ventana de 24 h del destino venció: solo se pueden enviar plantillas']);
        exit;
    }

    $tipo  = $src['tipo'] ?: 'text';
    $texto = (string) ($src['texto'] ?? '');
    $mediaTipos = ['image', 'video', 'audio', 'document', 'sticker'];

    $waId = null; $regTipo = 'text'; $regTexto = null; $regMedia = null; $nuevoMediaId = null;

    if (in_array($tipo, $mediaTipos, true)) {
        // Recuperar el archivo original en disco (igual que media.php: A/B/C)
        $dir  = __DIR__ . '/../../storage/wa_media';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ruta = null; $relPath = null;
        $root = realpath(__DIR__ . '/../../');
        if (!empty($src['media_path']) && strpos($src['media_path'], '/') !== false) {
            $cand = realpath($root . '/' . ltrim($src['media_path'], '/'));
            if ($cand && strpos($cand, $root . DIRECTORY_SEPARATOR) === 0 && is_file($cand)) {
                $ruta = $cand; $relPath = $src['media_path'];
            }
        }
        if (!$ruta && !empty($src['media_path'])) {
            $r2 = $dir . '/' . basename($src['media_path']);
            if (is_file($r2)) { $ruta = $r2; $relPath = basename($src['media_path']); }
        }
        if (!$ruta && !empty($src['media_id'])) {
            $bin = WhatsAppService::fetchMedia((string) $src['media_id']);
            if ($bin) {
                $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',
                        'video/mp4'=>'mp4','audio/ogg'=>'ogg','audio/mpeg'=>'mp3','application/pdf'=>'pdf'][$bin['mime']] ?? 'bin';
                $nombreArch = 'wa_' . $src['id'] . '_' . substr(sha1((string) $src['media_id']), 0, 10) . '.' . $ext;
                if (@file_put_contents($dir . '/' . $nombreArch, $bin['contenido']) !== false) {
                    $ruta = $dir . '/' . $nombreArch; $relPath = $nombreArch;
                    if (empty($src['media_mime'])) $src['media_mime'] = $bin['mime'];
                    // cachear en el mensaje origen para la próxima
                    $pdo->prepare("UPDATE wa_mensajes SET media_path = ? WHERE id = ?")->execute([$nombreArch, $src['id']]);
                }
            }
        }
        if (!$ruta) { echo json_encode(['ok' => false, 'msg' => 'No se pudo recuperar el archivo original']); exit; }

        $mime   = $src['media_mime'] ?: (mime_content_type($ruta) ?: 'application/octet-stream');
        $nombre = $src['media_nombre'] ?: basename($ruta);
        $nuevoMediaId = WhatsAppService::uploadMedia($ruta, $mime);
        if (!$nuevoMediaId) {
            echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo subir el archivo']); exit;
        }
        $pie  = $tipo === 'sticker' ? '' : (trim($texto) !== '' ? $texto : null);
        $waId = WhatsAppService::sendMedia($dst['telefono'], $tipo, $nuevoMediaId, $pie ?: null, $nombre, $dst['phone_number_id']);
        $regTipo = $tipo; $regTexto = $pie ?: null;
        $regMedia = ['path' => $relPath, 'mime' => $mime, 'nombre' => $nombre];
    } else {
        // Texto (o cualquier tipo sin media pero con texto: se reenvía el texto)
        if (trim($texto) === '') { echo json_encode(['ok' => false, 'msg' => 'Este mensaje no se puede reenviar']); exit; }
        if (mb_strlen($texto) > 4096) $texto = mb_substr($texto, 0, 4096);
        $waId = WhatsAppService::sendText($dst['telefono'], $texto, null, $dst['phone_number_id']);
        $regTipo = 'text'; $regTexto = $texto;
    }

    $nuevoId = WaInbox::registrarSaliente(
        $pdo, $dst['telefono'], $waId, $regTipo, $regTexto,
        null, $yo, $waId ? 'sent' : 'failed',
        $waId ? null : WhatsAppService::lastError(), $dst['phone_number_id'], $regMedia
    );

    if ($waId) {
        // Marcar como reenviado y guardar el media_id para renderizarlo en el hilo
        $pdo->prepare("UPDATE wa_mensajes SET reenviado = 1" . ($nuevoMediaId ? ", media_id = ?" : "") . " WHERE wa_message_id = ?")
            ->execute($nuevoMediaId ? [$nuevoMediaId, $waId] : [$waId]);
    } else {
        echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo reenviar']); exit;
    }

    echo json_encode(['ok' => true, 'destino' => $destino]);
    exit;
}

// ══ Ping de mensajes nuevos (para notificar en vivo) ═════════════════════════
// Devuelve los mensajes ENTRANTES con id > since dentro de las bandejas visibles.
// Muy barato: filtra por PK (id) indexada. Sin `since` → solo el max id actual
// (línea base al abrir el inbox, para no notificar todo el historial).
if ($accion === 'ping') {
    $vis = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
    if ($vis !== null && !$vis) { echo json_encode(['ok' => true, 'nuevos' => [], 'max_id' => 0]); exit; }
    $visWhere = ''; $visArgs = [];
    if ($vis !== null) {
        $visWhere = ' AND c.phone_number_id IN (' . implode(',', array_fill(0, count($vis), '?')) . ')';
        $visArgs  = $vis;
    }
    $since = (isset($_GET['since']) && $_GET['since'] !== '') ? (int) $_GET['since'] : null;

    if ($since === null) {
        $q = $pdo->prepare("SELECT COALESCE(MAX(m.id),0) FROM wa_mensajes m
                             JOIN wa_conversaciones c ON c.id = m.conversacion_id
                            WHERE m.direccion = 'in'$visWhere");
        $q->execute($visArgs);
        echo json_encode(['ok' => true, 'nuevos' => [], 'max_id' => (int) $q->fetchColumn()]);
        exit;
    }

    $st = $pdo->prepare(
        "SELECT m.id, m.conversacion_id AS conv_id, m.texto, m.tipo, c.telefono,
                COALESCE(cl.razon_social, CONCAT('+', c.telefono)) AS contacto
           FROM wa_mensajes m
           JOIN wa_conversaciones c ON c.id = m.conversacion_id
           LEFT JOIN clientes cl ON cl.id = c.cliente_id
          WHERE m.id > ? AND m.direccion = 'in'$visWhere
          ORDER BY m.id ASC LIMIT 20"
    );
    $st->execute(array_merge([$since], $visArgs));

    $ico = ['image' => '🖼️ Imagen', 'audio' => '🎤 Audio', 'voice' => '🎤 Audio',
            'video' => '🎬 Video', 'document' => '📎 Documento', 'sticker' => 'Sticker',
            'location' => '📍 Ubicación'];
    $nuevos = []; $maxId = $since;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $maxId = max($maxId, (int) $r['id']);
        $txt = trim((string) $r['texto']);
        $prev = $txt !== '' ? mb_substr($txt, 0, 80) : ($ico[$r['tipo']] ?? 'Nuevo mensaje');
        $nuevos[] = ['id' => (int) $r['id'], 'conv_id' => (int) $r['conv_id'],
                     'tel' => $r['telefono'], 'contacto' => $r['contacto'], 'preview' => $prev];
    }
    echo json_encode(['ok' => true, 'nuevos' => $nuevos, 'max_id' => $maxId], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══ Listado ══════════════════════════════════════════════════════════════════
if ($accion === 'lista') {
    $filtro = $_GET['filtro'] ?? 'todas';
    $buscar = trim($_GET['q'] ?? '');

    $where = ["c.estado <> 'resuelta'"];
    $args  = [];

    // Visibilidad por número: un ejecutivo solo ve las bandejas habilitadas
    $vis = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
    if ($vis !== null) {
        if (!$vis) { echo json_encode(['ok' => true, 'items' => [], 'conteos' => [], 'bandejas' => []]); exit; }
        $where[] = 'c.phone_number_id IN (' . implode(',', array_fill(0, count($vis), '?')) . ')';
        $args    = array_merge($args, $vis);
    }

    // Depurar bandejas fantasma: ocultar conversaciones de números que ya no
    // existen en el módulo de números (wa_numeros). Aplica a todos (gerencia
    // incluida, que de otro modo veía el phone_number_id crudo como bandeja).
    $where[] = 'EXISTS (SELECT 1 FROM wa_numeros wn WHERE wn.phone_number_id = c.phone_number_id)';

    // Bandeja elegida: dividir el inbox por número. '' = todas las visibles.
    $bandeja = trim($_GET['bandeja'] ?? '');
    if ($bandeja !== '') {
        $where[] = 'c.phone_number_id = ?';
        $args[]  = $bandeja;
    }

    if ($filtro === 'mias')            { $where[] = 'c.asignado_a = ?'; $args[] = $yo; }
    elseif ($filtro === 'sin_asignar') { $where[] = 'c.asignado_a IS NULL'; }
    // "Sin persona": el número no está vinculado a ninguna empresa del sistema
    elseif ($filtro === 'sin_persona') { $where[] = 'c.cliente_id IS NULL'; }
    // "Sin responder": el último mensaje es del cliente y la conversación sigue abierta
    elseif ($filtro === 'sin_responder') {
        $where[] = "c.estado = 'abierta' AND (SELECT m.direccion FROM wa_mensajes m
                     WHERE m.conversacion_id = c.id ORDER BY m.id DESC LIMIT 1) = 'in'";
    }
    // Etapa del embudo: filtro "etapa:<code>"
    elseif (str_starts_with($filtro, 'etapa:')) {
        $et = substr($filtro, 6);
        if (in_array($et, GG_ETAPAS, true)) {
            $where[] = gg_etapa_case('c.cliente_id') . ' = ?';
            $args[]  = $et;
        }
    }

    // Archivadas: viven fuera del listado principal. Solo la vista "archivadas"
    // las muestra; cualquier otro filtro las oculta.
    if ($filtro === 'archivadas') { $where[] = 'c.archivada = 1'; }
    else                          { $where[] = 'c.archivada = 0'; }

    // Filtro por etiquetas: acepta varias (lista separada por comas). Una
    // conversación entra si tiene CUALQUIERA de las etiquetas seleccionadas.
    $etqs = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['etiqueta'] ?? '')))));
    if ($etqs) {
        $ph = implode(',', array_fill(0, count($etqs), '?'));
        $where[] = "EXISTS (SELECT 1 FROM wa_conversacion_etiquetas x
                             WHERE x.conversacion_id = c.id AND x.etiqueta_id IN ($ph))";
        foreach ($etqs as $id) $args[] = $id;
    }

    if ($buscar !== '') {
        // Búsqueda global: teléfono, empresa, último preview y — lo nuevo — el
        // contenido de CUALQUIER mensaje del hilo (EXISTS sobre wa_mensajes).
        $where[] = '(c.telefono LIKE ? OR cl.razon_social LIKE ? OR c.ultimo_mensaje_preview LIKE ?'
                 . ' OR EXISTS (SELECT 1 FROM wa_mensajes m WHERE m.conversacion_id = c.id AND m.texto LIKE ?))';
        $like = '%' . $buscar . '%';
        array_push($args, $like, $like, $like, $like);
    }

    $st = $pdo->prepare(
        "SELECT c.id, c.telefono, c.cliente_id, c.asignado_a, c.asignado_at, c.no_leidos, c.sin_leer_manual,
                c.fijada, c.archivada, c.silenciada, c.lead_caliente_at,
                c.ventana_expira_at, c.ultimo_mensaje_at, c.ultimo_mensaje_preview, c.estado, cl.razon_social,
                (SELECT m.direccion FROM wa_mensajes m WHERE m.conversacion_id = c.id ORDER BY m.id DESC LIMIT 1) AS ultima_dir,
                " . gg_etapa_case('c.cliente_id') . " AS etapa,
                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS asignado_nombre
           FROM wa_conversaciones c
           LEFT JOIN clientes cl ON cl.id = c.cliente_id
           LEFT JOIN usuarios u  ON u.id  = c.asignado_a
          WHERE " . implode(' AND ', $where) . "
          ORDER BY (c.lead_caliente_at IS NOT NULL) DESC, c.fijada DESC, c.ultimo_mensaje_at DESC LIMIT 100"
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
            'sin_leer'  => (bool) $r['sin_leer_manual'],
            'fijada'     => (bool) $r['fijada'],
            'archivada'  => (bool) $r['archivada'],
            'silenciada' => (bool) $r['silenciada'],
            'lead'       => !empty($r['lead_caliente_at']),  // 🔥 pulsó "Quiero aprovecharlo"
            'asignado_a'      => $r['asignado_a'] ? (int) $r['asignado_a'] : null,
            'asignado_nombre' => $r['asignado_nombre'] ?: null,
            'es_mia'          => ((int) $r['asignado_a']) === $yo,
            // Sin liberación automática, una conversación estancada debe notarse
            'dias_asignada'   => $r['asignado_at'] ? (int) floor((time() - strtotime($r['asignado_at'])) / 86400) : null,
            // "Esperando respuesta": el cliente escribió último y sigue abierta.
            // horas_espera alimenta el resaltado (rojo si lleva mucho sin respuesta).
            'esperando'   => ($r['ultima_dir'] === 'in' && $r['estado'] === 'abierta'),
            'horas_espera'=> ($r['ultima_dir'] === 'in' && $r['ultimo_mensaje_at'])
                              ? (int) floor((time() - strtotime($r['ultimo_mensaje_at'])) / 3600) : null,
            'etapa'       => $r['etapa'],
            'ventana_abierta' => WaInbox::ventanaAbierta($r['ventana_expira_at']),
            'etiquetas'       => $etqPorConv[$r['id']] ?? [],
        ];
    }

    $wCont = "estado <> 'resuelta'";
    $aCont = [$yo];
    if ($vis !== null) {
        $wCont .= ' AND phone_number_id IN (' . implode(',', array_fill(0, count($vis), '?')) . ')';
        $aCont  = array_merge($aCont, $vis);
    }
    // Mismo depurado que el listado: excluir números que ya no existen en wa_numeros
    // (forma IN por subconsulta: válida tanto con la tabla sin alias como con alias 'c').
    $wCont .= ' AND phone_number_id IN (SELECT phone_number_id FROM wa_numeros)';
    $c = $pdo->prepare(
        "SELECT COUNT(*) todas, SUM(asignado_a = ?) mias,
                SUM(asignado_a IS NULL) sin_asignar, SUM(cliente_id IS NULL) sin_persona,
                SUM(estado = 'abierta' AND archivada = 0 AND
                    (SELECT m.direccion FROM wa_mensajes m
                      WHERE m.conversacion_id = wa_conversaciones.id ORDER BY m.id DESC LIMIT 1) = 'in'
                   ) sin_responder
           FROM wa_conversaciones WHERE $wCont"
    );
    $c->execute($aCont);
    $cn = $c->fetch(PDO::FETCH_ASSOC) ?: [];

    // Conteos por etapa del embudo (solo conversaciones activas, no archivadas)
    $eq = $pdo->prepare(
        "SELECT etapa, COUNT(*) n FROM (
            SELECT " . gg_etapa_case('c.cliente_id') . " AS etapa
              FROM wa_conversaciones c WHERE $wCont AND c.archivada = 0
         ) t WHERE etapa IS NOT NULL GROUP BY etapa"
    );
    // $wCont usa el mismo binding de $aCont pero SIN el primer '?' (mias = $yo);
    // aquí no hay '= ?' de mias, así que se quita el primer arg.
    $eq->execute(array_slice($aCont, 1));
    $embudo = [];
    foreach ($eq as $r) $embudo[$r['etapa']] = (int) $r['n'];

    // Bandejas disponibles (números que el usuario puede ver), con su conteo.
    // Solo se ofrece el selector si hay más de una: con un número no aporta.
    $bWhere = ["c.estado <> 'resuelta'"];
    $bArgs  = [];
    if ($vis !== null) {
        $bWhere[] = 'c.phone_number_id IN (' . implode(',', array_fill(0, count($vis), '?')) . ')';
        $bArgs    = array_merge($bArgs, $vis);
    }
    $bq = $pdo->prepare(
        // INNER JOIN: solo bandejas de números que existen en wa_numeros. Las de
        // números eliminados (huérfanas) no se ofrecen en el selector.
        "SELECT c.phone_number_id, COUNT(*) n,
                COALESCE(NULLIF(n.etiqueta,''), n.telefono, c.phone_number_id) etiqueta,
                n.telefono
           FROM wa_conversaciones c
           JOIN wa_numeros n ON n.phone_number_id = c.phone_number_id
          WHERE " . implode(' AND ', $bWhere) . "
          GROUP BY c.phone_number_id
          ORDER BY n DESC"
    );
    $bq->execute($bArgs);
    $bandejas = $bq->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'items' => $items, 'bandejas' => $bandejas, 'conteos' => [
        'todas'       => (int) ($cn['todas'] ?? 0),
        'mias'        => (int) ($cn['mias'] ?? 0),
        'sin_asignar' => (int) ($cn['sin_asignar'] ?? 0),
        'sin_persona' => (int) ($cn['sin_persona'] ?? 0),
        'sin_responder' => (int) ($cn['sin_responder'] ?? 0),
    ], 'embudo' => $embudo]);
    exit;
}

// ══ Hilo + contexto ══════════════════════════════════════════════════════════
// ══ Marcar leído en WhatsApp (tildes azules) + "escribiendo…" ════════════════
// Fire-and-forget desde el frontend: al abrir un chat (sin typing) y al redactar
// (typing=1). Envía el read-receipt/typing del ÚLTIMO mensaje entrante a Meta.
if ($accion === 'leido') {
    $id     = (int) ($_GET['id'] ?? 0);
    $typing = !empty($_GET['typing']);
    if ($id) {
        $q = $pdo->prepare(
            "SELECT c.phone_number_id,
                    (SELECT m.wa_message_id FROM wa_mensajes m
                      WHERE m.conversacion_id = c.id AND m.direccion = 'in' AND m.wa_message_id IS NOT NULL
                      ORDER BY m.id DESC LIMIT 1) AS ult_in
               FROM wa_conversaciones c WHERE c.id = ?"
        );
        $q->execute([$id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['ult_in'])) {
            $vis = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
            if ($vis === null || in_array($r['phone_number_id'], $vis, true)) {
                require_once __DIR__ . '/../../core/WhatsAppService.php';
                try { WhatsAppService::markRead((string) $r['ult_in'], $typing, (string) $r['phone_number_id']); }
                catch (\Throwable $e) { /* best-effort */ }
            }
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ══ Buscar DENTRO de una conversación ════════════════════════════════════════
if ($accion === 'buscar_hilo') {
    $id = (int) ($_GET['id'] ?? 0);
    $q  = trim($_GET['q'] ?? '');
    if (!$id || mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'resultados' => []]); exit; }

    // Visibilidad: solo si el usuario puede ver esa bandeja.
    $cq = $pdo->prepare("SELECT phone_number_id FROM wa_conversaciones WHERE id = ?");
    $cq->execute([$id]);
    $pnid = $cq->fetchColumn();
    $vis  = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
    if ($pnid === false || ($vis !== null && !in_array($pnid, $vis, true))) {
        echo json_encode(['ok' => false, 'msg' => 'Sin acceso']); exit;
    }

    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $s = $pdo->prepare(
        "SELECT m.id, m.direccion AS dir, m.created_at, LEFT(m.texto, 140) AS snippet
           FROM wa_mensajes m
          WHERE m.conversacion_id = ? AND m.texto LIKE ? ESCAPE '\\\\'
          ORDER BY m.id DESC LIMIT 50"
    );
    $s->execute([$id, $like]);
    $res = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $res[] = ['id' => (int) $r['id'], 'dir' => $r['dir'], 'cuando' => $r['created_at'], 'texto' => $r['snippet']];
    }
    echo json_encode(['ok' => true, 'resultados' => $res], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'hilo') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }

    $st = $pdo->prepare(
        "SELECT c.*, cl.razon_social, cl.ruc, cl.contacto_nombre,
                " . gg_etapa_case('c.cliente_id') . " AS etapa,
                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS asignado_nombre
           FROM wa_conversaciones c
           LEFT JOIN clientes cl ON cl.id = c.cliente_id
           LEFT JOIN usuarios u  ON u.id  = c.asignado_a
          WHERE c.id = ?"
    );
    $st->execute([$id]);
    $conv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(['ok' => false, 'msg' => 'No encontrada']); exit; }

    // El filtro también acá: ocultar en la lista pero permitir abrir por id
    // dejaría la restricción como algo meramente visual.
    $vis = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
    if ($vis !== null && !in_array($conv['phone_number_id'], $vis, true)) {
        echo json_encode(['ok' => false, 'msg' => 'No tienes acceso a esa bandeja']); exit;
    }

    // Cargar los mensajes MÁS RECIENTES (no los más antiguos) y paginar hacia
    // atrás con `antes` (id del más viejo ya cargado). Antes se traían los 300
    // más ANTIGUOS en ASC → conversaciones largas (>300) ocultaban lo reciente.
    $antes = (int) ($_GET['antes'] ?? 0);
    $LIM_MSG = 300;
    $ms = $pdo->prepare(
        "SELECT m.id, m.wa_message_id, m.direccion, m.tipo, m.reenviado, m.reaccion, m.responde_a, m.texto, m.referral_json,
                (SELECT LEFT(r.texto, 90) FROM wa_mensajes r WHERE r.wa_message_id = m.responde_a LIMIT 1) AS cita_texto,
                (SELECT r.direccion FROM wa_mensajes r WHERE r.wa_message_id = m.responde_a LIMIT 1) AS cita_dir,
                m.template_name, m.estado, m.error_msg,
                m.enviado_por, m.desde_app, m.created_at, m.media_id, m.media_path, m.media_mime, m.media_nombre,
                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS autor
           FROM wa_mensajes m LEFT JOIN usuarios u ON u.id = m.enviado_por
          WHERE m.conversacion_id = ?" . ($antes > 0 ? " AND m.id < ?" : "") . "
          ORDER BY m.id DESC LIMIT " . ($LIM_MSG + 1)
    );
    $ms->execute($antes > 0 ? [$id, $antes] : [$id]);
    $rowsMsg  = $ms->fetchAll(PDO::FETCH_ASSOC);
    $hay_mas  = count($rowsMsg) > $LIM_MSG;        // ¿quedan mensajes más antiguos?
    if ($hay_mas) $rowsMsg = array_slice($rowsMsg, 0, $LIM_MSG);
    $rowsMsg  = array_reverse($rowsMsg);           // volver a ASC (viejo→nuevo) para render

    // Nombre del número (para atribuir los ecos de coexistencia enviados desde su app)
    $numLabel = '';
    if (!empty($conv['phone_number_id'])) {
        $nq = $pdo->prepare("SELECT COALESCE(NULLIF(etiqueta,''), telefono) FROM wa_numeros WHERE phone_number_id = ? LIMIT 1");
        $nq->execute([$conv['phone_number_id']]);
        $numLabel = (string) $nq->fetchColumn();
    }

    $mensajes = [];
    foreach ($rowsMsg as $m) {
        $mensajes[] = [
            'id'         => (int) $m['id'],
            'wa_id'      => $m['wa_message_id'],
            'tipo'       => $m['tipo'],
            'reenviado'  => (bool) $m['reenviado'],
            'reaccion'   => $m['reaccion'],
            'cita'       => $m['responde_a'] ? [
                'texto' => $m['cita_texto'] ?: '(mensaje)',
                'dir'   => $m['cita_dir'] ?: 'in',
            ] : null,
            'media'      => ($m['media_id'] || $m['media_path']) ? (int) $m['id'] : null,
            'media_mime' => $m['media_mime'],
            'media_nombre' => $m['media_nombre'],
            'dir'        => $m['direccion'],
            'texto'      => $m['texto'],
            'referral'   => $m['referral_json'] ? json_decode($m['referral_json'], true) : null,
            'estado'     => $m['estado'],
            'estado_txt' => wa_estado_label($m['estado']),
            'error'      => $m['error_msg'],
            // desde_app = eco de coexistencia (Frank/Andrés responden desde la app):
            // se atribuye al nombre del número, no a "Sistema · automático".
            'autor'      => !empty($m['desde_app'])
                                ? ($numLabel ?: 'Enviado desde WhatsApp')
                                : ($m['enviado_por'] ? ($m['autor'] ?: 'Ejecutivo') : null),
            'cuando'     => $m['created_at'],
        ];
    }

    $primer_id = $rowsMsg ? (int) $rowsMsg[0]['id'] : null; // id del más antiguo cargado

    // Paginación hacia atrás: al pedir mensajes más viejos solo se devuelven ellos
    // (sin recalcular contexto/notas, que no cambian). El frontend los antepone.
    if ($antes > 0) {
        echo json_encode(['ok' => true, 'mensajes' => $mensajes, 'hay_mas' => $hay_mas, 'primer_id' => $primer_id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ctx = ['propuestas' => [], 'envios' => [], 'certificados' => [], 'saldo' => null, 'fecha_requerida' => null, 'agenda' => []];
    if ($conv['cliente_id']) {
        $cid = (int) $conv['cliente_id'];

        $q = $pdo->prepare("SELECT id, numero, estado,
                                   (archivo_pdf IS NOT NULL AND archivo_pdf <> '') AS tiene_pdf
                              FROM cotizaciones
                             WHERE cliente_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 5");
        $q->execute([$cid]);
        $ctx['propuestas'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $q = $pdo->prepare("SELECT id, codigo_envio FROM envios
                             WHERE cliente_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 3");
        $q->execute([$cid]);
        $ctx['envios'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Certificados del cliente (norma + estado + vencimiento)
        $q = $pdo->prepare("SELECT c.id, n.codigo AS norma, c.estado, c.fecha_vencimiento,
                                   (c.certificado_archivo IS NOT NULL AND c.certificado_archivo <> '') AS tiene_pdf
                              FROM certificados c LEFT JOIN normas_iso n ON n.id = c.norma_iso_id
                             WHERE c.cliente_id = ? AND c.deleted_at IS NULL
                             ORDER BY c.id DESC LIMIT 6");
        $q->execute([$cid]);
        $ctx['certificados'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Saldo pendiente: pagos aún NO validados
        $q = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(monto),0) monto, MAX(moneda) moneda
                              FROM pagos_cliente
                             WHERE cliente_id = ? AND deleted_at IS NULL AND estado = 'pendiente'");
        $q->execute([$cid]);
        $sal = $q->fetch(PDO::FETCH_ASSOC);
        if ($sal && (int) $sal['n'] > 0) {
            $ctx['saldo'] = ['n' => (int) $sal['n'], 'monto' => (float) $sal['monto'], 'moneda' => $sal['moneda'] ?: 'S/'];
        }

        // Fecha requerida de certificados (de la propuesta aceptada más reciente)
        $q = $pdo->prepare("SELECT fecha_requerida_certificados FROM cotizaciones
                             WHERE cliente_id = ? AND deleted_at IS NULL AND fecha_requerida_certificados IS NOT NULL
                             ORDER BY id DESC LIMIT 1");
        $q->execute([$cid]);
        $ctx['fecha_requerida'] = $q->fetchColumn() ?: null;

        // Llamadas agendadas pendientes (mismo sistema del CRM)
        $q = $pdo->prepare("SELECT lp.id, lp.fecha_hora, lp.nota,
                                   TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS asignado
                              FROM llamadas_programadas lp LEFT JOIN usuarios u ON u.id = lp.usuario_id
                             WHERE lp.cliente_id = ? AND lp.estado IN ('pendiente','notificada')
                             ORDER BY lp.fecha_hora ASC LIMIT 5");
        $q->execute([$cid]);
        $ctx['agenda'] = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Notas internas del equipo (privadas, NO se envían al cliente)
    $qn = $pdo->prepare("SELECT nt.id, nt.nota, nt.created_at,
                                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS autor,
                                nt.usuario_id
                           FROM wa_conversacion_notas nt LEFT JOIN usuarios u ON u.id = nt.usuario_id
                          WHERE nt.conversacion_id = ? ORDER BY nt.id DESC LIMIT 50");
    $qn->execute([$id]);
    $ctx['notas'] = $qn->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $qe = $pdo->prepare("SELECT e.id, e.nombre, e.color
                           FROM wa_conversacion_etiquetas x
                           JOIN wa_etiquetas e ON e.id = x.etiqueta_id AND e.activo = 1
                          WHERE x.conversacion_id = ? ORDER BY e.nombre");
    $qe->execute([$id]);
    $etiquetas = $qe->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pdo->prepare("UPDATE wa_conversaciones SET no_leidos = 0, sin_leer_manual = 0 WHERE id = ?")->execute([$id]);

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
        'etapa'           => $conv['etapa'],
    ], 'mensajes' => $mensajes, 'hay_mas' => $hay_mas, 'primer_id' => $primer_id, 'contexto' => $ctx, 'etiquetas' => $etiquetas]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
