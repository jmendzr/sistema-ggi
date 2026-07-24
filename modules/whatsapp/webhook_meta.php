<?php
/**
 * modules/whatsapp/webhook_meta.php
 *
 * Receptor de webhooks de Meta WhatsApp Cloud API (nativo).
 *
 * Registrado en la app de Meta (Webhooks) apuntando a:
 *   https://sistema.groupglobaliso.com/modules/whatsapp/webhook_meta.php
 *
 * Eventos que procesa:
 *   - message.status → actualiza estado en whatsapp_log (sent/delivered/read/failed)
 *   - messages (entrantes) → guarda la respuesta del cliente en whatsapp_inbound
 *     (permite segmentar "clientes que no respondieron" en campañas)
 *
 * Verificación de webhook: Meta envía GET con hub.verify_token
 * Configurar en .env: WA_WEBHOOK_VERIFY_TOKEN=<token_aleatorio>
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WaInbox.php';
require_once __DIR__ . '/../../core/WaCampanas.php';

$verify_token = defined('WA_WEBHOOK_VERIFY_TOKEN') ? WA_WEBHOOK_VERIFY_TOKEN : env('WA_WEBHOOK_VERIFY_TOKEN', '');

// ── Verificación inicial de webhook (GET) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode  = $_GET['hub_mode']         ?? $_GET['hub.mode']         ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge']   ?? '';

    if ($mode === 'subscribe' && $verify_token && hash_equals($verify_token, (string) $token)) {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}

// ── Recibir eventos POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(200); exit; }

// ── Verificar firma HMAC-SHA256 del payload (X-Hub-Signature-256) ─────────────
// Meta firma cada POST con el App Secret. Sin esto, cualquiera que conozca la
// URL puede inyectar estados y mensajes falsos.
// Solo se exige con el proveedor nativo: los webhooks proxeados por Kapso no
// vienen firmados con NUESTRO app secret, así que hoy este bloque es no-op.
$_app_secret = defined('META_APP_SECRET') ? META_APP_SECRET : '';
$_es_meta    = strtolower(trim(defined('WA_PROVIDER') ? WA_PROVIDER : 'kapso')) === 'meta';

if ($_es_meta && $_app_secret !== '') {
    $recibida = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $esperada = 'sha256=' . hash_hmac('sha256', $raw, $_app_secret);

    if ($recibida === '' || !hash_equals($esperada, $recibida)) {
        error_log('[wa-webhook] firma invalida o ausente; payload descartado');
        http_response_code(401);
        exit;
    }
}

$data = json_decode($raw, true);
if (!$data) { http_response_code(200); exit; }

// Responder 200 inmediatamente para que Meta no reintente
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
flush();

// ── Procesar statuses ─────────────────────────────────────────────────────────
try {
    $pdo = Database::get();

    $entries = $data['entry'] ?? [];
    foreach ($entries as $entry) {
        $changes = $entry['changes'] ?? [];
        foreach ($changes as $change) {
            $value = $change['value'] ?? [];

            // Delivery/read statuses
            $statuses = $value['statuses'] ?? [];
            foreach ($statuses as $status) {
                $wa_id    = $status['id']     ?? null;   // message ID de WA
                $estado   = $status['status'] ?? null;   // sent|delivered|read|failed

                if (!$wa_id || !$estado) continue;

                // Mapeo de estados Meta → nuestros estados internos
                $map = [
                    'sent'      => 'sent',
                    'delivered' => 'delivered',
                    'read'      => 'read',
                    'failed'    => 'failed',
                ];
                $estado_interno = $map[$estado] ?? null;
                if (!$estado_interno) continue;

                $error_msg = null;
                if ($estado === 'failed') {
                    $errData   = $status['errors'][0] ?? [];
                    $error_msg = isset($errData['code'])
                        ? "Code {$errData['code']}: " . ($errData['title'] ?? $errData['message'] ?? 'error')
                        : 'failed';
                }

                // Hilo del inbox: se actualiza SIEMPRE, antes del corte de whatsapp_log.
                // Los mensajes escritos por un ejecutivo no existen en whatsapp_log
                // (esa tabla es solo de notificaciones automáticas).
                WaInbox::actualizarEstado($pdo, $wa_id, $estado_interno, $error_msg);

                // Métricas de campañas: sin esto, una campaña quedaría siempre
                // en "enviado" sin saber cuántos llegaron o se leyeron.
                WaCampanas::actualizarEstado($pdo, $wa_id, $estado_interno);

                // Actualizar solo si el nuevo estado es "más avanzado" que el actual
                $order = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
                $current = $pdo->prepare("SELECT estado FROM whatsapp_log WHERE wa_message_id = ?");
                $current->execute([$wa_id]);
                $row = $current->fetch(\PDO::FETCH_ASSOC);

                if (!$row) continue;  // no conocemos este mensaje

                $cur_order = $order[$row['estado']] ?? 0;
                $new_order = $order[$estado_interno] ?? 0;

                if ($new_order >= $cur_order) {
                    $pdo->prepare("
                        UPDATE whatsapp_log
                           SET estado = ?, error_msg = ?, updated_at = NOW()
                         WHERE wa_message_id = ?
                    ")->execute([$estado_interno, $error_msg, $wa_id]);
                }
            }

            // ── Mensajes entrantes (respuestas de clientes) ───────────────
            $messages = $value['messages'] ?? [];
            foreach ($messages as $msg) {
                $wa_id = $msg['id']   ?? null;
                $from  = preg_replace('/\D/', '', (string)($msg['from'] ?? ''));
                if (!$from) continue;

                $tipo  = $msg['type'] ?? 'text';

                // Reacción a un mensaje nuestro: no es un mensaje nuevo, es un
                // emoji sobre uno existente. Se guarda en ese mensaje.
                if ($tipo === 'reaction') {
                    $sobre = $msg['reaction']['message_id'] ?? null;
                    $emoji = $msg['reaction']['emoji'] ?? null;   // vacío = reacción quitada
                    if ($sobre) {
                        $pdo->prepare("UPDATE wa_mensajes SET reaccion = ? WHERE wa_message_id = ?")
                            ->execute([$emoji ?: null, $sobre]);
                    }
                    continue;
                }

                // Extraer texto según el tipo de mensaje
                $texto = match ($tipo) {
                    'text'        => $msg['text']['body']            ?? null,
                    'button'      => $msg['button']['text']          ?? null,
                    'interactive' => $msg['interactive']['button_reply']['title']
                                  ?? $msg['interactive']['list_reply']['title'] ?? null,
                    // Los adjuntos pueden traer pie de foto
                    'image', 'video', 'document' => $msg[$tipo]['caption'] ?? null,
                    default       => null,
                };

                // Adjuntos: Meta NO envía el archivo, solo un identificador.
                // Se guarda el id y la descarga se hace luego, bajo demanda:
                // la URL de descarga caduca en minutos, pero el id sirve ~30 días.
                $media = null;
                if (in_array($tipo, ['image', 'sticker', 'video', 'audio', 'document'], true)) {
                    $m = $msg[$tipo] ?? [];
                    if (!empty($m['id'])) {
                        $media = [
                            'id'     => $m['id'],
                            'mime'   => $m['mime_type'] ?? null,
                            'nombre' => $m['filename']  ?? null,
                        ];
                    }
                }

                // Meta marca los mensajes reenviados en context.forwarded
                $reenviado = !empty($msg['context']['forwarded'])
                          || !empty($msg['context']['frequently_forwarded']);

                // Resolver cliente por los últimos 9 dígitos (móvil Perú)
                $cliente_id = null;
                $last9 = substr($from, -9);
                if (strlen($last9) === 9) {
                    $cq = $pdo->prepare("
                        SELECT id FROM clientes
                        WHERE deleted_at IS NULL
                          AND REPLACE(REPLACE(REPLACE(COALESCE(contacto_telefono,''),' ',''),'+',''),'-','') LIKE ?
                        LIMIT 1
                    ");
                    $cq->execute(['%' . $last9]);
                    $cliente_id = (int)$cq->fetchColumn() ?: null;
                    // Fallback: contacto de cotización (persona que negoció la propuesta)
                    if (!$cliente_id) {
                        $cq = $pdo->prepare("
                            SELECT cliente_id FROM cotizaciones
                            WHERE deleted_at IS NULL AND cliente_id IS NOT NULL
                              AND REPLACE(REPLACE(REPLACE(COALESCE(contacto_telefono,''),' ',''),'+',''),'-','') LIKE ?
                            ORDER BY id DESC LIMIT 1
                        ");
                        $cq->execute(['%' . $last9]);
                        $cliente_id = (int)$cq->fetchColumn() ?: null;
                    }
                }

                // INSERT IGNORE: Meta puede reintentar el webhook (wa_message_id es UNIQUE)
                $pdo->prepare("
                    INSERT IGNORE INTO whatsapp_inbound (wa_message_id, telefono, cliente_id, tipo, texto)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$wa_id, $from, $cliente_id, $tipo, $texto]);

                // Hilo del inbox multiagente: crea/actualiza la conversación y
                // reabre la ventana de 24h de Meta.
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                WaInbox::registrarEntrante($pdo, $from, $wa_id, $tipo, $texto, $phoneNumberId, $media, $reenviado);
            }

            // ── Eventos de LLAMADA (Calling API) ──────────────────────────
            // Fase A: por ahora solo se REGISTRA el evento crudo para poder ver
            // su estructura real y construir el softphone contra datos reales.
            // Aún no se contesta la llamada (eso requiere el cliente WebRTC).
            $calls = $value['calls'] ?? [];
            foreach ($calls as $call) {
                try {
                    $callId  = $call['id']        ?? null;
                    $from    = preg_replace('/\D/', '', (string)($call['from'] ?? ''));
                    $evento  = $call['event']     ?? ($call['status'] ?? null);
                    $dir     = $call['direction'] ?? null;
                    $estado  = $call['status']    ?? null;
                    $dur     = isset($call['duration']) ? (int) $call['duration'] : null;
                    $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                    // Vincular con la conversación del inbox si existe
                    $convId = null;
                    if ($from) {
                        $q = $pdo->prepare("SELECT id FROM wa_conversaciones WHERE telefono = ? ORDER BY id DESC LIMIT 1");
                        $q->execute([$from]);
                        $convId = $q->fetchColumn() ?: null;
                    }

                    $pdo->prepare(
                        "INSERT INTO wa_llamadas
                            (wa_call_id, conversacion_id, phone_number_id, telefono, direccion, evento, estado, duracion_seg, payload_json)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    )->execute([
                        $callId, $convId, $phoneNumberId, $from ?: null, $dir, $evento, $estado, $dur,
                        json_encode($call, JSON_UNESCAPED_UNICODE)
                    ]);

                    // Al iniciarse la llamada (connect), dejar una entrada en el
                    // hilo del inbox. El "texto" guarda un token de estado que el
                    // inbox renderiza (call:ringing → luego answered/missed/rejected).
                    // Solo las llamadas ENTRANTES (user-initiated) crean entrada
                    // en el hilo y push. El connect BUSINESS_INITIATED es el SDP
                    // answer de una llamada que iniciamos nosotros — no es entrante.
                    if ($evento === 'connect' && $from && $dir !== 'BUSINESS_INITIATED') {
                        WaInbox::registrarEntrante($pdo, $from, $callId, 'call', 'call:ringing', $phoneNumberId, null, false);

                        // Push a quien puede atender: el asignado (si hay) + gerencia,
                        // para enterarse de la llamada aunque no tengan el inbox abierto.
                        try {
                            require_once __DIR__ . '/../../core/PushService.php';
                            $nombre = '+' . $from; $asignado = null;
                            if ($convId) {
                                $qn = $pdo->prepare("SELECT COALESCE(cl.razon_social, CONCAT('+', c.telefono)) n, c.asignado_a
                                                       FROM wa_conversaciones c LEFT JOIN clientes cl ON cl.id = c.cliente_id
                                                      WHERE c.id = ?");
                                $qn->execute([$convId]);
                                if ($rw = $qn->fetch(\PDO::FETCH_ASSOC)) { $nombre = $rw['n']; $asignado = $rw['asignado_a']; }
                            }
                            $dest = [];
                            if ($asignado) $dest[] = (int) $asignado;
                            foreach ($pdo->query("SELECT id FROM usuarios WHERE rol IN ('gerente_general','gerente_operaciones') AND activo = 1") as $u)
                                $dest[] = (int) $u['id'];
                            $urlInbox = (defined('APP_URL') ? APP_URL : '') . '/modules/inbox/index.php';
                            foreach (array_unique($dest) as $uid)
                                PushService::notify($uid, '📞 Llamada de WhatsApp', $nombre . ' te está llamando', $urlInbox, 'wa-call-' . $callId);
                        } catch (\Throwable $pe) {
                            error_log('[WhatsApp Webhook] push llamada: ' . $pe->getMessage());
                        }
                    }

                    // Al terminar/rechazar: actualizar la entrada del hilo con el
                    // resultado y la duración, para verlo como WhatsApp.
                    if (($evento === 'terminate' || $evento === 'reject') && $callId) {
                        $st = strtoupper((string) $estado);
                        if ($evento === 'reject' || $st === 'REJECTED')        $tok = 'call:rejected';
                        elseif ($st === 'COMPLETED' || ($dur !== null && $dur > 0)) $tok = 'call:answered:' . (int) $dur;
                        elseif ($st === 'FAILED' || $st === 'ERROR')           $tok = 'call:failed';
                        else                                                   $tok = 'call:missed';

                        $pdo->prepare("UPDATE wa_mensajes SET texto = ? WHERE wa_message_id = ? AND tipo = 'call'")
                            ->execute([$tok, $callId]);

                        // Preview del listado según el resultado
                        if ($convId) {
                            $prev = $tok === 'call:rejected' ? '📞 Llamada rechazada'
                                  : (strpos($tok, 'answered') !== false ? '📞 Llamada de voz'
                                  : ($tok === 'call:failed' ? '📞 Llamada fallida' : '📞 Llamada perdida'));
                            $pdo->prepare("UPDATE wa_conversaciones SET ultimo_mensaje_preview = ? WHERE id = ?")
                                ->execute([$prev, $convId]);
                        }
                    }

                    // Traza inmediata para inspeccionar la primera llamada real
                    error_log('[WhatsApp Webhook] CALL event=' . $evento . ' dir=' . $dir
                            . ' from=' . $from . ' id=' . $callId);
                } catch (\Throwable $ce) {
                    error_log('[WhatsApp Webhook] Error registrando llamada: ' . $ce->getMessage());
                }
            }
        }
    }
} catch (\Throwable $e) {
    error_log('[WhatsApp Webhook] Error: ' . $e->getMessage());
}
