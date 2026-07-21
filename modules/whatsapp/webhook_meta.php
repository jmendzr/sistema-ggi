<?php
/**
 * modules/whatsapp/webhook_meta.php
 *
 * Receptor de webhooks de Meta WhatsApp Cloud API (vía Kapso proxy).
 *
 * Registrar en Meta Business Manager (o en Kapso) apuntando a:
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
                // Extraer texto según el tipo de mensaje
                $texto = match ($tipo) {
                    'text'        => $msg['text']['body']            ?? null,
                    'button'      => $msg['button']['text']          ?? null,
                    'interactive' => $msg['interactive']['button_reply']['title']
                                  ?? $msg['interactive']['list_reply']['title'] ?? null,
                    default       => null,  // image/document/audio/etc: solo se registra el tipo
                };

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
            }
        }
    }
} catch (\Throwable $e) {
    error_log('[WhatsApp Webhook] Error: ' . $e->getMessage());
}
