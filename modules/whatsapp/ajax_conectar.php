<?php
/**
 * ajax_conectar.php — Alta de número por Embedded Signup / Coexistencia.
 *
 * Recibe del flujo de Meta (Embedded Signup) el waba_id y el phone_number_id,
 * suscribe nuestra app a esa WABA (para recibir el webhook) y da de alta el
 * número en wa_numeros para que aparezca como bandeja en el inbox.
 *
 * La app debe estar Live y con la Revisión aprobada para que funcione con
 * números reales (mientras esté en desarrollo, solo con admins/probadores).
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');
header('Content-Type: application/json; charset=utf-8');

$pdo = Database::get();
$ver = META_WA_VERSION;
$tok = META_WA_TOKEN;

function gg_api($method, $url, $tok, $body = null) {
    $ch = curl_init($url);
    $h = ['Authorization: Bearer ' . $tok, 'Accept: application/json'];
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) { $opt[CURLOPT_POSTFIELDS] = json_encode($body); $h[] = 'Content-Type: application/json'; }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$c, json_decode((string) $r, true) ?: []];
}

$accion = $_REQUEST['accion'] ?? '';

if ($accion === 'finalizar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();

    $wabaId  = preg_replace('/\D/', '', $_POST['waba_id'] ?? '');
    $phoneId = preg_replace('/\D/', '', $_POST['phone_number_id'] ?? '');
    if (!$wabaId || !$phoneId) {
        echo json_encode(['ok' => false, 'msg' => 'El flujo no devolvió los identificadores del número']); exit;
    }

    // 1) Suscribir NUESTRA app a esa WABA, para recibir los mensajes por webhook.
    [$sc, $sr] = gg_api('POST', "https://graph.facebook.com/{$ver}/{$wabaId}/subscribed_apps", $tok);
    if ($sc < 200 || $sc >= 300) {
        echo json_encode(['ok' => false, 'msg' => 'No se pudo suscribir la app a la cuenta: '
            . ($sr['error']['message'] ?? ('HTTP ' . $sc))]); exit;
    }

    // 2) Consultar el número (nombre, teléfono, calidad) con nuestro token.
    [$nc, $num] = gg_api('GET', "https://graph.facebook.com/{$ver}/{$phoneId}"
        . '?fields=id,display_phone_number,verified_name,quality_rating,status,platform_type', $tok);
    if ($nc !== 200 || empty($num['id'])) {
        echo json_encode(['ok' => false, 'msg' => 'La app se suscribió, pero aún no se puede leer el número '
            . '(puede tardar unos minutos, o requiere que la Revisión de Meta esté aprobada).']); exit;
    }

    // 2.5) REGISTRAR el número en Cloud API. Un número NATIVO recién agregado queda
    //      "Pendiente" (no CONNECTED) y NO envía/recibe hasta registrarlo con el PIN
    //      de verificación en dos pasos. Los de COEXISTENCIA ya vienen conectados →
    //      se omiten. Best-effort: si falla, no se aborta el alta pero se informa.
    $registro  = null;
    $estadoNum = strtoupper((string)($num['status'] ?? ''));
    // Registrar SIEMPRE que el número no esté ya CONNECTED. Los de coexistencia
    // llegan como CONNECTED → se omiten solos. Un número NATIVO recién agregado
    // llega PENDING (platform_type "NOT_APPLICABLE" / "CLOUD_API") y necesita
    // /register. Best-effort: si falla, no aborta el alta pero se informa.
    if ($estadoNum !== 'CONNECTED') {
        $pin = defined('META_WA_REGISTER_PIN') ? preg_replace('/\D/', '', (string)META_WA_REGISTER_PIN) : '';
        if (strlen($pin) === 6) {
            [$rc, $rr] = gg_api('POST', "https://graph.facebook.com/{$ver}/{$phoneId}/register", $tok, [
                'messaging_product' => 'whatsapp',
                'pin'               => $pin,
            ]);
            if ($rc >= 200 && $rc < 300 && !empty($rr['success'])) {
                $registro = 'ok';
                // Releer el estado ya CONNECTED tras registrar.
                [$nc2, $num2] = gg_api('GET', "https://graph.facebook.com/{$ver}/{$phoneId}?fields=status", $tok);
                if ($nc2 === 200 && !empty($num2['status'])) $num['status'] = $num2['status'];
            } else {
                $registro = 'error: ' . ($rr['error']['message'] ?? ('HTTP ' . $rc));
            }
        } else {
            $registro = 'sin_pin';
        }
    }

    // Mensaje según el resultado del registro.
    $regMsg = '';
    if ($registro === 'sin_pin')                 $regMsg = ' Falta registrarlo en Cloud API: define META_WA_REGISTER_PIN (6 dígitos) en .env y vuelve a conectar.';
    elseif (is_string($registro) && strpos($registro, 'error') === 0) $regMsg = ' No se pudo registrar en Cloud API (' . substr($registro, 7) . '); revisa el PIN de verificación en dos pasos.';

    // 3) Alta en wa_numeros (idempotente).
    $ya = $pdo->prepare("SELECT id FROM wa_numeros WHERE phone_number_id = ?");
    $ya->execute([$phoneId]);
    if ($ya->fetchColumn()) {
        // Actualizar el estado (pudo pasar a CONNECTED tras registrar).
        $pdo->prepare("UPDATE wa_numeros SET estado = ? WHERE phone_number_id = ?")
            ->execute([$num['status'] ?? null, $phoneId]);
        echo json_encode(['ok' => true, 'numero' => $num['display_phone_number'] ?? $phoneId, 'ya' => true,
                          'registro' => $registro, 'msg_extra' => $regMsg]); exit;
    }
    $pdo->prepare(
        "INSERT INTO wa_numeros
            (phone_number_id, waba_id, etiqueta, telefono, verified_name, calidad, estado, verificado_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute([
        $phoneId, $wabaId, $num['verified_name'] ?? null, $num['display_phone_number'] ?? null,
        $num['verified_name'] ?? null, $num['quality_rating'] ?? null, $num['status'] ?? null,
    ]);

    echo json_encode(['ok' => true, 'numero' => $num['display_phone_number'] ?? $phoneId,
                      'nombre' => $num['verified_name'] ?? '', 'coex' => ($num['platform_type'] ?? ''),
                      'registro' => $registro, 'msg_extra' => $regMsg]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida']);
