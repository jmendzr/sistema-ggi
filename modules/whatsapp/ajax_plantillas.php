<?php
/**
 * ajax_plantillas.php — Mantenimiento de plantillas de WhatsApp contra Meta.
 *
 * Acciones: sincronizar, crear, eliminar, vincular (mapeo evento↔plantilla).
 * Solo gerencia: crear plantillas afecta la cuenta de WhatsApp de la empresa
 * y eliminarlas rompe notificaciones en producción.
 */
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/WhatsAppService.php';
require_once __DIR__ . '/../../core/WaPlantillas.php';
session_init();
require_login();
require_role('gerente_general', 'gerente_operaciones');
header('Content-Type: application/json; charset=utf-8');

$pdo    = Database::get();
$accion = $_REQUEST['accion'] ?? '';

function salir(array $d): void { echo json_encode($d); exit; }

// ══ Sincronizar con Meta ═════════════════════════════════════════════════════
if ($accion === 'sincronizar') {
    $meta = WhatsAppService::listarPlantillas();
    if ($meta === null) {
        salir(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'No se pudo consultar Meta']);
    }

    // Guardar estado y cuerpo para que el diagnóstico funcione aunque después
    // no haya conexión con Meta
    $upd = $pdo->prepare("UPDATE whatsapp_templates SET estado_meta = ?, body_text = ?
                           WHERE template_name = ? AND language_code = ?");
    $n = 0;
    foreach ($meta as $t) {
        $body = '';
        foreach ($t['components'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'BODY') $body = $c['text'] ?? '';
        }
        $upd->execute([$t['status'] ?? null, $body, $t['name'], $t['language']]);
        $n += $upd->rowCount();
    }

    salir(['ok' => true, 'actualizadas' => $n, 'total_meta' => count($meta)]
        + WaPlantillas::diagnostico($pdo, $meta));
}

// ══ Diagnóstico sin llamar a Meta ════════════════════════════════════════════
if ($accion === 'estado') {
    salir(['ok' => true] + WaPlantillas::diagnostico($pdo, null));
}

// ── A partir de aquí, todo modifica algo ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') salir(['ok' => false, 'msg' => 'Método no permitido']);
csrf_verify();

