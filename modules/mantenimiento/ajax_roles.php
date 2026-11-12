<?php
// ============================================================
//  GGI SISTEMA — AJAX: Roles y Permisos
//  Requiere: gerente_general | JSON POST | CSRF via header
// ============================================================
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/Database.php';
session_init();
require_login();
session_write_close(); // Liberar lock: solo se necesitaba para autenticación

header('Content-Type: application/json; charset=utf-8');

// Solo gerente_general puede modificar permisos
if (!has_role('gerente_general')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Acceso denegado.']);
    exit;
}

// Leer body JSON
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['ok' => false, 'msg' => 'Payload inválido.']);
    exit;
}

// Verificar CSRF (header o body)
$token_header  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$token_session = $_SESSION['csrf_token']       ?? '';
if (empty($token_header) || !hash_equals($token_session, $token_header)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido.']);
    exit;
}

$accion = $body['accion'] ?? '';
$pdo    = Database::get();

// ── Módulos válidos (definición maestra) ────────────────────────────────────
$MODULOS_VALIDOS = [
    'clientes','certificados','cotizaciones','envios','auditorias','documentos',
    'pagos','facturas_emitir','bonos','metas','reportes','comunicaciones','notificaciones',
    'whatsapp','mantenimiento','clientes_ver',
];

// ── Roles editables: dinámicos desde BD (excluye gerente_general) ───────────
function get_roles_editables(\PDO $pdo): array {
    try {
        return $pdo
            ->query("SELECT nombre FROM roles WHERE nombre != 'gerente_general'")
            ->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return ['gerente_operaciones','ejecutivo_ventas','gestor_logistico','delivery'];
    }
}

