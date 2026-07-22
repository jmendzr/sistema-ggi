<?php
/**
 * ajax_numeros.php — Registro de números de WhatsApp del sistema.
 *
 * El sistema nació atado a un solo número (META_WA_PHONE_NUMBER_ID en el .env),
 * pero la empresa puede tener varios repartidos en más de una cuenta de
 * WhatsApp Business. Esta tabla permite registrarlos todos y saber cuáles
 * están realmente operativos.
 *
 * Nada se guarda sin verificarlo antes contra Meta: un identificador mal
 * copiado quedaría en la lista aparentando funcionar.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');
header('Content-Type: application/json; charset=utf-8');

$pdo    = Database::get();
$accion = $_REQUEST['accion'] ?? 'lista';

function salir(array $d): void { echo json_encode($d); exit; }

/** Consulta un número en Meta. Devuelve null si no existe o no hay acceso. */
function gg_num_consultar(string $phoneId): ?array
{
    $ch = curl_init('https://graph.facebook.com/' . META_WA_VERSION . '/' . rawurlencode($phoneId)
        . '?fields=id,display_phone_number,verified_name,quality_rating,status,platform_type');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . META_WA_TOKEN],
    ]);
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $d = json_decode((string) $b, true) ?? [];

    return ($c === 200 && !empty($d['id'])) ? $d : null;
}

// ══ Listado, refrescando el estado real de cada uno ══════════════════════════
if ($accion === 'lista') {
    $filas = $pdo->query("SELECT * FROM wa_numeros ORDER BY activo DESC, id")->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare("UPDATE wa_numeros
                             SET telefono = ?, verified_name = ?, calidad = ?, estado = ?, verificado_at = NOW()
                           WHERE id = ?");
    foreach ($filas as &$f) {
        $m = gg_num_consultar($f['phone_number_id']);
        if ($m) {
            $f['telefono']      = $m['display_phone_number'] ?? null;
            $f['verified_name'] = $m['verified_name'] ?? null;
            $f['calidad']       = $m['quality_rating'] ?? null;
            $f['estado']        = $m['status'] ?? null;
            $upd->execute([$f['telefono'], $f['verified_name'], $f['calidad'], $f['estado'], $f['id']]);
        } else {
            // Sin acceso: puede estar en una cuenta que el token no administra
            $f['estado'] = 'SIN_ACCESO';
        }
    }
    unset($f);

    // Conversaciones por número, para ver cuál se usa de verdad
    $uso = [];
    foreach ($pdo->query("SELECT phone_number_id, COUNT(*) n FROM wa_conversaciones GROUP BY phone_number_id") as $r) {
        $uso[$r['phone_number_id']] = (int) $r['n'];
    }
    foreach ($filas as &$f) $f['conversaciones'] = $uso[$f['phone_number_id']] ?? 0;
    unset($f);

    salir(['ok' => true, 'items' => $filas, 'principal' => META_WA_PHONE_NUMBER_ID]);
}

// ── Escritura ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false, 'msg' => 'Método no permitido']);
csrf_verify();

// ══ Agregar un número ════════════════════════════════════════════════════════
if ($accion === 'agregar') {
    $phoneId  = preg_replace('/\D/', '', $_POST['phone_number_id'] ?? '');
    $wabaId   = preg_replace('/\D/', '', $_POST['waba_id'] ?? '');
    $etiqueta = trim($_POST['etiqueta'] ?? '');

    if ($phoneId === '') salir(['ok' => false, 'msg' => 'Falta el identificador del número']);

    $ya = $pdo->prepare("SELECT COUNT(*) FROM wa_numeros WHERE phone_number_id = ?");
    $ya->execute([$phoneId]);
    if ($ya->fetchColumn()) salir(['ok' => false, 'msg' => 'Ese número ya está registrado']);

    // Verificar contra Meta ANTES de guardar
    $m = gg_num_consultar($phoneId);
    if (!$m) {
        salir(['ok' => false, 'msg' =>
            'Meta no reconoce ese identificador, o el token del sistema no tiene acceso a su cuenta. '
          . 'Verifica que sea el "Identificador del número de teléfono" y que la cuenta esté asignada '
          . 'al usuario del sistema en Configuración del negocio.']);
    }

    $pdo->prepare(
        "INSERT INTO wa_numeros
            (phone_number_id, waba_id, etiqueta, telefono, verified_name, calidad, estado, verificado_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute([
        $phoneId, $wabaId ?: null, $etiqueta ?: ($m['verified_name'] ?? null),
        $m['display_phone_number'] ?? null, $m['verified_name'] ?? null,
        $m['quality_rating'] ?? null, $m['status'] ?? null,
    ]);

    salir(['ok' => true, 'numero' => $m['display_phone_number'] ?? $phoneId,
           'estado' => $m['status'] ?? '']);
}

// ══ Activar / desactivar ═════════════════════════════════════════════════════
if ($accion === 'toggle') {
    $id     = (int) ($_POST['id'] ?? 0);
    $activo = !empty($_POST['activo']) ? 1 : 0;
    if (!$id) salir(['ok' => false, 'msg' => 'ID inválido']);

    $pdo->prepare("UPDATE wa_numeros SET activo = ? WHERE id = ?")->execute([$activo, $id]);
    salir(['ok' => true]);
}

// ══ Quitar del registro (no toca Meta) ═══════════════════════════════════════
if ($accion === 'eliminar') {
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) salir(['ok' => false, 'msg' => 'ID inválido']);

    $q = $pdo->prepare("SELECT phone_number_id FROM wa_numeros WHERE id = ?");
    $q->execute([$id]);
    $pid = $q->fetchColumn();

    if ($pid === META_WA_PHONE_NUMBER_ID) {
        salir(['ok' => false, 'msg' => 'Es el número principal del sistema: no se puede quitar.']);
    }

    // Las conversaciones se conservan: borrar el número no debe borrar historial
    $pdo->prepare("DELETE FROM wa_numeros WHERE id = ?")->execute([$id]);
    salir(['ok' => true]);
}

salir(['ok' => false, 'msg' => 'Acción desconocida']);