// ══ Crear plantilla en Meta ══════════════════════════════════════════════════
if ($accion === 'crear') {
    $nombre    = strtolower(trim($_POST['nombre'] ?? ''));
    $categoria = strtoupper(trim($_POST['categoria'] ?? 'UTILITY'));
    $idioma    = trim($_POST['idioma'] ?? 'es');
    $cuerpo    = trim($_POST['cuerpo'] ?? '');
    $ejemplos  = $_POST['ejemplos'] ?? [];
    $pie       = trim($_POST['pie'] ?? '');
    $evento    = trim($_POST['event_code'] ?? '');
    $claves    = $_POST['claves'] ?? [];

    if ($e = WaPlantillas::validarNombre($nombre)) salir(['ok' => false, 'msg' => $e]);
    if ($e = WaPlantillas::validarCuerpo($cuerpo)) salir(['ok' => false, 'msg' => $e]);
    if (!in_array($categoria, ['UTILITY', 'MARKETING', 'AUTHENTICATION'], true)) {
        salir(['ok' => false, 'msg' => 'Categoría inválida']);
    }

    // Meta EXIGE un ejemplo por variable: sin ellos rechaza la creación
    $nParams = WaPlantillas::contarParams($cuerpo);
    $ej      = [];
    for ($i = 0; $i < $nParams; $i++) {
        $v = trim((string) ($ejemplos[$i] ?? ''));
        if ($v === '') salir(['ok' => false, 'msg' => 'Falta el ejemplo de {{' . ($i + 1) . '}}']);
        $ej[] = $v;
    }

    $comp = [['type' => 'BODY', 'text' => $cuerpo]];
    if ($ej) $comp[0]['example'] = ['body_text' => [$ej]];
    if ($pie !== '') $comp[] = ['type' => 'FOOTER', 'text' => mb_substr($pie, 0, 60)];

    $res = WhatsAppService::crearPlantilla($nombre, $categoria, $idioma, $comp);
    if (!$res) salir(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'Meta rechazó la creación']);

    // Vincular al evento si se indicó, para que empiece a usarse al aprobarse
    if ($evento !== '') {
        $claves = array_values(array_filter(array_map('trim', (array) $claves), fn($v) => $v !== ''));
        $ya = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_templates WHERE event_code = ?");
        $ya->execute([$evento]);

        if ($ya->fetchColumn()) {
            $pdo->prepare("UPDATE whatsapp_templates
                              SET template_name = ?, language_code = ?, param_keys = ?,
                                  body_text = ?, estado_meta = ?
                            WHERE event_code = ?")
                ->execute([$nombre, $idioma, json_encode($claves), $cuerpo, $res['status'] ?? 'PENDING', $evento]);
        } else {
            $pdo->prepare("INSERT INTO whatsapp_templates
                             (event_code, template_name, language_code, param_keys, body_text, estado_meta, activo)
                           VALUES (?, ?, ?, ?, ?, ?, 0)")
                ->execute([$evento, $nombre, $idioma, json_encode($claves), $cuerpo, $res['status'] ?? 'PENDING']);
        }
    }

    salir(['ok' => true, 'estado' => $res['status'] ?? 'PENDING', 'id' => $res['id'] ?? null]);
}

// ══ Eliminar plantilla de Meta ═══════════════════════════════════════════════
if ($accion === 'eliminar') {
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre === '') salir(['ok' => false, 'msg' => 'Falta el nombre']);

    // Si algún evento ACTIVO la usa, borrarla deja esa notificación muerta
    $q = $pdo->prepare("SELECT event_code FROM whatsapp_templates
                         WHERE template_name = ? AND activo = 1");
    $q->execute([$nombre]);
    $usos = $q->fetchAll(PDO::FETCH_COLUMN);
    if ($usos && empty($_POST['forzar'])) {
        salir(['ok' => false, 'confirmar' => true,
               'msg' => 'La usan eventos activos: ' . implode(', ', $usos)
                      . '. Si la eliminas, esas notificaciones dejarán de enviarse.']);
    }

    if (!WhatsAppService::eliminarPlantilla($nombre)) {
        salir(['ok' => false, 'msg' => WhatsAppService::lastError() ?: 'Meta no pudo eliminarla']);
    }

    $pdo->prepare("UPDATE whatsapp_templates SET estado_meta = NULL WHERE template_name = ?")
        ->execute([$nombre]);

    salir(['ok' => true]);
}

// ══ Vincular / editar el mapeo evento ↔ plantilla ════════════════════════════
if ($accion === 'vincular') {
    $evento = trim($_POST['event_code'] ?? '');
    if ($evento === '') salir(['ok' => false, 'msg' => 'Falta el evento']);

    $nombre = strtolower(trim($_POST['template_name'] ?? ''));
    $idioma = trim($_POST['idioma'] ?? 'es');
    $claves = array_values(array_filter(array_map('trim', (array) ($_POST['claves'] ?? [])), fn($v) => $v !== ''));
    $activo = !empty($_POST['activo']) ? 1 : 0;
    $boton  = trim($_POST['url_button_key'] ?? '');

    if ($e = WaPlantillas::validarNombre($nombre)) salir(['ok' => false, 'msg' => $e]);

    // Activar una plantilla que no está aprobada garantiza fallos de envío
    if ($activo) {
        $q = $pdo->prepare("SELECT estado_meta FROM whatsapp_templates WHERE template_name = ? LIMIT 1");
        $q->execute([$nombre]);
        $est = $q->fetchColumn();
        if ($est && $est !== 'APPROVED' && empty($_POST['forzar'])) {
            salir(['ok' => false, 'confirmar' => true,
                   'msg' => 'Esa plantilla está en estado ' . $est . '. Si la activas, los envíos van a fallar.']);
        }
    }

    $pdo->prepare("UPDATE whatsapp_templates
                      SET template_name = ?, language_code = ?, param_keys = ?,
                          activo = ?, url_button_key = ?
                    WHERE event_code = ?")
        ->execute([$nombre, $idioma, json_encode($claves), $activo, $boton ?: null, $evento]);

    salir(['ok' => true]);
}

salir(['ok' => false, 'msg' => 'Acción desconocida']);