// ============================================================
//  Acción: toggle permiso
// ============================================================
if ($accion === 'toggle') {
    $rol    = $body['rol']    ?? '';
    $modulo = $body['modulo'] ?? '';
    $activo = (int)($body['activo'] ?? 0);

    $roles_editables = get_roles_editables($pdo);

    if (!in_array($rol, $roles_editables, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Rol no editable.']);
        exit;
    }
    if (!in_array($modulo, $MODULOS_VALIDOS, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Módulo no reconocido.']);
        exit;
    }
    $activo = $activo ? 1 : 0;

    try {
        $pdo->prepare("
            INSERT INTO rol_permisos (rol, modulo, activo)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE activo = VALUES(activo)
        ")->execute([$rol, $modulo, $activo]);

        log_actividad('roles', 'toggle', "Permiso '{$modulo}' " . ($activo ? 'activado' : 'desactivado') . " para rol '{$rol}'");

        echo json_encode(['ok' => true, 'activo' => $activo]);
    } catch (\Throwable $e) {
        error_log('ajax_roles toggle: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Error de base de datos.']);
    }
    exit;
}

// ============================================================
//  Acción: editar metadata de un rol existente
// ============================================================
if ($accion === 'editar_rol') {
    $nombre      = $body['nombre']           ?? '';
    $label       = trim($body['label']       ?? '');
    $descripcion = trim($body['descripcion'] ?? '');
    $color       = $body['color']            ?? '#1A3A8F';

    $roles_editables = get_roles_editables($pdo);
    if (!in_array($nombre, $roles_editables, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Rol no editable.']);
        exit;
    }
    if (empty($label)) {
        echo json_encode(['ok' => false, 'msg' => 'La etiqueta es obligatoria.']);
        exit;
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#1A3A8F';

    $label       = substr(strip_tags($label), 0, 100);
    $descripcion = substr(strip_tags($descripcion), 0, 500);

    try {
        $pdo->prepare("
            UPDATE roles SET label = ?, descripcion = ?, color = ?
            WHERE nombre = ?
        ")->execute([$label, $descripcion, $color, $nombre]);

        log_actividad('roles', 'editar', "Rol '{$nombre}' actualizado: label='{$label}'");
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('ajax_roles editar_rol: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Error al guardar.']);
    }
    exit;
}

// ============================================================
//  Acción: crear nuevo rol
// ============================================================
if ($accion === 'crear_rol') {
    $nombre      = trim($body['nombre']           ?? '');
    $label       = trim($body['label']            ?? '');
    $descripcion = trim($body['descripcion']      ?? '');
    $color       = $body['color']                 ?? '#1A3A8F';

    // Validar nombre (slug): solo a-z, 0-9, guión bajo; 3-50 chars; no reservado
    if (!preg_match('/^[a-z][a-z0-9_]{2,49}$/', $nombre)) {
        echo json_encode(['ok' => false, 'msg' => 'El identificador debe tener 3-50 caracteres (solo letras minúsculas, números y guión bajo) y comenzar con letra.']);
        exit;
    }
    if ($nombre === 'gerente_general') {
        echo json_encode(['ok' => false, 'msg' => 'Nombre reservado.']);
        exit;
    }
    if (empty($label)) {
        echo json_encode(['ok' => false, 'msg' => 'La etiqueta es obligatoria.']);
        exit;
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#3B82F6';

    $label       = substr(strip_tags($label), 0, 100);
    $descripcion = substr(strip_tags($descripcion), 0, 500);

    // Verificar que no exista
    $existe = $pdo->prepare("SELECT 1 FROM roles WHERE nombre = ?");
    $existe->execute([$nombre]);
    if ($existe->fetch()) {
        echo json_encode(['ok' => false, 'msg' => 'Ya existe un rol con ese identificador.']);
        exit;
    }

    // Calcular orden (siguiente al máximo)
    $max_orden = (int)$pdo->query("SELECT COALESCE(MAX(orden),1) FROM roles")->fetchColumn();

    try {
        $pdo->prepare("
            INSERT INTO roles (nombre, label, descripcion, color, orden)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$nombre, $label, $descripcion, $color, $max_orden + 1]);

        // Insertar entradas en rol_permisos (todos desactivados por defecto)
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO rol_permisos (rol, modulo, activo) VALUES (?, ?, 0)
        ");
        foreach ($MODULOS_VALIDOS as $mod) {
            $stmt->execute([$nombre, $mod]);
        }

        log_actividad('roles', 'crear', "Rol '{$nombre}' creado con label='{$label}'");
        echo json_encode(['ok' => true, 'nombre' => $nombre]);
    } catch (\Throwable $e) {
        error_log('ajax_roles crear_rol: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Error al crear el rol.']);
    }
    exit;
}

// ============================================================
//  Acción: eliminar rol
// ============================================================
if ($accion === 'eliminar_rol') {
    $nombre = $body['nombre'] ?? '';

    // No se puede eliminar gerente_general
    if ($nombre === 'gerente_general') {
        echo json_encode(['ok' => false, 'msg' => 'No se puede eliminar el rol de administrador.']);
        exit;
    }

    $roles_editables = get_roles_editables($pdo);
    if (!in_array($nombre, $roles_editables, true)) {
        echo json_encode(['ok' => false, 'msg' => 'Rol no encontrado.']);
        exit;
    }

    // Verificar que no haya usuarios con este rol
    $usuarios_con_rol = (int)$pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = ? AND activo = 1")
        ->execute([$nombre]) ? $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = ? AND activo = 1")->execute([$nombre]) : 0;

    // Re-hacer la consulta correctamente
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = ?");
    $stmt->execute([$nombre]);
    $count_usuarios = (int)$stmt->fetchColumn();

    if ($count_usuarios > 0) {
        echo json_encode(['ok' => false, 'msg' => "No se puede eliminar: hay {$count_usuarios} usuario(s) con este rol. Reasígnalos primero."]);
        exit;
    }

    try {
        $pdo->prepare("DELETE FROM rol_permisos WHERE rol = ?")->execute([$nombre]);
        $pdo->prepare("DELETE FROM roles WHERE nombre = ?")->execute([$nombre]);

        log_actividad('roles', 'eliminar', "Rol '{$nombre}' eliminado");
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        error_log('ajax_roles eliminar_rol: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Error al eliminar el rol.']);
    }
    exit;
}

// Acción no reconocida
echo json_encode(['ok' => false, 'msg' => 'Acción no válida.']);
