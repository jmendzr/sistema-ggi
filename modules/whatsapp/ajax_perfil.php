<?php
/**
 * ajax_perfil.php — Leer y actualizar el perfil de negocio de WhatsApp.
 *
 * Usa el token actual (scope whatsapp_business_management, ya disponible).
 * La foto va por el "resumable upload" de Meta para obtener el handle.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');
header('Content-Type: application/json; charset=utf-8');

$accion = $_REQUEST['accion'] ?? 'leer';

// Número sobre el que se opera (multi-número). Vacío = el número por defecto.
$phoneId = preg_replace('/\D/', '', $_REQUEST['phone_number_id'] ?? '') ?: null;

if ($accion === 'leer') {
    $p = WhatsAppService::perfilLeer($phoneId);
    if ($p === null) { echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo leer el perfil']); exit; }
    echo json_encode(['ok' => true, 'perfil' => $p]);
    exit;
}

if ($accion === 'guardar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();

    // Verticales válidas de Meta
    $verticales = ['UNDEFINED','OTHER','AUTO','BEAUTY','APPAREL','EDU','ENTERTAIN','EVENT_PLAN',
        'FINANCE','GROCERY','GOVT','HOTEL','HEALTH','NONPROFIT','PROF_SERVICES','RETAIL',
        'TRAVEL','RESTAURANT','ALCOHOL','ONLINE_GAMBLING','PHYSICAL_GAMBLING','OTC_DRUGS'];

    $campos = [];
    // about: máx 139; description: máx 512; address: máx 256; email: máx 128
    $about = trim($_POST['about'] ?? '');
    $campos['about']       = mb_substr($about, 0, 139);
    $campos['description'] = mb_substr(trim($_POST['description'] ?? ''), 0, 512);
    $campos['address']     = mb_substr(trim($_POST['address'] ?? ''), 0, 256);
    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'msg' => 'El correo no es válido']); exit;
    }
    $campos['email'] = $email;
    $vert = $_POST['vertical'] ?? '';
    if (in_array($vert, $verticales, true)) $campos['vertical'] = $vert;

    // websites: hasta 2, deben empezar con http(s)
    $webs = [];
    foreach ([$_POST['web1'] ?? '', $_POST['web2'] ?? ''] as $w) {
        $w = trim($w);
        if ($w === '') continue;
        if (!preg_match('~^https?://~i', $w)) $w = 'https://' . $w;
        if (filter_var($w, FILTER_VALIDATE_URL)) $webs[] = $w;
    }
    $campos['websites'] = $webs;

    $ok = WhatsAppService::perfilActualizar($campos, $phoneId);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo guardar']);
    exit;
}

if ($accion === 'foto') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'msg' => 'Método no permitido']); exit; }
    csrf_verify();

    if (empty($_FILES['foto']['tmp_name'])) { echo json_encode(['ok' => false, 'msg' => 'No se recibió la imagen']); exit; }
    $tmp  = $_FILES['foto']['tmp_name'];
    $mime = mime_content_type($tmp) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        echo json_encode(['ok' => false, 'msg' => 'La foto debe ser JPG o PNG']); exit;
    }
    if (filesize($tmp) > 5 * 1024 * 1024) { echo json_encode(['ok' => false, 'msg' => 'La foto supera 5 MB']); exit; }

    $handle = WhatsAppService::perfilSubirFoto($tmp, $mime);
    if (!$handle) { echo json_encode(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo subir la foto']); exit; }

    $ok = WhatsAppService::perfilActualizar(['profile_picture_handle' => $handle], $phoneId);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo asignar la foto']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción no reconocida']);
