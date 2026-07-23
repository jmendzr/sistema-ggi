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

    $t = $pdo->prepare("SELECT template_name, language_code, param_keys, body_text, url_button_key
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

    $waId = WhatsAppService::sendTemplate(
        $conv['telefono'], $tpl['template_name'], $vals, $tpl['language_code'],
        null, null, $boton !== '' ? $boton : null
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

    $waId = WhatsAppService::sendMedia($conv['telefono'], 'sticker', (string) $mediaId);

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
    $waId = WhatsAppService::sendMedia($conv['telefono'], $tipo, $mediaId, $pie ?: null, $nombre);

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

    } else {
        echo json_encode(['ok' => false, 'msg' => 'Tipo de mensaje inválido']); exit;
    }

    $waId = WhatsAppService::sendInteractive($conv['telefono'], $interactive);

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

    // Visibilidad por número: un ejecutivo solo ve las bandejas habilitadas
    $vis = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
    if ($vis !== null) {
        if (!$vis) { echo json_encode(['ok' => true, 'items' => [], 'conteos' => [], 'bandejas' => []]); exit; }
        $where[] = 'c.phone_number_id IN (' . implode(',', array_fill(0, count($vis), '?')) . ')';
        $args    = array_merge($args, $vis);
    }

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
        "SELECT c.id, c.telefono, c.cliente_id, c.asignado_a, c.asignado_at, c.no_leidos, c.sin_leer_manual,
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
            'sin_leer'  => (bool) $r['sin_leer_manual'],
            'asignado_a'      => $r['asignado_a'] ? (int) $r['asignado_a'] : null,
            'asignado_nombre' => $r['asignado_nombre'] ?: null,
            'es_mia'          => ((int) $r['asignado_a']) === $yo,
            // Sin liberación automática, una conversación estancada debe notarse
            'dias_asignada'   => $r['asignado_at'] ? (int) floor((time() - strtotime($r['asignado_at'])) / 86400) : null,
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
    $c = $pdo->prepare(
        "SELECT COUNT(*) todas, SUM(asignado_a = ?) mias,
                SUM(asignado_a IS NULL) sin_asignar, SUM(cliente_id IS NULL) sin_persona
           FROM wa_conversaciones WHERE $wCont"
    );
    $c->execute($aCont);
    $cn = $c->fetch(PDO::FETCH_ASSOC) ?: [];

    // Bandejas disponibles (números que el usuario puede ver), con su conteo.
    // Solo se ofrece el selector si hay más de una: con un número no aporta.
    $bWhere = ["c.estado <> 'resuelta'"];
    $bArgs  = [];
    if ($vis !== null) {
        $bWhere[] = 'c.phone_number_id IN (' . implode(',', array_fill(0, count($vis), '?')) . ')';
        $bArgs    = array_merge($bArgs, $vis);
    }
    $bq = $pdo->prepare(
        "SELECT c.phone_number_id, COUNT(*) n,
                COALESCE(NULLIF(n.etiqueta,''), n.telefono, c.phone_number_id) etiqueta,
                n.telefono
           FROM wa_conversaciones c
           LEFT JOIN wa_numeros n ON n.phone_number_id = c.phone_number_id
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

    // El filtro también acá: ocultar en la lista pero permitir abrir por id
    // dejaría la restricción como algo meramente visual.
    $vis = WaInbox::numerosVisibles($pdo, $yo, $esJefe);
    if ($vis !== null && !in_array($conv['phone_number_id'], $vis, true)) {
        echo json_encode(['ok' => false, 'msg' => 'No tienes acceso a esa bandeja']); exit;
    }

    $ms = $pdo->prepare(
        "SELECT m.id, m.wa_message_id, m.direccion, m.tipo, m.reenviado, m.reaccion, m.texto,
                m.template_name, m.estado, m.error_msg,
                m.enviado_por, m.created_at, m.media_id, m.media_path, m.media_mime, m.media_nombre,
                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS autor
           FROM wa_mensajes m LEFT JOIN usuarios u ON u.id = m.enviado_por
          WHERE m.conversacion_id = ? ORDER BY m.id ASC LIMIT 300"
    );
    $ms->execute([$id]);

    $mensajes = [];
    foreach ($ms as $m) {
        $mensajes[] = [
            'id'         => (int) $m['id'],
            'wa_id'      => $m['wa_message_id'],
            'tipo'       => $m['tipo'],
            'reenviado'  => (bool) $m['reenviado'],
            'reaccion'   => $m['reaccion'],
            'media'      => ($m['media_id'] || $m['media_path']) ? (int) $m['id'] : null,
            'media_mime' => $m['media_mime'],
            'media_nombre' => $m['media_nombre'],
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
    ], 'mensajes' => $mensajes, 'contexto' => $ctx, 'etiquetas' => $etiquetas]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
