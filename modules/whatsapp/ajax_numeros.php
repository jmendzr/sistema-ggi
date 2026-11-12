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
    $acc = [];
    foreach ($pdo->query("SELECT numero_id, COUNT(*) n FROM wa_numero_usuarios GROUP BY numero_id") as $r) {
        $acc[(int) $r['numero_id']] = (int) $r['n'];
    }
    foreach ($filas as &$f) {
        $f['conversaciones'] = $uso[$f['phone_number_id']] ?? 0;
        $f['con_acceso']     = $acc[(int) $f['id']] ?? 0;   // 0 = la ven todos
    }
    unset($f);

    salir(['ok' => true, 'items' => $filas, 'principal' => META_WA_PHONE_NUMBER_ID]);
}

// ══ Quién ve cada bandeja (LECTURA — se pide por GET, debe ir antes del guard POST) ══
if ($accion === 'accesos') {
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) salir(['ok' => false, 'msg' => 'ID inválido']);

    $u = $pdo->query(
        "SELECT id, TRIM(CONCAT(nombre,' ',apellido)) nombre, rol
           FROM usuarios
          WHERE activo = 1 AND rol IN ('ejecutivo_ventas','gerente_operaciones','gerente_general')
          ORDER BY rol = 'ejecutivo_ventas' DESC, nombre"
    )->fetchAll(PDO::FETCH_ASSOC);

    $q = $pdo->prepare("SELECT usuario_id FROM wa_numero_usuarios WHERE numero_id = ?");
    $q->execute([$id]);

    salir(['ok' => true, 'usuarios' => $u,
           'con_acceso' => array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN) ?: [])]);
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

if ($accion === 'guardar_accesos') {
    $id   = (int) ($_POST['id'] ?? 0);
    $ids  = array_map('intval', (array) ($_POST['usuarios'] ?? []));
    if (!$id) salir(['ok' => false, 'msg' => 'ID inválido']);

    // Lista vacía = la bandeja la ve todo el mundo. Es lo que había antes de
    // existir los permisos, así que no restringe por accidente.
    $pdo->prepare("DELETE FROM wa_numero_usuarios WHERE numero_id = ?")->execute([$id]);

    if ($ids) {
        $ins = $pdo->prepare("INSERT IGNORE INTO wa_numero_usuarios (numero_id, usuario_id) VALUES (?, ?)");
        foreach ($ids as $u) if ($u > 0) $ins->execute([$id, $u]);
    }

    salir(['ok' => true, 'restringido' => (bool) $ids]);
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
